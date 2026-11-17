<?php

namespace Synth\Core;

use Doctrine\DBAL\Connection;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__).'/EntityManagerFactory.php';
require_once dirname(__DIR__).'/SharedApiKeyStore.php';

// SYNTH's one entry to provider API keys. There is no local key table: every key lives in the shared `dwemer` database's
// api_keys table, the store the CHIM 4 server uses (SharedApiKeyStore), and connectors reference a key by its key_id
// (core_*_connector.api_key_id, a uuid). The class keeps its long-standing name and read methods so callers stay simple.
// A key's handle ("id") is its key_id string; a row is ['id', 'label', 'api_key', 'provider', 'origin', 'revision'].
// Without a usable store (CHIM_SHARED_DB_* unset, the database unreachable or not migrated) there are no keys: reads return
// [] / false, writes refuse, nothing is logged and PHP raises no warning. unavailableReason() says why, for Setup messages.
// Keys are credentials: nothing here logs one, and callers decide what may reach a page.
class ApiBadgeRepository
{
    // Listing orders offered to the connector pages; the SQL never takes a caller's text.
    private const ORDERS = ['id', 'label', 'lower_label'];
    // The SYNTH tables that reference a key; a key any of them uses is never deleted.
    private const REFERENCING = ['core_llm_connector', 'core_tts_connector', 'core_stt_connector', 'core_itt_connector'];

    private static ?SharedApiKeyStore $store = null;
    private static bool $storeLoaded = false;

    private ?Connection $connection = null;
    private string $error = '';

    public function __construct(private $db) {}

    /** Test and tool seam: the store used from now on (null = none). false re-reads the environment. */
    public static function useSharedStore(SharedApiKeyStore|null|false $store): void
    {
        self::$store = $store === false ? null : $store;
        self::$storeLoaded = $store !== false;
    }

    /** Why keys are unavailable ('not_configured', 'unreachable' or 'not_migrated'), or null when the store works. */
    public static function unavailableReason(): ?string
    {
        $store = self::store();
        return $store === null ? 'not_configured' : $store->unavailableReason();
    }

    /** One fixed sentence for a Setup or page message, or '' when keys are available. Never names a value. */
    public static function unavailableMessage(): string
    {
        return match (self::unavailableReason()) {
            null => '',
            'not_configured' => 'API keys are stored in the shared dwemer database, which is not configured. Set CHIM_SHARED_DB_HOST, CHIM_SHARED_DB_PORT, CHIM_SHARED_DB_NAME and CHIM_SHARED_DB_USER (and CHIM_SHARED_DB_PASSWORD) for SYNTH, then reload.',
            'not_migrated' => 'The shared dwemer database has no API key table yet. Run the shared migrations (docs/SCHEMA-MIGRATIONS.md, Shared API keys), then reload.',
            default => 'The shared dwemer database cannot be reached. Check that PostgreSQL is running and the CHIM_SHARED_DB_* settings are correct, then reload.',
        };
    }

    public static function available(): bool
    {
        return self::unavailableReason() === null;
    }

    /** The key with this exact id, or [] when there is none (or no store). */
    public function byId(string $id): array
    {
        $id = SharedApiKeyStore::idValid($id) ? $id : null;
        if ($id === null || ($store = $this->usable()) === null) return [];
        $row = $this->guard(fn() => $store->find($id), null);
        return is_array($row) && $row['status'] === 'active' ? $this->view($row) : [];
    }

    /** The oldest active key whose label matches in any letter case, or []. */
    public function byLabel(string $label): array
    {
        return $this->firstWhere(static fn(array $row) => strtolower($row['label']) === strtolower($label));
    }

    /** Every active key, oldest first. */
    public function all(): array
    {
        return array_map($this->view(...), $this->listing());
    }

    /** Id and label of every key, never the key. */
    public function labels(string $order): array
    {
        return array_map(static fn(array $row) => ['id' => $row['id'], 'label' => $row['label']], $this->sorted($this->listing(), $order));
    }

    /** Id, label and key of every key, for pages that only show whether a key is saved. */
    public function labelsWithKeys(string $order): array
    {
        return array_map(static fn(array $row) => ['id' => $row['id'], 'label' => $row['label'], 'api_key' => $row['secret']],
            $this->sorted($this->listing(), $order));
    }

    /** ['api_key' => ...] of the key with this id, or []. */
    public function keyById(string $id): array
    {
        $row = $this->byId($id);
        return $row === [] ? [] : ['api_key' => $row['api_key']];
    }

    /**
     * ['api_key' => ...] of the oldest active key whose lower-cased label is exactly $lowerLabel, or []. $lowestId is kept
     * for the callers that named it: the oldest key is always the provider's canonical one.
     */
    public function keyByLowerLabel(string $lowerLabel, bool $lowestId = false): array
    {
        $row = $this->firstWhere(static fn(array $row) => strtolower($row['label']) === $lowerLabel);
        return $row === [] ? [] : ['api_key' => $row['api_key']];
    }

    /** ['id' => ...] of the oldest active key whose lower-cased label is exactly $lowerLabel, or []. */
    public function idByLowerLabel(string $lowerLabel): array
    {
        $row = $this->firstWhere(static fn(array $row) => strtolower($row['label']) === $lowerLabel);
        return $row === [] ? [] : ['id' => $row['id']];
    }

