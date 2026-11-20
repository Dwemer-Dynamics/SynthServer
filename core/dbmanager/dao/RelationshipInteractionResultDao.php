<?php

// Reads core_relationship_interaction_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionResult;

/** Finds rows of core_relationship_interaction_result. */
final class RelationshipInteractionResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipInteractionResult
    {
        return $this->fresh()->find(AbstractRelationshipInteractionResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipInteractionResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipInteractionResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
