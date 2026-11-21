<?php

// Reads core_worldknowledge_catalog. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
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

    /**
     * The expected and installed factory row counts of one catalogue; [] when it is not installed or the read fails.
     *
     * @return array<string, mixed>
     */
    public function completeness(string $catalogId, string $catalogVersion): array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT catalog.row_count AS expected_rows, count(article.entry_id) AS installed_rows'
                .' FROM public.core_worldknowledge_catalog AS catalog'
                ." LEFT JOIN public.core_worldknowledge_article AS article ON article.source_kind='factory'"
                .' AND article.catalog_id=catalog.catalog_id AND article.catalog_version=catalog.catalog_version'
                .' WHERE catalog.catalog_id=? AND catalog.catalog_version=? GROUP BY catalog.row_count',
                [$catalogId, $catalogVersion]
            );
        } catch (DbalException|DriverException $error) {
            return [];
        }

        return $row === false ? [] : $row;
    }

    /** Deletes every catalogue row; false when the statement fails. */
    public function deleteAll(): bool
    {
        return $this->run('DELETE FROM public.core_worldknowledge_catalog', []);
    }

    /** Records one catalogue manifest as the active catalogue; false when the statement fails. */
    public function insertActive(string $catalogId, string $catalogVersion, string $displayName, string $checksum, int $rowCount, string $manifestJson): bool
    {
        return $this->run(
            'INSERT INTO public.core_worldknowledge_catalog '
            .'(catalog_id,catalog_version,display_name,checksum_sha256,row_count,manifest,is_active,activated_at) VALUES '
            .'(?,?,?,?,?,CAST(? AS jsonb),TRUE,CURRENT_TIMESTAMP)',
            [$catalogId, $catalogVersion, $displayName, $checksum, $rowCount, $manifestJson]
        );
    }

    /** The stored checksum of one catalogue, or null when it has no row or the read fails. */
    public function checksum(string $catalogId, string $catalogVersion): ?string
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT checksum_sha256 FROM public.core_worldknowledge_catalog WHERE catalog_id=? AND catalog_version=? LIMIT 1',
                [$catalogId, $catalogVersion]
            );
        } catch (DbalException|DriverException $error) {
            return null;
        }

        return $row === false ? null : strval($row['checksum_sha256'] ?? '');
    }

    /** @param list<mixed> $parameters */
    private function run(string $sql, array $parameters): bool
    {
        try {
            $this->connection()->executeStatement($sql, $parameters);

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }
}
