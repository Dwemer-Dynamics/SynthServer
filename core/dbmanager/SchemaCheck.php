<?php

declare(strict_types=1);

namespace Synth\Core\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Read-only comparison of a live database with the frozen schema manifest (docs/SCHEMA-MIGRATIONS.md, "Drift check").
 *
 * The check never migrates, never takes the migration owner's advisory lock, never creates the version table and
 * never writes a row. The live inspection runs in one READ ONLY transaction that is always rolled back, so even a
 * mistaken statement in this file could not change the database. It reports names and statuses only: no SQL, no
 * definitions, no row data and no driver message ever leave this class.
 *
 * The manifest is `migrations/schema-manifest.json`: one entry per migration with the SHA-256 of the migration class
 * and of its generated companion files (all under `files`). Offline validation (no database) refuses a stale manifest, so a new or edited
 * migration cannot be compared against an out-of-date contract. Entries with a `structure` file also contribute the
 * lines the live schema must contain; that file is the same one the baseline migration verifies in postUp().
 */
final class SchemaCheck
{
    public const MANIFEST_FILE = 'schema-manifest.json';
    private const MANIFEST_FORMAT = 1;
    private const LIVE_SCHEMAS = "'synth', 'synth_meta'";
    private const VERSION_TABLE = 'doctrine_migration_versions';
    private const MAX_REPORTED_CHANGES = 200;

    /** State exactly what a match proves. Printed with every report. */
    public const LIMITS = [
        'Compares only the structure manifest entries: tables and views by name and type, columns, vector column types, primary key, foreign key, check and unique constraints, and indexes, in the synth and synth_meta schemas and in public relations named core_*. Objects the manifest does not list are allowed and only counted.',
        'Does not compare view or function bodies, triggers, sequence state, ownership, privileges, extensions, database encoding or locale, or any row.',
        'The migration ledger is compared by version name only: a known migration that is not recorded, or a record this tree does not contain, is drift.',
        'Entries are rendered by the server under search_path synth, synth_meta, public and compared as exact strings, so equivalent SQL that PostgreSQL deparses differently may report drift. The baseline structure was generated on PostgreSQL 15 and the check is validated on PostgreSQL 17; a different major is reported, never refused.',
        'A drift report needs an authored migration and a reviewed manifest update. This tool repairs and generates nothing.',
    ];

    public function __construct(private readonly string $migrationsDirectory)
    {
    }

    public static function forTree(): self
    {
        return new self(__DIR__ . '/migrations');
    }

