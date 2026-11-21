<?php

// Reads the PostgreSQL catalogs (information_schema and pg_stat) for the admin pages that adapt to what an install has.
// Read-only, no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

/** Table and column existence and row estimates. Not tied to an entity. */
final class SchemaCatalogDao
{
    use UsesEntityManager;

    /** Whether a table of this name exists in one of these schemas. @param list<string> $schemas */
    public function tableExists(string $table, array $schemas = ['synth', 'public']): bool
    {
        $marks = implode(',', array_fill(0, count($schemas), '?'));

        return $this->connection()->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema IN ({$marks}) AND table_name = ? LIMIT 1",
            [...array_values($schemas), $table]
        ) !== false;
    }

    /** Column names of a table in one of these schemas. @param list<string> $schemas @return list<string> */
    public function tableColumns(string $table, array $schemas = ['synth', 'public']): array
    {
        $marks = implode(',', array_fill(0, count($schemas), '?'));

        return array_map('strval', $this->connection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema IN ({$marks}) AND table_name = ?",
            [...array_values($schemas), $table]
        ));
    }

    /** The planner's live-row estimate for a table, or null when it has no statistics row. */
    public function estimatedRows(string $schema, string $table): ?int
    {
        $estimate = $this->connection()->fetchOne(
            'SELECT COALESCE(n_live_tup,0)::bigint FROM pg_stat_all_tables WHERE schemaname = ? AND relname = ?',
            [$schema, $table]
        );

        return $estimate === false ? null : (int) $estimate;
    }
}
