<?php

// Reads core_catalog_version. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCatalogVersion;

/** Finds rows of core_catalog_version. */
final class CatalogVersionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $catalogKey): ?AbstractCatalogVersion
    {
        return $this->fresh()->find(AbstractCatalogVersion::class, $catalogKey);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCatalogVersion> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCatalogVersion::class)->findBy([], ['catalogKey' => 'ASC'], max(1, $limit)));
    }
}