    /**
     * SHA-256 over the LF bytes, so a CRLF checkout is not a change (the same rule the baseline applies to its SQL file).
     *
     * @throws SchemaCheckException when the file is unreadable
     */
    public static function fileHash(string $path): string
    {
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            throw new SchemaCheckException('A migration file named by the manifest is unreadable: ' . basename($path));
        }
        return hash('sha256', str_replace("\r\n", "\n", $bytes));
    }

    /**
     * Hashes for the manifest as it should be, computed from the tree: one entry per `Version*.php`, with every
     * `<Version>.<suffix>` file beside it (the class itself, its structure manifest, its fresh SQL). Used for review and to refresh the manifest after a new migration.
     *
     * @return array<string, array{class: string, files: array<string, string>}>
     */
    public function currentEntries(): array
    {
        $entries = [];
        $files = glob($this->migrationsDirectory . '/Version*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = basename($file, '.php');
            $companions = [];
            foreach (glob($this->migrationsDirectory . '/' . $version . '.*') ?: [] as $companion) {
                $companions[basename($companion)] = self::fileHash($companion);
            }
            ksort($companions, SORT_STRING);
            $entries[$version] = [
                'class' => 'Synth\\Migrations\\' . $version,
                'files' => $companions,
            ];
        }
        return $entries;
    }

    /**
     * The reviewed manifest, refused unless it matches the tree exactly: the same set of migrations, every hash and
     * every recorded structure entry count. Nothing about a database is read here.
     *
     * @return array<string, mixed>
     * @throws SchemaCheckException
     */
    public function loadManifest(): array
    {
        $path = $this->migrationsDirectory . '/' . self::MANIFEST_FILE;
        $json = is_file($path) ? @file_get_contents($path) : false;
        $manifest = $json === false ? null : json_decode($json, true);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::MANIFEST_FORMAT
            || !is_array($manifest['migrations'] ?? null) || $manifest['migrations'] === []) {
            throw new SchemaCheckException('The schema manifest is missing or not format ' . self::MANIFEST_FORMAT . '.');
        }
        $current = $this->currentEntries();
        $problems = [];
        foreach (array_diff(array_keys($current), array_keys($manifest['migrations'])) as $name) {
            $problems[] = $name . ' has no manifest entry';
        }
        foreach (array_diff(array_keys($manifest['migrations']), array_keys($current)) as $name) {
            $problems[] = $name . ' is in the manifest but not in the tree';
        }
        foreach ($manifest['migrations'] as $name => $entry) {
            if (!isset($current[$name]) || !is_array($entry)) {
                continue;
            }
            if (($entry['class'] ?? null) !== $current[$name]['class']) {
                $problems[] = $name . ' class name differs from its manifest entry';
            }
            if (($entry['files'] ?? null) !== $current[$name]['files']) {
                $problems[] = $name . ' migration or companion file differs from its manifest entry';
            }
            if (isset($entry['structure'])) {
                $problems = [...$problems, ...$this->structureProblems((string) $name, $entry)];
            }
        }
        if ($problems !== []) {
            throw new SchemaCheckException('The schema manifest is stale: ' . implode('; ', array_slice($problems, 0, 5))
                . (count($problems) > 5 ? '; ...' : '') . '. Review the change and refresh the manifest entry.');
        }
        return $manifest;
    }

    /**
     * Every structure line the manifest requires, in file order, across all entries.
     *
     * @param array<string, mixed> $manifest from loadManifest()
     * @return list<string>
     */
    public function expectedStructure(array $manifest): array
    {
        $lines = [];
        foreach ($manifest['migrations'] as $name => $entry) {
            if (isset($entry['structure'])) {
                $lines = [...$lines, ...$this->structureLines($this->migrationsDirectory . '/' . $entry['structure']['file'])];
            }
        }
        return $lines;
    }

    /** @return list<string> */
    private function structureProblems(string $name, array $entry): array
    {
        $file = $entry['structure']['file'] ?? null;
        if (!is_string($file) || !isset($entry['files'][$file])) {
            return [$name . ' names a structure file that is not one of its hashed files'];
        }
        $count = count($this->structureLines($this->migrationsDirectory . '/' . $file));
        return $count === ($entry['structure']['entries'] ?? null) ? [] : [$name . ' structure entry count differs'];
    }

    /** @return list<string> */
    private function structureLines(string $path): array
    {
        $text = is_file($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            throw new SchemaCheckException('A structure file named by the manifest is unreadable.');
        }
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Inspect the live database inside one READ ONLY transaction and roll it back. Statements: BEGIN, SET LOCAL,
     * SHOW, SELECT, ROLLBACK. The caller supplies the connection and owns closing it.
     *
     * @return array{server_major: int, structure: list<string>, versions: ?list<string>}
     * @throws SchemaCheckException
     */
    public function inspect(Connection $connection): array
    {
        try {
            $connection->executeStatement('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
            try {
                $connection->executeStatement('SET LOCAL search_path TO synth, synth_meta, public');
                $connection->executeStatement("SET LOCAL statement_timeout = '30s'");
                $connection->executeStatement("SET LOCAL lock_timeout = '2s'");
                $major = intdiv((int) $connection->fetchOne('SHOW server_version_num'), 10000);
                $versions = null;
                if ($connection->fetchOne("SELECT to_regclass('synth_meta." . self::VERSION_TABLE . "') IS NOT NULL")) {
                    $versions = array_map('strval', $connection->fetchFirstColumn(
                        'SELECT version FROM synth_meta.' . self::VERSION_TABLE . ' ORDER BY version'));
                }
                return ['server_major' => $major, 'structure' => $this->liveStructure($connection), 'versions' => $versions];
            } finally {
                $connection->executeStatement('ROLLBACK');
            }
        } catch (\Throwable) {
            // Driver messages can carry connection details or SQL.
            throw new SchemaCheckException('Schema inspection failed; check connectivity and that the role may read the synth and synth_meta schemas.', 2);
        }
    }

    /**
     * The same inventory the baseline migration verifies (Version20261117000000::structureDifferences), rendered the
     * same way, so a clean fresh install matches, plus the public relations named core_* that the redesign's entity
     * tables use (docs/SCHEMA-REDESIGN.md, section 8). It is repeated here because that method is private and the
     * baseline file is frozen.
     *
     * @return list<string>
     */
    private function liveStructure(Connection $connection): array
    {
        return array_map('strval', $connection->fetchFirstColumn(<<<'SQL'
            SELECT concat_ws('|', 'table', table_schema, table_name, table_type)
              FROM information_schema.tables WHERE (table_schema IN ('synth', 'synth_meta') OR (table_schema = 'public' AND table_name LIKE 'core\_%'))
            UNION ALL
            SELECT concat_ws('|', 'column', table_schema, table_name, column_name, data_type, udt_name, is_nullable,
                             coalesce(column_default, ''), coalesce(character_maximum_length::text, ''))
              FROM information_schema.columns WHERE (table_schema IN ('synth', 'synth_meta') OR (table_schema = 'public' AND table_name LIKE 'core\_%'))
            UNION ALL
            SELECT concat_ws('|', 'constraint', n.nspname, t.relname, c.conname, c.contype,
                             CASE WHEN c.convalidated THEN 't' ELSE 'f' END, pg_get_constraintdef(c.oid))
              FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE (n.nspname IN ('synth', 'synth_meta') OR (n.nspname = 'public' AND t.relname LIKE 'core\_%'))
            UNION ALL
            SELECT concat_ws('|', 'index', schemaname, tablename, indexname, indexdef)
              FROM pg_indexes WHERE (schemaname IN ('synth', 'synth_meta') OR (schemaname = 'public' AND tablename LIKE 'core\_%'))
            UNION ALL
            SELECT concat_ws('|', 'typmod', n.nspname, c.relname, a.attname, format_type(a.atttypid, a.atttypmod))
              FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE (n.nspname IN ('synth', 'synth_meta') OR (n.nspname = 'public' AND c.relname LIKE 'core\_%')) AND a.attnum > 0 AND NOT a.attisdropped
               AND a.atttypid = 'public.vector'::regtype
            SQL));
    }

    /** The identity of a structure line: its kind and object path, without the definition. */
    private static function identity(string $line): string
    {
        $parts = explode('|', $line, 5);
        return implode('|', array_slice($parts, 0, $parts[0] === 'table' ? 3 : 4));
    }

    /**
     * Manifest entries the live schema lacks (`missing`) or has with another definition (`changed`), as names only.
     *
     * @param list<string> $expected
     * @param list<string> $live
     * @return array{changes: list<array{kind: string, name: string, status: string}>, total: int, unmanifested_tables: int}
     */
    public static function compare(array $expected, array $live): array
    {
        $liveSet = array_flip($live);
        $liveIdentities = [];
        foreach ($live as $line) {
            $liveIdentities[self::identity($line)] = true;
        }
        $expectedIdentities = [];
        $changes = [];
        $total = 0;
        foreach ($expected as $line) {
            $identity = self::identity($line);
            $expectedIdentities[$identity] = true;
            if (isset($liveSet[$line])) {
                continue;
            }
            $total++;
            if (count($changes) < self::MAX_REPORTED_CHANGES) {
                $parts = explode('|', $identity);
                $changes[] = [
                    'kind' => $parts[0],
                    'name' => implode('.', array_slice($parts, 1)),
                    'status' => isset($liveIdentities[$identity]) ? 'changed' : 'missing',
                ];
            }
        }
        $unmanifested = 0;
        foreach ($liveIdentities as $identity => $_) {
            $parts = explode('|', (string) $identity);
            if ($parts[0] === 'table' && !isset($expectedIdentities[$identity]) && ($parts[2] ?? '') !== self::VERSION_TABLE) {
                $unmanifested++;
            }
        }
        return ['changes' => $changes, 'total' => $total, 'unmanifested_tables' => $unmanifested];
    }

    /**
     * @param array<string, mixed> $manifest from loadManifest()
     * @param ?list<string> $recorded versions in the live ledger, null when it does not exist
     * @return array{status: string, pending: list<string>, unknown: list<string>}
     */
    public static function compareLedger(array $manifest, ?array $recorded): array
    {
        $known = array_map(static fn (array $entry): string => (string) $entry['class'], array_values($manifest['migrations']));
        if ($recorded === null || $recorded === []) {
            return ['status' => 'missing', 'pending' => $known, 'unknown' => []];
        }
        $pending = array_values(array_diff($known, $recorded));
        $unknown = array_values(array_diff($recorded, $known));
        $status = $unknown !== [] ? 'unknown' : ($pending !== [] ? 'pending' : 'current');
        return ['status' => $status, 'pending' => $pending, 'unknown' => $unknown];
    }
}

final class SchemaCheckException extends \RuntimeException
{
    /** 2 = the inspection could not run; 3 = the manifest does not match the tree. */
    public function __construct(string $message, int $code = 3)
    {
        parent::__construct($message, $code);
    }
}
