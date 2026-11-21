<?php

// Reads core_location_legacy. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractLocationLegacy;

/** Finds rows of core_location_legacy. */
final class LocationLegacyDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractLocationLegacy
    {
        return $this->fresh()->find(AbstractLocationLegacy::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractLocationLegacy> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractLocationLegacy::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * The column names of core_location_legacy, as information_schema lists them. Throws when the read fails.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map('strval', $this->connection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'core_location_legacy'"
        ));
    }

    /**
     * Rows whose name (or worldspace, or region: the $matchColumns, each a plain column name) equals the lower-cased candidate.
     * $regionColumn names the column returned as `region` ('region' or 'worldspace'), or is '' for an empty region.
     *
     * @param list<string> $matchColumns
     * @return list<array<string, mixed>>
     */
    public function rowsMatchingCandidate(string $regionColumn, array $matchColumns, string $candidateKey): array
    {
        foreach ([...$matchColumns, $regionColumn] as $column) {
            if ($column !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new \InvalidArgumentException('Invalid column name.');
            }
        }
        if ($matchColumns === []) {
            return [];
        }
        $clauses = [];
        foreach ($matchColumns as $column) {
            $clauses[] = "LOWER({$column}) = :candidate";
        }
        $select = $regionColumn === '' ? "name, '' AS region" : ($regionColumn === 'region' ? 'name, region' : "name, {$regionColumn} AS region");
        try {
            return $this->connection()->fetchAllAssociative("SELECT {$select} FROM core_location_legacy WHERE ".implode(' OR ', $clauses), ['candidate' => $candidateKey]);
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}
