<?php

namespace Synth\Core;

use Doctrine\DBAL\Connection;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__).'/EntityManagerFactory.php';
require_once dirname(__DIR__).'/SharedApiKeyStore.php';

// The only reader and writer of synth.core_api_badge (id, label, api_key, shared_key_id). Statements run through Doctrine
// DBAL on the shared legacy session, so they keep its reconnect, search_path, transaction and failure logging. Each method
// keeps the return value of the legacy call it replaced: "none" or "unreadable" is [] / false / 0, never an exception, and
// the lookups that had no ORDER BY still have none.
// Keys are credentials: nothing here logs one, and callers decide what may reach a page.
//
// Shared keys (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"). core_api_badge stays the stable local binding: its id and
// label are what connector rows reference. When the shared `dwemer` store is configured (CHIM_SHARED_DB_*) and the
// migration added core_api_badge.shared_key_id, a bound row's key is read from the shared api_keys table and writes go to
// it first. core_api_badge.api_key stays as a mirror of the shared value: the fallback when the store cannot answer and
// the rollback copy. Without the configuration, or before the migration, none of this runs: no extra query, log line or
// warning, and every statement is the one SYNTH always ran. The repository never deletes a shared key: Delete removes the
// local binding only, since another product may use the key.
class ApiBadgeRepository
{
    // Listing orders offered to the connector pages; the SQL never takes a caller's text.
    private const ORDERS = ['id' => 'id ASC', 'label' => 'label ASC', 'lower_label' => 'LOWER(label) ASC'];

    private static ?SharedApiKeyStore $store = null;
    private static bool $storeLoaded = false;

    private ?Connection $connection = null;
    private ?bool $bindingReady = null;

    public function __construct(private $db) {}

    /** Test and tool seam: the store used from now on (null = local keys only). false re-reads the environment. */
    public static function useSharedStore(SharedApiKeyStore|null|false $store): void
    {
        self::$store = $store === false ? null : $store;
        self::$storeLoaded = $store !== false;
    }

    /** The row with this exact id, or [] when there is none. */
    public function byId(int $id): array
    {
        return $this->row('SELECT * FROM core_api_badge WHERE id = ? LIMIT 1', [$id]);
    }

    /** The lowest-id row whose label matches in any letter case, or []. */
    public function byLabel(string $label): array
    {
        return $this->row('SELECT * FROM core_api_badge WHERE LOWER(label) = LOWER(?) ORDER BY id ASC LIMIT 1', [$label]);
    }

    /** Every row, in the table's own order. */
    public function all(): array
    {
        return $this->rows('SELECT * FROM core_api_badge WHERE TRUE', []);
    }

    /** Id and label of every row, never the key. */
    public function labels(string $order): array
    {
        return $this->rows('SELECT id, label FROM core_api_badge ORDER BY '.$this->order($order), []);
    }

    /** Id, label and key of every row, for pages that only show whether a key is saved. */
    public function labelsWithKeys(string $order): array
    {
        return $this->rows('SELECT id, label, api_key'.$this->bindingColumn().' FROM core_api_badge ORDER BY '.$this->order($order), []);
    }

