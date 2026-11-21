<?php

// Reads core_location_legacy. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractLocationLegacy;

/** Finds rows of core_location_legacy. */
class LocationLegacyDao
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
     * Up to 20 legacy locations whose normalized name equals $key, as name and worldspace (empty when the table has no
     * worldspace column). Throws when the table is unreadable.
     *
     * @return list<array<string, mixed>>
     */
    public function byNormalizedName(string $key, bool $hasWorldspace): array
    {
        $worldspaceSelect = $hasWorldspace ? 'worldspace' : "'' AS worldspace";

        return $this->connection()->fetchAllAssociative(
            "SELECT name, {$worldspaceSelect}
                   FROM public.core_location_legacy
                  WHERE btrim(regexp_replace(lower(coalesce(name, '')), '[^a-z0-9]+', ' ', 'g')) = ?
                  LIMIT 20",
            [$key]
        );
    }
}
