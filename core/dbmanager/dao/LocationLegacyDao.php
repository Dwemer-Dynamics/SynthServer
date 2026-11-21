<?php

// Reads core_location_legacy. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractLocationLegacy;
use Doctrine\DBAL\Exception as DbalException;

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
     * The legacy location whose name or worldspace best matches a travel destination (exact name, then exact worldspace, then
     * trigram similarity), as name, formid, worldspace, sim and exact_rank; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function bestMatch(string $destination): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT name, formid, worldspace,
                GREATEST(CASE WHEN lower(COALESCE(name, '')) = lower(:d) THEN 1.0 ELSE similarity(COALESCE(name, ''), :d) END,
                    similarity(COALESCE(worldspace, ''), :d)) AS sim,
                CASE WHEN lower(COALESCE(name, '')) = lower(:d) THEN 3
                    WHEN lower(COALESCE(worldspace, '')) = lower(:d) THEN 2 ELSE 0 END AS exact_rank
             FROM public.core_location_legacy WHERE COALESCE(name, '') <> ''
             ORDER BY exact_rank DESC, sim DESC, name ASC LIMIT 1",
            ['d' => $destination]
        );

        return $row === false ? null : $row;
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

    /** Empties the legacy location table (a full replace from the game). False when the statement fails. */
    public function truncate(): bool
    {
        try {
            $this->connection()->executeStatement('TRUNCATE TABLE public.core_location_legacy');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /**
     * Stores one location from the game by formid: updates the row when one exists, else inserts it. $x and $y are both set or
     * both null (no coordinates). False when a statement fails, as the legacy writer did.
     */
    public function saveByFormid(string $name, string $formid, string $worldspace, string $tags, int $isInterior, ?float $x, ?float $y, string $refs, bool $cleared): bool
    {
        $point = ($x === null || $y === null) ? 'NULL' : 'point(CAST(? AS float8), CAST(? AS float8))';
        $pointParams = ($x === null || $y === null) ? [] : [$x, $y];
        try {
            $exists = $this->connection()->fetchOne('SELECT 1 FROM public.core_location_legacy WHERE formid = CAST(? AS bigint) LIMIT 1', [$formid]) !== false;
            if ($exists) {
                $this->connection()->executeStatement(
                    'UPDATE public.core_location_legacy SET name = ?, worldspace = ?, tags = ?, is_interior = ?, coords = '.$point
                    .', refs = ?, cleared = CAST(? AS boolean), updated_at = now() WHERE formid = CAST(? AS bigint)',
                    array_merge([$name, $worldspace, $tags, $isInterior], $pointParams, [$refs, $cleared ? 'true' : 'false', $formid])
                );
            } else {
                $this->connection()->executeStatement(
                    'INSERT INTO public.core_location_legacy (name, formid, worldspace, tags, is_interior, coords, refs, cleared, updated_at) VALUES '
                    .'(?, CAST(? AS bigint), ?, ?, ?, '.$point.', ?, CAST(? AS boolean), now())',
                    array_merge([$name, $formid, $worldspace, $tags, $isInterior], $pointParams, [$refs, $cleared ? 'true' : 'false'])
                );
            }
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /**
     * Name and coordinates (as the point's text) of exterior markers that have coordinates and belong to the worldspace (or to
     * none). Throws when unreadable.
     *
     * @return list<array{name: ?string, coords: ?string}>
     */
    public function exteriorMarkers(string $worldspace, int $limit = 2000): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT name, coords::text AS coords FROM public.core_location_legacy
             WHERE COALESCE(is_interior, 0) = 0 AND coords IS NOT NULL AND (worldspace IS NULL OR worldspace = '' OR worldspace = ?)
             LIMIT ".max(1, $limit),
            [$worldspace]
        );
    }
}
