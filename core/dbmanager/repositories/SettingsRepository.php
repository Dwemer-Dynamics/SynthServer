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
}
