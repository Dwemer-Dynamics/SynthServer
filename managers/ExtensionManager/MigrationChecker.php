<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// The rule for SQL an extension may run: its migration files and its ExtensionDatabase calls. Deliberately narrow: one
// statement at a time, only tables, indexes and sequences (create, alter, drop) and row changes, and every relation named
// must start with ext_<id>_ (id in snake_case). Functions, triggers, views, schemas, roles, COPY, GRANT, DO blocks,
// dollar quoting, quoted identifiers, other schemas and system catalogs are refused, as are comma joins and subselects
// (which could name a table the scan did not see). Strings are removed before the scan, so a literal can say anything.
final class MigrationChecker
{
    public const MAX_STATEMENT_BYTES = 65536;

    private const MIGRATION_STARTS = [
        'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'CREATE INDEX', 'CREATE UNIQUE INDEX', 'DROP INDEX',
        'CREATE SEQUENCE', 'ALTER SEQUENCE', 'DROP SEQUENCE', 'INSERT', 'UPDATE', 'DELETE',
    ];
    private const FORBIDDEN = ['FUNCTION', 'PROCEDURE', 'TRIGGER', 'EXECUTE', 'COPY', 'GRANT', 'REVOKE', 'DO', 'EXTENSION', 'SCHEMA',
        'ROLE', 'USER', 'USING', 'VIEW', 'TRUNCATE', 'VACUUM', 'LISTEN', 'NOTIFY', 'SET', 'RESET', 'CALL', 'LOCK', 'COMMENT', 'OWNER', 'POLICY', 'RULE'];

    public static function prefix(string $id): string
    {
        return 'ext_'.str_replace('-', '_', $id).'_';
    }

    /**
     * Splits a migration file into statements and checks each one.
     *
     * @return array{statements: list<string>, problems: list<string>}
     */
    public static function checkFile(string $sql, string $id): array
    {
        $statements = [];
        $problems = [];
        $parts = self::split($sql);
        if ($parts === null) return ['statements' => [], 'problems' => ['unterminated quote or comment']];
        foreach ($parts as $index => $statement) {
            $problem = self::checkStatement($statement, $id, null);
            if ($problem !== null) $problems[] = 'statement '.($index + 1).': '.$problem;
            $statements[] = $statement;
        }
        if ($statements === []) $problems[] = 'the file holds no statement';
        return ['statements' => $statements, 'problems' => $problems];
    }

    /**
     * Checks one statement. $verbs null means a migration statement (DDL and row changes); a list restricts it to
     * those first words (the runtime calls). Returns the problem, or null when it is allowed.
     */
    public static function checkStatement(string $sql, string $id, ?array $verbs): ?string
    {
        if (strlen($sql) > self::MAX_STATEMENT_BYTES) return 'statement too large';
        $bare = self::withoutLiterals($sql);
        if ($bare === null) return 'unterminated quote or comment';
        $bare = trim(rtrim(trim($bare), ';'));
        if ($bare === '' || str_contains($bare, ';')) return 'exactly one statement is allowed';
        if (str_contains($bare, '"') || str_contains($bare, '$$') || preg_match('/\$[A-Za-z_]*\$/', $bare) === 1) return 'quoted identifiers and dollar quoting are refused';
        $upper = strtoupper(preg_replace('/\s+/', ' ', $bare));
        if ($verbs === null) {
            $ok = false;
            foreach (self::MIGRATION_STARTS as $start) if (str_starts_with($upper, $start.' ')) { $ok = true; break; }
            if (!$ok) return 'only table, index and sequence changes and row changes are allowed';
        } elseif (!in_array(strtok($upper, ' '), $verbs, true)) {
            return 'only '.implode(', ', $verbs).' is allowed here';
        }
        // ON CONFLICT ... DO NOTHING / DO UPDATE SET is an ordinary row change, not a DO block.
        $scan = preg_replace('/\bDO\s+(NOTHING|UPDATE)\b/', 'DO_ACTION', $upper);
        foreach (self::FORBIDDEN as $word) {
            if (preg_match('/\b'.$word.'\b/', $scan) === 1 && !self::isColumnUse($word, $upper)) return "{$word} is refused";
        }
        if (preg_match('/\b(PG_[A-Z_]+|INFORMATION_SCHEMA|PUBLIC\.CORE_)/', $upper) === 1) return 'system catalogs and core tables are refused';
        if (preg_match('/\(\s*SELECT\b/', $upper) === 1) return 'subselects are refused';
        if (preg_match('/\bFROM\s+[A-Z0-9_.]+(?:\s+(?:AS\s+)?[A-Z0-9_]+)?\s*,/', $upper) === 1) return 'comma joins are refused';
        if (preg_match('/^(?:DROP|ALTER)\s+(?:TABLE|INDEX|SEQUENCE)\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?[A-Z0-9_.]+\s*,/', $upper) === 1) return 'one relation per statement';
        $prefix = strtoupper(self::prefix($id));
        $names = self::relationNames($upper);
        if ($names === []) return 'no table is named';
        foreach ($names as $name) {
            $name = preg_replace('/^PUBLIC\./', '', $name);
            if (!str_starts_with($name, $prefix) || preg_match('/^[A-Z0-9_]+$/', $name) !== 1) return 'relation outside '.strtolower($prefix).': '.strtolower($name);
        }
        return null;
    }

