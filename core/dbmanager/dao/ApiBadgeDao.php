<?php

// The one data-access point for API keys. A key is global (the shared dwemer store, the CHIM 4 server's api_keys table, shared
// with other Dwemer mods) or SYNTH-only (public.core_api_badge: id, label, api_key, UNIQUE label). The two stores are presented
// as one list; every key records which store it lives in through its ApiKeyRef, and nothing ever falls back from one store to
// the other. Without a usable shared store only SYNTH-only keys exist, and a global key reads as missing.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Connection;
use Synth\Core\Contracts\ApiKeyRef;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Core\Entities\System\ApiBadge;
use Synth\Core\SharedApiKeyException;
use Synth\Core\SharedApiKeyStore;

require_once dirname(__DIR__, 2).'/contracts/ApiKeyRef.php';
require_once dirname(__DIR__, 2).'/entities/system/ApiBadge.php';
require_once dirname(__DIR__).'/EntityManagerFactory.php';
require_once dirname(__DIR__).'/SharedApiKeyStore.php';
require_once __DIR__.'/ConnectorReferences.php';

/** Lists, reads, creates, edits and deletes API keys in either store. Keys are credentials: nothing here logs one. */
final class ApiBadgeDao
{
    private static ?SharedApiKeyStore $store = null;
    private static bool $storeLoaded = false;

    private ?Connection $connection = null;
    private ?ConnectorReferences $references = null;
    private string $error = '';

    /** Uses the legacy sql wrapper's session for the SYNTH-only table and for the connector reference scans. */
    public function __construct(private readonly object $db)
    {
    }

    /** Test and tool seam: the shared store used from now on (null = none). false re-reads the environment. */
    public static function useSharedStore(SharedApiKeyStore|null|false $store): void
    {
        self::$store = $store === false ? null : $store;
        self::$storeLoaded = $store !== false;
    }

    /** Why global keys are unavailable ('not_configured', 'unreachable' or 'not_migrated'), or null when the shared store works. */
    public static function unavailableReason(): ?string
    {
        $store = self::store();
        return $store === null ? 'not_configured' : $store->unavailableReason();
    }

    /** One fixed sentence for a Setup or page message, or '' when global keys are available. Never names a value. */
    public static function unavailableMessage(): string
    {
        return match (self::unavailableReason()) {
            null => '',
            'not_configured' => 'Global API keys come from the shared dwemer database, which is not configured, so only SYNTH-only keys work. Set CHIM_SHARED_DB_HOST, CHIM_SHARED_DB_PORT, CHIM_SHARED_DB_NAME and CHIM_SHARED_DB_USER (and CHIM_SHARED_DB_PASSWORD) for SYNTH, then reload.',
            'not_migrated' => 'The shared dwemer database has no API key table yet, so only SYNTH-only keys work. Run the shared migrations (docs/SCHEMA-MIGRATIONS.md, Shared API keys), then reload.',
            default => 'The shared dwemer database cannot be reached, so only SYNTH-only keys work. Check that PostgreSQL is running and the CHIM_SHARED_DB_* settings are correct, then reload.',
        };
    }

    /** The shared store's readiness: ['available' => bool, 'reason' => ?string, 'message' => string]. */
    public function storeStatus(): array
    {
        return ['available' => self::unavailableReason() === null, 'reason' => self::unavailableReason(), 'message' => self::unavailableMessage()];
    }

    /**
     * Every key as ApiBadge objects: global keys first (oldest first), then SYNTH-only keys (by id).
     *
     * @return list<ApiBadge>
     */
    public function listAll(): array
    {
        return array_map($this->entity(...), $this->rows(null, false));
    }

    /**
     * Every key as raw rows with the secret (server-side use only): id (a reference string), store, label, provider, origin, revision, secret.
     *
     * @return list<array<string,string>>
     */
    public function listWithSecrets(): array
    {
        return $this->rows(null, false);
    }

    /** The key $ref names, or null when there is none (a revoked or unknown global key, or no shared store, reads as none). */
    public function find(ApiKeyRef $ref): ?ApiBadge
    {
        $row = $this->row($ref);
        return $row === null ? null : $this->entity($row);
    }

