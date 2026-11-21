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
}
