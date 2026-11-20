<?php

// Reads core_relationship_response_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipResponseSource;

/** Finds rows of core_relationship_response_source. */
final class RelationshipResponseSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $responseHistoryEventId, int|string $sourceHistoryEventId): ?AbstractRelationshipResponseSource
    {
        return $this->fresh()->find(AbstractRelationshipResponseSource::class, ['responseHistoryEventId' => $responseHistoryEventId, 'sourceHistoryEventId' => $sourceHistoryEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipResponseSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipResponseSource::class)->findBy([], ['responseHistoryEventId' => 'ASC', 'sourceHistoryEventId' => 'ASC'], max(1, $limit)));
    }
}