    /**
     * The first key whose label matches in any letter case. $store 'shared' or 'local' limits the search; null looks in the
     * shared store first, then in the SYNTH-only one. The oldest match wins in the shared store, the lowest id locally.
     */
    public function findByLabel(string $label, ?string $store = null): ?ApiBadge
    {
        foreach ($this->rows($store, false) as $row) {
            if (strtolower($row['label']) === strtolower($label)) return $this->entity($row);
        }
        return null;
    }

    /** The global key of a provider label: the oldest shared key labelled so (any case). Never a SYNTH-only key. */
    public function defaultForProvider(string $label): ?ApiBadge
    {
        // Only a key of the provider the label names: a custom key that merely carries the label is never the default.
        $provider = SharedApiKeyStore::providerForLabel($label);
        if ($provider === null) return null;
        foreach ($this->rows(ApiKeyRef::SHARED, false) as $row) {
            if (strtolower($row['label']) === strtolower($label) && $row['provider'] === $provider) return $this->entity($row);
        }
        return null;
    }

    /** The saved secret of $ref, or '' (none saved, unknown key, revoked key, or no shared store). Server-side use only. */
    public function secret(ApiKeyRef $ref): string
    {
        return $this->row($ref)['secret'] ?? '';
    }

    /**
     * Creates a key in $store ('shared' or 'local') and returns its reference, or null. $options: provider and origin for a
     * shared key (the provider defaults to the one the label names, else a custom key that needs an origin). A shared key
     * cannot be empty; a SYNTH-only key may be, and its label must be unique (exact case).
     */
    public function create(string $store, string $label, string $secret, array $options = []): ?ApiKeyRef
    {
        $label = trim($label);
        $this->error = '';
        if ($store === ApiKeyRef::LOCAL) {
            return $this->createLocal($label, $secret);
        }
        $shared = $this->usable();
        if ($shared === null) { $this->error = 'unavailable'; return null; }
        $provider = isset($options['provider']) ? strval($options['provider']) : (SharedApiKeyStore::providerForLabel($label) ?? 'custom_openai');
        $origin = $provider === 'custom_openai' ? SharedApiKeyStore::canonicalOrigin($options['origin'] ?? null) : (SharedApiKeyStore::ORIGINS[$provider] ?? null);
        if ($origin === null) { $this->error = 'invalid_origin'; return null; }
        $result = $this->guard(fn() => $shared->create($provider, $label, $origin, $secret), null);
        if ($result === null) { $this->error = $this->error ?: 'refused'; return null; }
        if ($result['outcome'] !== 'created') { $this->error = $result['outcome']; return null; }
        return ApiKeyRef::shared((string) $result['id']);
    }

    /**
     * Sets the label and/or the secret of $ref. A global key is edited in place for every mod (revision + 1) and its secret
     * cannot be emptied; a SYNTH-only key may be emptied (that is its explicit Clear). With $expectedRevision a global key that
     * changed elsewhere is refused and lastError() is 'conflict'. True when the key was changed.
     */
    public function update(ApiKeyRef $ref, ?string $label, ?string $secret, ?int $expectedRevision = null): bool
    {
        $this->error = '';
        $label = $label === null ? null : trim($label);
        if ($ref->isShared()) {
            $shared = $this->usable();
            if ($shared === null || $secret === '') return false;
            $outcome = $this->guard(fn() => $shared->update($ref->id(), $label, $secret, $expectedRevision), 'unavailable');
            $this->error = $outcome === 'updated' ? '' : $outcome;
            return $outcome === 'updated';
        }
        if ($label !== null && $this->labelTaken($label, (int) $ref->id())) { $this->error = 'duplicate_label'; return false; }
        $sets = []; $params = [];
        if ($label !== null) { $sets[] = 'label = ?'; $params[] = $label; }
        if ($secret !== null) { $sets[] = 'api_key = ?'; $params[] = $secret; }
        if ($sets === []) return false;
        $params[] = (int) $ref->id();
        return $this->run('UPDATE core_api_badge SET '.implode(', ', $sets).' WHERE id = ?', $params);
    }

