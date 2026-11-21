<?php

// Reads core_location_legacy. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
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
