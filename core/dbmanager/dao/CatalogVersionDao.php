<?php

// Reads core_catalog_version. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractCatalogVersion;

/** Finds rows of core_catalog_version. */
class CatalogVersionDao
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

    /**
     * Records that a catalogue release is installed: inserts the key when absent, then raises its version to at least
     * $version. The two statements run in this order on the caller's transaction; older populated installs have no unique
     * key, so the caller locks the table first. False when either statement fails.
     */
    public function recordVersion(string $catalogKey, int $version): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_catalog_version(catalog_key,version) SELECT ?,? WHERE NOT EXISTS (SELECT 1 FROM public.core_catalog_version WHERE catalog_key=?)',
                [$catalogKey, $version, $catalogKey]
            );
            $this->connection()->executeStatement(
                'UPDATE public.core_catalog_version SET version=GREATEST(version,?) WHERE catalog_key=?',
                [$version, $catalogKey]
            );

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }
}
