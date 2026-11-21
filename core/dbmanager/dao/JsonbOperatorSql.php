<?php

// Doctrine's SQL parser reads every unquoted `?` as a positional placeholder, so the jsonb key-exists operator (`doc ? 'key'`)
// cannot appear in a statement that goes through DBAL. The saved-history CTE the managers still build as text contains that
// operator, and a DAO that takes such a CTE as a fragment passes it through here first.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use LogicException;

final class JsonbOperatorSql
{
    /**
     * Rewrites `<document path> ? '<key>'` to jsonb_exists(<document path>, '<key>') outside string literals. Throws when an unquoted
     * `?` is still left, instead of letting it bind to a parameter by accident.
     */
    public static function plain(string $sql): string
    {
        if (!str_contains($sql, '?')) {
            return $sql;
        }
        $plain = preg_replace(
            "~(\\b[\\w.]+(?:->>?(?:'(?:[^']|'')*'|\\d+))*)\\s\\?\\s('(?:[^']|'')*')~",
            'jsonb_exists($1,$2)',
            $sql
        );
        if ($plain === null) {
            throw new LogicException('Unable to rewrite the jsonb operators of a SQL fragment.');
        }
        $unquoted = preg_replace("~'(?:[^']|'')*'~", "''", $plain) ?? $plain;
        if (str_contains($unquoted, '?')) {
            throw new LogicException('A SQL fragment still holds an unquoted question mark after the jsonb rewrite.');
        }

        return $plain;
    }
}
