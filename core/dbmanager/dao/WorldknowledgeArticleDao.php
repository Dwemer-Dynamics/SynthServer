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

    /** Topic and description of every article that has no 384-dimension vector yet. @return list<array<string, mixed>> */
    public function withoutVector384(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT topic, topic_desc FROM core_worldknowledge_article WHERE vector384 IS NULL');
    }
}
