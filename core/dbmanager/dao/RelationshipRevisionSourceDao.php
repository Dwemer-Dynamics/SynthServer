<?php

// Reads core_relationship_revision_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipRevisionSource;

/** Finds rows of core_relationship_revision_source. */
final class RelationshipRevisionSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $relationshipHistoryEventId, int|string $sourceHistoryEventId): ?AbstractRelationshipRevisionSource
    {
        return $this->fresh()->find(AbstractRelationshipRevisionSource::class, ['relationshipHistoryEventId' => $relationshipHistoryEventId, 'sourceHistoryEventId' => $sourceHistoryEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipRevisionSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipRevisionSource::class)->findBy([], ['relationshipHistoryEventId' => 'ASC', 'sourceHistoryEventId' => 'ASC'], max(1, $limit)));
    }

    /** Freezes that this revision depends on this source history event. */
    public function add(int $relationshipHistoryEventId, int|string $sourceHistoryEventId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_relationship_revision_source VALUES(:p1,:p2)',
            ['p1' => $relationshipHistoryEventId, 'p2' => $sourceHistoryEventId]
        );
    }
}
