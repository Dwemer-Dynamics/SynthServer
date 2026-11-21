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

    /** Number of locations (home dashboard). */
    public function dashboardCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_location_legacy');
    }

    /**
     * Locations by name, at most 250, with the given columns (home dashboard).
     *
     * @param list<string> $columns from name, formid, worldspace, updated_at
     * @return list<array<string, mixed>>
     */
    public function dashboardRows(array $columns): array
    {
        $columns = array_values(array_intersect($columns, ['name', 'formid', 'worldspace', 'updated_at']));
        if ($columns === []) {
            throw new \InvalidArgumentException('No known location column was requested.');
        }

        return $this->connection()->fetchAllAssociative('SELECT '.implode(', ', $columns).' FROM core_location_legacy ORDER BY name ASC LIMIT 250');
    }

    /**
     * The location with coordinates nearest to a point, optionally limited to a worldspace (case-insensitive), or null.
     *
     * @return array<string, mixed>|null
     */
    public function nearestToPoint(float $x, float $y, string $worldspace = ''): ?array
    {
        $where = '';
        $params = [];
        if ($worldspace !== '') {
            $where = " AND lower(COALESCE(worldspace, '')) = lower(?)";
            $params[] = $worldspace;
        }
        $params[] = "({$x},{$y})";
        $row = $this->connection()->fetchAssociative(
            'SELECT name, formid, worldspace FROM core_location_legacy WHERE coords IS NOT NULL'.$where.' ORDER BY coords <-> CAST(? AS point) LIMIT 1',
            $params
        );

        return $row === false ? null : $row;
    }
}
