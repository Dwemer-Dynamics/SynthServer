<?php

namespace Synth\Core;

use Doctrine\DBAL\Connection;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__).'/EntityManagerFactory.php';

// The only reader and writer of synth.core_api_badge (id, label, api_key). Statements run through Doctrine DBAL on the
// shared legacy session, so they keep its reconnect, search_path, transaction and failure logging. Each method keeps
// the return value of the legacy call it replaced: "none" or "unreadable" is [] / false / 0, never an exception, and
// the lookups that had no ORDER BY still have none.
// Keys are credentials: nothing here logs one, and callers decide what may reach a page.
class ApiBadgeRepository
{
    // Listing orders offered to the connector pages; the SQL never takes a caller's text.
    private const ORDERS = ['id' => 'id ASC', 'label' => 'label ASC', 'lower_label' => 'LOWER(label) ASC'];

    private ?Connection $connection = null;

    public function __construct(private $db) {}

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

    /** How many rows hold a non-empty key, counted by the database so no key is read; throws when unreadable. */
    public function countWithKeys(): int
    {
        try {
            return intval($this->connection()->executeQuery(
                "SELECT COUNT(*) FROM core_api_badge WHERE btrim(COALESCE(api_key, '')) <> ''")->fetchOne());
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            throw new \RuntimeException('Unable to count API keys', 0, $error);
        }
    }

    /** Id and label of every row, never the key. */
    public function labels(string $order): array
    {
        return $this->rows('SELECT id, label FROM core_api_badge ORDER BY '.$this->order($order), []);
    }

    /** Id, label and key of every row, for pages that only show whether a key is saved. */
    public function labelsWithKeys(string $order): array
    {
        return $this->rows('SELECT id, label, api_key FROM core_api_badge ORDER BY '.$this->order($order), []);
    }

    /** ['api_key' => ...] of the row with this id, or []. */
    public function keyById(int $id): array
    {
        return $this->row('SELECT api_key FROM core_api_badge WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * ['api_key' => ...] of a row whose lower-cased label is exactly $lowerLabel, or []. The connector lookups
     * never ordered this; Quickstart orders by id so it reads the provider's lowest-id row.
     */
    public function keyByLowerLabel(string $lowerLabel, bool $lowestId = false): array
    {
        return $this->row('SELECT api_key FROM core_api_badge WHERE lower(label) = ?'.($lowestId ? ' ORDER BY id' : '').' LIMIT 1',
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
        return isset($row['id']) ? (int) $row['id'] : 0;
    }

    /** Inserts label and key with a defaulted id; only success is reported, as the legacy insert did. */
    public function add(string $label, string $apiKey): bool
    {
        return $this->run('INSERT INTO core_api_badge (label, api_key) VALUES (?, ?)', [$label, $apiKey], quiet: true);
    }

    /** Sets label and/or api_key on this id. A true result does not mean a row matched. */
    public function update(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip(['label', 'api_key']));
        if ($data === []) {
            \Logger::error('SQL: updateRow refused because no values were provided for core_api_badge.');
            return false;
        }
        $set = implode(', ', array_map(static fn($column) => $column.' = ?', array_keys($data)));
        return $this->run('UPDATE core_api_badge SET '.$set.' WHERE id = ?', [...array_values($data), $id], quiet: true);
    }

    /** True when the statement ran, whether or not a row matched. */
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

    // $quiet mirrors the legacy writes that silenced PHP's warning for a failed statement so a failed save can still
    // redirect; the shared session logs the failure either way. Legacy reads and Delete never silenced it.
    private function row(string $sql, array $params, bool $quiet = false): array
    {
        try {
            $row = $quiet ? @$this->connection()->executeQuery($sql, $params)->fetchAssociative()
                : $this->connection()->executeQuery($sql, $params)->fetchAssociative();
        } catch (\Doctrine\DBAL\Exception|\Doctrine\DBAL\Driver\Exception $error) {
            // The shared session has already logged the failed statement; readers see no row.
            return [];
        }
        return is_array($row) ? $this->texts($row) : [];
    }

    private function rows(string $sql, array $params): array
    {
        try {
            return array_map($this->texts(...), $this->connection()->executeQuery($sql, $params)->fetchAllAssociative());
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
