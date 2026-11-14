<?php

namespace Synth\Core;

// Stored server-wide general settings, read on the shared connection.
class SettingsRepository
{
    public function __construct(private $db) {}

    /** Raw stored value, or null when the setting has no row. */
    public function value(string $id): ?string
    {
        $result = $this->db->execQuery('SELECT value FROM synth.general_settings WHERE id=$1 LIMIT 1', [$id]);
        $row = $result ? pg_fetch_assoc($result) : false;
        return is_array($row) ? strval($row['value'] ?? '') : null;
    }

    /** Raw stored values for the named settings that have a row, in one read; throws when unreadable. */
    public function values(array $ids): array
    {
        $result = $this->db->execQuery('SELECT id, value FROM synth.general_settings WHERE id = ANY($1::text[])',
            ['{'.implode(',', array_map(static fn($id) => '"'.addcslashes(strval($id), '"\\').'"', $ids)).'}']);
        if (!$result) throw new \RuntimeException('Unable to read general settings');
        $values = [];
        while ($row = pg_fetch_assoc($result)) $values[strval($row['id'])] = strval($row['value'] ?? '');
        return $values;
    }
}