    /**
     * Deletes $ref: 'deleted', 'in_use' (a SYNTH connector still uses it), 'conflict' (a global key changed since $expectedRevision), 'missing', 'revoked' (a global key already deleted) or
     * 'unavailable' (no shared store). A global delete wipes the secret and keeps a tombstone, for every mod; a SYNTH-only one
     * removes the row.
     */
    public function delete(ApiKeyRef $ref, ?int $expectedRevision = null): string
    {
        if (!$ref->isShared()) {
            if ($this->row($ref) === null) return $this->error = 'missing';
            if ($this->references()->isUsed($ref)) return $this->error = 'in_use';
            return $this->error = ($this->run('DELETE FROM core_api_badge WHERE id = ?', [(int) $ref->id()]) ? 'deleted' : 'in_use');
        }
        $shared = $this->usable();
        if ($shared === null) return $this->error = 'unavailable';
        return $this->error = $this->guard(fn() => $shared->tombstone($ref->id(), fn() => $this->references()->isUsed($ref), $expectedRevision), 'unavailable');
    }

    /**
     * The secret of $ref for a request to $endpoint (a URL), or '' when it must not be sent there. A global key is pinned to one
     * origin (ChimServer's resolveSecret rule): the endpoint's origin must equal it, else this logs one fixed message and returns
     * ''. An empty $endpoint means the provider's own API ($providerLabel's fixed origin). Azure, Replicate and DeepL keys have a
     * placeholder origin (their endpoint varies) and are not pinned. A SYNTH-only key has no pinned origin and is returned as is.
     */
    public function secretForEndpoint(ApiKeyRef $ref, string $endpoint, string $providerLabel = ''): string
    {
        $row = $this->row($ref);
        if ($row === null) return '';
        if (!$ref->isShared() || in_array($row['provider'], ['azure', 'replicate', 'deepl'], true)) return $row['secret'];
        $target = trim($endpoint) === '' ? (SharedApiKeyStore::ORIGINS[SharedApiKeyStore::providerForLabel($providerLabel) ?? $row['provider']] ?? null)
            : SharedApiKeyStore::originOfUrl($endpoint);
        if ($target === null || $target !== $row['origin']) {
            self::note('API key not sent: the endpoint origin does not match the key\'s origin');
            return '';
        }
        return $row['secret'];
    }

    /**
     * Runs $write (one autocommitted SYNTH statement that stores a connector's reference to $ref) while the shared key is share-locked
     * (ChimServer's bind protocol), so a concurrent delete either waits and then sees the reference or wins and nothing is written.
     * A SYNTH-only key needs no lock. Returns ['outcome' => 'locked'|'missing'|'revoked'|'unavailable', 'result' => $write's result].
     */
    public function bindUnderLock(ApiKeyRef $ref, callable $write): array
    {
        if (!$ref->isShared()) {
            return $this->row($ref) === null ? ['outcome' => 'missing', 'result' => null] : ['outcome' => 'locked', 'result' => $write()];
        }
        $shared = $this->usable();
        if ($shared === null) return ['outcome' => 'unavailable', 'result' => null];
        return $this->guard(fn() => $shared->withShareLock($ref->id(), $write), ['outcome' => 'unavailable', 'result' => null]);
    }

    /** True when DwemerDistro's shared-key registry lists SYNTH, so CHIM checks SYNTH connectors before deleting a shared key. */
    public static function distroRegistered(): bool
    {
        $file = '/etc/dwemer/shared-key-consumers.json';
        $text = is_readable($file) ? @file_get_contents($file, false, null, 0, 16384) : false;
        $registry = is_string($text) ? json_decode($text, true) : null;
        foreach (is_array($registry['products'] ?? null) ? $registry['products'] : [] as $product) {
            if (is_array($product) && ($product['id'] ?? '') === 'synth') return true;
        }
        return false;
    }

    /** True when a SYNTH-only key already has this label (exact case), other than the key $exceptId. */
    private function labelTaken(string $label, int $exceptId): bool
    {
        try {
            return $this->connection()->executeQuery('SELECT 1 FROM core_api_badge WHERE label = ? AND id <> ? LIMIT 1', [$label, $exceptId])->fetchOne() !== false;
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return true;
        }
    }

    /** One fixed line in the server log; never a key, a value or a database message. */
    private static function note(string $message): void
    {
        if (class_exists('Logger', false)) {
            \Logger::warn($message);
        } else {
            error_log($message);
        }
    }

    /** The last refusal code of this instance, never a message from a database. */
    public function lastError(): string
    {
        return $this->error;
    }

    // ---- internals

    /** The configured shared store, loaded once per process. */
    private static function store(): ?SharedApiKeyStore
    {
        if (!self::$storeLoaded) {
            self::$store = SharedApiKeyStore::fromEnvironment();
            self::$storeLoaded = true;
        }
        return self::$store;
    }