    /** @return list<string>|null the statements of a file (semicolon outside quotes and comments), or null when it is malformed */
    private static function split(string $sql): ?array
    {
        $clean = self::withoutComments($sql);
        if ($clean === null) return null;
        $statements = [];
        $current = '';
        $quote = false;
        $length = strlen($clean);
        for ($i = 0; $i < $length; $i++) {
            $c = $clean[$i];
            if ($c === "'") $quote = !$quote;
            if ($c === ';' && !$quote) {
                if (trim($current) !== '') $statements[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $c;
        }
        if ($quote) return null;
        if (trim($current) !== '') $statements[] = trim($current);
        return $statements;
    }

    private static function withoutComments(string $sql): ?string
    {
        $out = '';
        $length = strlen($sql);
        $quote = false;
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            if ($c === "'") { $quote = !$quote; $out .= $c; continue; }
            if (!$quote && $c === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $length && $sql[$i] !== "\n") $i++;
                $out .= "\n";
                continue;
            }
            if (!$quote && $c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) return null;
                $i = $end + 1;
                $out .= ' ';
                continue;
            }
            $out .= $c;
        }
        return $quote ? null : $out;
    }

    // Comments and string literals replaced by a placeholder, so words inside them are never scanned.
    private static function withoutLiterals(string $sql): ?string
    {
        $clean = self::withoutComments($sql);
        if ($clean === null) return null;
        return preg_replace("/'(?:[^']|'')*'/", "''", $clean);
    }

    // `SET` and `USER` etc. may appear as a column or UPDATE ... SET; allow SET only in an UPDATE statement.
    private static function isColumnUse(string $word, string $upper): bool
    {
        return $word === 'SET' && (str_starts_with($upper, 'UPDATE ') || str_contains($upper, 'DO UPDATE SET'));
    }

    /** @return list<string> relation names after TABLE, INDEX ... ON, INTO, UPDATE, FROM, JOIN, REFERENCES, SEQUENCE and index names */
    private static function relationNames(string $upper): array
    {
        $names = [];
        $name = '([A-Z0-9_.]+)';
        $patterns = [
            '/\b(?:CREATE|ALTER|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?'.$name.'/',
            '/\b(?:CREATE|ALTER|DROP)\s+SEQUENCE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?'.$name.'/',
            '/\bDROP\s+INDEX\s+(?:IF\s+EXISTS\s+)?'.$name.'/',
            '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?'.$name.'\s+ON\s+(?:ONLY\s+)?'.$name.'/',
            '/\bRENAME\s+TO\s+'.$name.'/',
            '/\bINSERT\s+INTO\s+'.$name.'/',
            '/^UPDATE\s+(?:ONLY\s+)?'.$name.'/',
            '/\b(?:FROM|JOIN|REFERENCES)\s+'.$name.'/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $upper, $matches) > 0) {
                for ($i = 1; $i < count($matches); $i++) foreach ($matches[$i] as $found) $names[] = $found;
            }
        }
        return array_values(array_unique($names));
    }
}
