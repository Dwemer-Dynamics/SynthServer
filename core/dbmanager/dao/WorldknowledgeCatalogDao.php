<?php

// Reads core_worldknowledge_catalog. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorldknowledgeCatalog;

/** Finds rows of core_worldknowledge_catalog. */
class WorldknowledgeCatalogDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $catalogId, string $catalogVersion): ?AbstractWorldknowledgeCatalog
    {
        return $this->fresh()->find(AbstractWorldknowledgeCatalog::class, ['catalogId' => $catalogId, 'catalogVersion' => $catalogVersion]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorldknowledgeCatalog> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorldknowledgeCatalog::class)->findBy([], ['catalogId' => 'ASC', 'catalogVersion' => 'ASC'], max(1, $limit)));
    }

    /** The one active catalog row (catalog_id, catalog_version, display_name, checksum_sha256, row_count, activated_at), or null. @return array<string, mixed>|null */
    public function active(): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT catalog_id, catalog_version, display_name, checksum_sha256, row_count, activated_at FROM public.core_worldknowledge_catalog WHERE is_active LIMIT 1'
        );

        return $row === false ? null : $row;
    }

    /** The sha256 checksum recorded for one catalog version, or null when there is no such catalog row. */
    public function checksum(string $catalogId, string $catalogVersion): ?string
    {
        $checksum = $this->connection()->fetchOne(
            'SELECT checksum_sha256 FROM public.core_worldknowledge_catalog WHERE catalog_id = ? AND catalog_version = ? LIMIT 1',
            [$catalogId, $catalogVersion]
        );

        return $checksum === false || $checksum === null ? null : (string) $checksum;
    }
}