    /** The shared store when it can be used right now, else null. */
    private function usable(): ?SharedApiKeyStore
    {
        $store = self::store();
        return $store !== null && $store->ready() ? $store : null;
    }

    /** The connector reference scanner over the same session. */
    private function references(): ConnectorReferences
    {
        return $this->references ??= new ConnectorReferences($this->db);
    }

    /**
     * Raw rows of both stores (or one): id (a reference string), store, label, provider, origin, revision, secret.
     *
     * @return list<array<string,string>>
     */
    private function rows(?string $store, bool $unused): array
    {
        $out = [];
        if ($store !== ApiKeyRef::LOCAL && ($shared = $this->usable()) !== null) {
            foreach ($this->guard(fn() => $shared->listActive(true), []) as $key) {
                $out[] = ['id' => 'shared:'.$key['id'], 'store' => ApiKeyRef::SHARED, 'label' => $key['label'], 'provider' => $key['provider'],
                    'origin' => $key['origin'], 'revision' => $key['revision'], 'secret' => $key['secret']];
            }
        }
        if ($store !== ApiKeyRef::SHARED) {
            try {
                foreach ($this->connection()->executeQuery('SELECT id, label, api_key FROM core_api_badge ORDER BY id')->fetchAllAssociative() as $key) {
                    $out[] = ['id' => 'local:'.$key['id'], 'store' => ApiKeyRef::LOCAL, 'label' => (string) $key['label'], 'provider' => '', 'origin' => '',
                        'revision' => '0', 'secret' => (string) $key['api_key']];
                }
            } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
                self::note('SYNTH-only API key list could not be read');
            }
        }
        return $out;
    }

    /** One key's raw row, or null. */
    private function row(ApiKeyRef $ref): ?array
    {
        if ($ref->isShared()) {
            $shared = $this->usable();
            $key = $shared === null ? null : $this->guard(fn() => $shared->find($ref->id()), null);
            if (!is_array($key) || $key['status'] !== 'active') return null;
            return ['id' => $ref->toString(), 'store' => ApiKeyRef::SHARED, 'label' => $key['label'], 'provider' => $key['provider'], 'origin' => $key['origin'],
                'revision' => $key['revision'], 'secret' => $key['secret']];
        }
        try {
            $key = $this->connection()->executeQuery('SELECT id, label, api_key FROM core_api_badge WHERE id = ?', [(int) $ref->id()])->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return null;
        }
        return is_array($key) ? ['id' => $ref->toString(), 'store' => ApiKeyRef::LOCAL, 'label' => (string) $key['label'], 'provider' => '', 'origin' => '',
            'revision' => '0', 'secret' => (string) $key['api_key']] : null;
    }

    /** The ApiBadge of a raw row. */
    private function entity(array $row): ApiBadge
    {
        return new ApiBadge(ApiKeyRef::parse($row['id']), $row['label'], $row['provider'], $row['origin'], (int) $row['revision'], trim($row['secret']) !== '');
    }

    /** Inserts a SYNTH-only key; the label is unique in exact case. */
    private function createLocal(string $label, string $secret): ?ApiKeyRef
    {
        if ($label === '' || strlen($secret) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $label.$secret) === 1) { $this->error = 'refused'; return null; }
        if ($this->labelTaken($label, 0)) { $this->error = 'duplicate_label'; return null; }
        try {
            $id = $this->connection()->executeQuery('INSERT INTO core_api_badge (label, api_key) VALUES (?, ?) RETURNING id', [$label, $secret])->fetchOne();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            $this->error = 'duplicate_label';
            return null;
        }
        return ApiKeyRef::local((int) $id);
    }

    /** True when the statement ran. */
    private function run(string $sql, array $params): bool
    {
        try {
            $this->connection()->executeStatement($sql, $params);
            return true;
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            $this->error = 'refused';
            return false;
        }
    }

    /** A shared-store failure reads as "no such key" or "refused", with a fixed code and no database text. */
    private function guard(callable $work, mixed $fallback): mixed
    {
        try {
            return $work();
        } catch (SharedApiKeyException) {
            $this->error = 'unavailable';
            return $fallback;
        }
    }

    /** The DBAL connection over the legacy session. */
    private function connection(): Connection
    {
        return $this->connection ??= EntityManagerFactory::forSharedSession($this->db)->getConnection();
    }
}
