<?php

// DBAL's PostgreSQL result maps int, boolean and float columns to PHP types. The relationship statements moved here from
// pg_fetch_assoc() and their callers compare the raw text ('t', '2'), so these helpers hand rows back the way the legacy wrapper
// did: every value a string (booleans 't'/'f'), null stays null.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

trait TextRows
{
    /** One row as text, or null when there was none. @param array<string, mixed>|false $row @return array<string, string|null>|null */
    private static function textRow(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        $text = [];
        foreach ($row as $column => $value) {
            $text[$column] = self::textValue($value);
        }

        return $text;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, string|null>> */
    private static function textRows(array $rows): array
    {
        return array_map(static fn (array $row): array => self::textRow($row) ?? [], $rows);
    }

    private static function textValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 't' : 'f';
        }

        return (string) $value;
    }
}