    /**
     * Creates a key and returns its id, or '' on failure. Takes label, api_key and optionally provider and origin. The
     * provider defaults to the one the label names; any other label is a custom key (custom_openai) and needs an origin
     * (https, or http to loopback). A key cannot be empty.
     */
    public function create(array $data): string
    {
        $label = trim(strval($data['label'] ?? ''));
        $secret = strval($data['api_key'] ?? '');
        $provider = isset($data['provider']) ? strval($data['provider']) : (SharedApiKeyStore::providerForLabel($label) ?? 'custom_openai');
        $origin = $provider === 'custom_openai'
            ? SharedApiKeyStore::canonicalOrigin($data['origin'] ?? null)
            : (SharedApiKeyStore::ORIGINS[$provider] ?? null);
        if ($origin === null || ($store = $this->usable()) === null) {
            $this->error = $origin === null ? 'invalid_origin' : 'unavailable';
            return '';
        }
        $result = $this->guard(fn() => $store->create($provider, $label, $origin, $secret), null);
        if ($result === null) { $this->error = 'refused'; return ''; }
        if ($result['outcome'] !== 'created') { $this->error = $result['outcome']; return ''; }
        return strval($result['id']);
    }

    /** Creates a key from a label and a value; only success is reported, as the insert it replaced did. */
    public function add(string $label, string $apiKey): bool
    {
        return $this->create(['label' => $label, 'api_key' => $apiKey]) !== '';
    }

    /**
     * Sets the label and/or the key of an active key (a blank key is refused: only delete() removes one). A true result
     * means the key was changed.
     */
    public function update(string $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(['label', 'api_key']));
        $id = SharedApiKeyStore::idValid($id) ? $id : null;
        if ($id === null || $data === [] || ($store = $this->usable()) === null) return false;
        $label = array_key_exists('label', $data) ? trim(strval($data['label'])) : null;
        $secret = array_key_exists('api_key', $data) ? strval($data['api_key']) : null;
        if ($secret === '') return false;
        return $this->guard(fn() => $store->update($id, $label, $secret) === 'updated', false);
    }

    /** True when the key was deleted (secret wiped, tombstone kept). A key a SYNTH connector still references is refused. */
    public function delete(string $id): bool
    {
        return $this->deleteOutcome($id) === 'deleted';
    }

    /** 'deleted', 'in_use', 'missing', 'revoked' or 'unavailable'. */
    public function deleteOutcome(string $id): string
    {
        $id = SharedApiKeyStore::idValid($id) ? $id : null;
        if ($id === null) return 'missing';
        if (($store = $this->usable()) === null) return 'unavailable';
        $outcome = $this->guard(fn() => $store->tombstone($id, fn() => $this->referenced($id)), 'unavailable');
        $this->error = $outcome;
        return $outcome;
    }

    /**
     * Quickstart's write: the key goes to the oldest active key whose lower-cased label is $lowerLabel, or a new key named
     * $label when there is none. An unavailable store saves nothing.
     */
    public function saveKeyForLowerLabel(string $lowerLabel, string $label, string $apiKey): bool
    {
        if ($this->usable() === null) return false;
        $existing = $this->idByLowerLabel($lowerLabel);
        if (isset($existing['id'])) return $this->update($existing['id'], ['api_key' => $apiKey]);
        return $this->add($label, $apiKey);
    }

    /** True when any SYNTH connector references this key. */
    public function inUse(string $id): bool
    {
        $id = SharedApiKeyStore::idValid($id) ? $id : null;
        return $id !== null && $this->referenced($id);
    }

    /** The last refusal code of this instance, never a message from the database. */
    public function lastError(): string
    {
        return $this->error;
    }

    private static function store(): ?SharedApiKeyStore
    {
        if (!self::$storeLoaded) {
            self::$store = SharedApiKeyStore::fromEnvironment();
            self::$storeLoaded = true;
        }
        return self::$store;
    }

    private function usable(): ?SharedApiKeyStore
    {
        $store = self::store();
        return $store !== null && $store->ready() ? $store : null;
    }

    private function listing(): array
    {
        $store = $this->usable();
        return $store === null ? [] : $this->guard(fn() => $store->listActive(true), []);
    }

    private function firstWhere(callable $match): array
    {
        foreach ($this->listing() as $row) {
            if ($match($row)) return $this->view($row);
        }
        return [];
    }

    private function sorted(array $rows, string $order): array
    {
        if (!in_array($order, self::ORDERS, true)) throw new \InvalidArgumentException('Unknown API key listing order');
        if ($order === 'label') usort($rows, static fn($a, $b) => strcmp($a['label'], $b['label']));
        elseif ($order === 'lower_label') usort($rows, static fn($a, $b) => strcmp(strtolower($a['label']), strtolower($b['label'])));
        return $rows;
    }

    private function view(array $row): array
    {
        return ['id' => $row['id'], 'label' => $row['label'], 'api_key' => $row['secret'] ?? '', 'provider' => $row['provider'],
            'origin' => $row['origin'], 'revision' => $row['revision']];
    }

    // A store failure reads as "no such key" or "refused", with a fixed code and no database text.
    private function guard(callable $work, mixed $fallback): mixed
    {
        try {
            return $work();
        } catch (SharedApiKeyException) {
            $this->error = 'unavailable';
            return $fallback;
        }
    }

    // Whether a SYNTH connector references the key. Read on the legacy session while the shared row is locked; a failed read
    // counts as "in use" so nothing is deleted on a doubt.
    private function referenced(string $id): bool
    {
        try {
            foreach (self::REFERENCING as $table) {
                if ($this->connection()->executeQuery("SELECT 1 FROM {$table} WHERE api_key_id = ?::uuid LIMIT 1", [$id])->fetchOne() !== false) return true;
            }
            return false;
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return true;
        }
    }

    private function connection(): Connection
    {
        return $this->connection ??= EntityManagerFactory::forSharedSession($this->db)->getConnection();
    }
}