    /** ['api_key' => ...] of the row with this id, or []. */
    public function keyById(int $id): array
    {
        return $this->row('SELECT api_key'.$this->bindingColumn().' FROM core_api_badge WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * ['api_key' => ...] of a row whose lower-cased label is exactly $lowerLabel, or []. The connector lookups
     * never ordered this; Quickstart orders by id so it reads the provider's lowest-id row.
     */
    public function keyByLowerLabel(string $lowerLabel, bool $lowestId = false): array
    {
        return $this->row('SELECT api_key'.$this->bindingColumn().' FROM core_api_badge WHERE lower(label) = ?'.($lowestId ? ' ORDER BY id' : '').' LIMIT 1',
            [$lowerLabel]);
    }

    /** ['id' => ...] of the first row whose lower-cased label is exactly $lowerLabel (unordered), or []. */
    public function idByLowerLabel(string $lowerLabel): array
    {
        return $this->row('SELECT id FROM core_api_badge WHERE lower(label) = ? LIMIT 1', [$lowerLabel]);
    }

    /** ['id' => ...] of the local Player2 entry: a Player2 label first, then SYNTH, lowest id first; or []. */
    public function localProviderId(): array
    {
        return $this->row("SELECT id FROM core_api_badge WHERE lower(label) IN ('player2','synth')
            ORDER BY CASE WHEN lower(label)='player2' THEN 0 ELSE 1 END, id ASC LIMIT 1", []);
    }

    /**
     * Inserts a row and returns its id, or 0 on failure. Only id, label and api_key are taken; without an id the
     * column default assigns one.
     */
    public function create(array $data): int
    {
        $data = array_intersect_key($data, array_flip(['id', 'label', 'api_key']));
        if (isset($data['id'])) $data['id'] = (int) $data['id'];
        if ($data === []) {
            \Logger::error('SQL: Insert returning ID refused because no values were provided for core_api_badge.');
            return 0;
        }
        $row = $this->row('INSERT INTO core_api_badge ('.implode(', ', array_keys($data)).') VALUES ('
            .implode(', ', array_fill(0, count($data), '?')).') RETURNING id', array_values($data), true);
        $id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($id > 0 && strval($data['api_key'] ?? '') !== '') $this->promote($id);
        return $id;
    }

    /** Inserts label and key with a defaulted id; only success is reported, as the legacy insert did. */
    public function add(string $label, string $apiKey): bool
    {
        if ($this->shared() === null) {
            return $this->run('INSERT INTO core_api_badge (label, api_key) VALUES (?, ?)', [$label, $apiKey], quiet: true);
        }
        $row = $this->row('INSERT INTO core_api_badge (label, api_key) VALUES (?, ?) RETURNING id', [$label, $apiKey], true);
        if (!isset($row['id'])) return false;
        if ($apiKey !== '') $this->promote((int) $row['id']);
        return true;
    }

    /** Sets label and/or api_key on this id. A true result does not mean a row matched. */
    public function update(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(['label', 'api_key']));
        if ($data === []) {
            \Logger::error('SQL: updateRow refused because no values were provided for core_api_badge.');
            return false;
        }
        $store = $this->configured();
        if ($store !== null && array_key_exists('api_key', $data)) return $this->updateKeyed($store, $id, $data);
        return $this->updateLocal($id, $data);
    }

    /** True when the statement ran, whether or not a row matched. The shared key, if any, is never deleted. */
    public function delete(int $id): bool
    {
        return $this->run('DELETE FROM core_api_badge WHERE id = ?', [$id]);
    }

    /**
     * Quickstart's write: the key goes to the lowest-id row whose lower-cased label is $lowerLabel, or a new row
     * named $label when there is none. An unreadable lookup saves nothing.
     */
    public function saveKeyForLowerLabel(string $lowerLabel, string $label, string $apiKey): bool
    {
        try {
            $result = $this->connection()->executeQuery('SELECT id FROM core_api_badge WHERE lower(label) = ? ORDER BY id LIMIT 1', [$lowerLabel]);
            $row = $result->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return false;
        }
        if (is_array($row) && intval($row['id'] ?? 0) > 0) return $this->update(intval($row['id']), ['api_key' => $apiKey]);
        return $this->add($label, $apiKey);
    }

    public function lastError(): string
    {
        return $this->db->GetLastError();
    }

    private function order(string $order): string
    {
        return self::ORDERS[$order] ?? throw new \InvalidArgumentException('Unknown API key listing order');
    }

    private function updateLocal(int $id, array $data): bool
    {
        $set = implode(', ', array_map(static fn($column) => $column.' = ?', array_keys($data)));
        return $this->run('UPDATE core_api_badge SET '.$set.' WHERE id = ?', [...array_values($data), $id], quiet: true);
    }

    // A key write on a store-enabled install. A bound row writes the shared value first and fails closed when the store
    // cannot be written, so the mirror never gets ahead of the shared key; the local statement then mirrors it. An
    // explicit Clear, or a value the shared table cannot hold (spaces, non-ASCII), unbinds the row and keeps the key
    // local; the shared key itself is never deleted here. If another local badge shares the bound key, an edit gets its
    // own shared key (adopted when one already holds the same value), so case variants stay independent.
    private function updateKeyed(SharedApiKeyStore $store, int $id, array $data): bool
    {
        $current = $this->row('SELECT label, shared_key_id FROM core_api_badge WHERE id = ? LIMIT 1', [$id], false, false);
        $bound = $current['shared_key_id'] ?? null;
        $value = strval($data['api_key']);
        if ($bound === null) {
            $ok = $this->updateLocal($id, $data);
            if ($ok && $value !== '') $this->promote($id);
            return $ok;
        }
        try {
            // A bound row is only written while its store answers: a local-only write would put the mirror ahead of the shared key.
            if (!$store->ready()) return false;
            if ($value === '' || !SharedApiKeyStore::secretValid($value)) return $this->updateLocal($id, $data + ['shared_key_id' => null]);
            $others = intval($this->row('SELECT count(*) AS n FROM core_api_badge WHERE shared_key_id = ? AND id <> ?', [$bound, $id], false, false)['n'] ?? 0);
            if ($others === 0 && $store->replaceSecret($bound, $value)) return $this->updateLocal($id, $data);
            $label = strval($data['label'] ?? $current['label']);
            $provider = SharedApiKeyStore::providerForLabel($label);
            if ($provider === null) return $this->updateLocal($id, $data + ['shared_key_id' => null]);
            $result = $store->adoptOrCreate($provider, $label, $value);
            if ($result['key_id'] === null) return false;
            $rebound = $store->withShareLock($result['key_id'], fn() => $this->updateLocal($id, $data + ['shared_key_id' => $result['key_id']]));
            return $rebound['outcome'] === 'locked' && $rebound['result'] === true;
        } catch (SharedApiKeyException) {
            return false;
        }
    }

    // Moves a local key into the shared store (adopting an identical active key of the same provider) and binds the row.
    // Best effort: a value the store cannot hold, an unmapped label, a full or unreachable store leaves the row local.
    private function promote(int $id): void
    {
        if ($this->shared() === null) return;
        $row = $this->row('SELECT label, api_key, shared_key_id FROM core_api_badge WHERE id = ? LIMIT 1', [$id], false, false);
        $provider = SharedApiKeyStore::providerForLabel(strval($row['label'] ?? ''));
        if ($row === [] || $row['shared_key_id'] !== null || $provider === null || !SharedApiKeyStore::secretValid($row['api_key'])) return;
        try {
            $result = $this->shared()->adoptOrCreate($provider, $row['label'], $row['api_key']);
            if ($result['key_id'] === null) return;
            $this->shared()->withShareLock($result['key_id'], fn() => $this->run('UPDATE core_api_badge SET shared_key_id = ? WHERE id = ? AND shared_key_id IS NULL AND api_key = ?',
                [$result['key_id'], $id, $row['api_key']], quiet: true));
        } catch (SharedApiKeyException) {
        }
    }

    // The configured store when this database has the binding column (migrated), whether or not the store answers.
    // Null, with no query at all, when no store is configured. This is what decides that a write must go through the
    // bound-row rules.
    private function configured(): ?SharedApiKeyStore
    {
        if (!self::$storeLoaded) {
            self::$store = SharedApiKeyStore::fromEnvironment();
            self::$storeLoaded = true;
        }
        if (self::$store === null) return null;
        if ($this->bindingReady === null) {
            $column = $this->row("SELECT 1 AS present FROM information_schema.columns WHERE table_schema = 'synth'
                AND table_name = 'core_api_badge' AND column_name = 'shared_key_id'", [], false, false);
            $this->bindingReady = $column !== [];
        }
        return $this->bindingReady ? self::$store : null;
    }

    // The store when SYNTH can use it right now: configured, binding column present, reachable and holding api_keys.
    // Otherwise null, silently, and the caller keeps the local behavior.
    private function shared(): ?SharedApiKeyStore
    {
        $store = $this->configured();
        return $store !== null && $store->ready() ? $store : null;
    }

    private function bindingColumn(): string
    {
        return $this->shared() === null ? '' : ', shared_key_id';
    }

    // Applies the shared value to bound rows and removes the binding column from what callers see. A key the store has
    // revoked reads as empty; a key it cannot answer for (unreachable, never held, or a legacy sealed row) keeps the
    // local mirror.
    private function resolveKeys(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (($row['shared_key_id'] ?? null) !== null && array_key_exists('api_key', $row)) $ids[] = $row['shared_key_id'];
        }
        $known = [];
        if ($ids !== [] && ($store = $this->shared()) !== null) {
            try {
                $known = $store->resolve($ids);
            } catch (SharedApiKeyException) {
            }
        }
        foreach ($rows as $index => $row) {
            $state = $known[$row['shared_key_id'] ?? ''] ?? null;
            if ($state !== null && $state['status'] !== 'active') $rows[$index]['api_key'] = '';
            elseif ($state !== null && $state['secret'] !== null) $rows[$index]['api_key'] = $state['secret'];
            unset($rows[$index]['shared_key_id']);
        }
        return $rows;
    }

    // $quiet mirrors the legacy writes that silenced PHP's warning for a failed statement so a failed save can still
    // redirect; the shared session logs the failure either way. Legacy reads and Delete never silenced it.
    // $resolve false returns the stored row, binding column included (internal use only).
    private function row(string $sql, array $params, bool $quiet = false, bool $resolve = true): array
    {
        try {
            $row = $quiet ? @$this->connection()->executeQuery($sql, $params)->fetchAssociative()
                : $this->connection()->executeQuery($sql, $params)->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            // The shared session has already logged the failed statement; readers see no row.
            return [];
        }
        if (!is_array($row)) return [];
        $row = $this->texts($row);
        return $resolve ? $this->resolveKeys([$row])[0] : $row;
    }

    private function rows(string $sql, array $params): array
    {
        try {
            return $this->resolveKeys(array_map($this->texts(...), $this->connection()->executeQuery($sql, $params)->fetchAllAssociative()));
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return [];
        }
    }

    // DBAL hands integer columns back as ints; callers always received pg_fetch_assoc's strings (and null).
    private function texts(array $row): array
    {
        return array_map(static fn($value) => $value === null ? null : strval($value), $row);
    }

    private function run(string $sql, array $params, bool $quiet = false): bool
    {
        try {
            if ($quiet) @$this->connection()->executeStatement($sql, $params);
            else $this->connection()->executeStatement($sql, $params);
            return true;
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            return false;
        }
    }

    private function connection(): Connection
    {
        return $this->connection ??= EntityManagerFactory::forSharedSession($this->db)->getConnection();
    }
}
