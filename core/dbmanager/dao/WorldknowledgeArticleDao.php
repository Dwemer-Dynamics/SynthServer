<?php

// Reads core_worldknowledge_article. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorldknowledgeArticle;

/** Finds rows of core_worldknowledge_article. */
final class WorldknowledgeArticleDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $entryId): ?AbstractWorldknowledgeArticle
    {
        return $this->fresh()->find(AbstractWorldknowledgeArticle::class, $entryId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorldknowledgeArticle> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorldknowledgeArticle::class)->findBy([], ['entryId' => 'ASC'], max(1, $limit)));
    }

    /** Number of active factory articles in active catalogs (home dashboard). */
    public function dashboardFactoryCount(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM public.core_worldknowledge_article AS article JOIN public.core_worldknowledge_catalog AS catalog'
            .' ON article.catalog_id=catalog.catalog_id AND article.catalog_version=catalog.catalog_version'
            ." WHERE article.source_kind='factory' AND article.is_active AND catalog.is_active"
        );
    }
}
