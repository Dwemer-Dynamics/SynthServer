<?php

// Reads core_relationship_interaction_result_prior. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionResultPrior;

/** Finds rows of core_relationship_interaction_result_prior. */
final class RelationshipInteractionResultPriorDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $resultHistoryEventId, int|string $actorIdentityId): ?AbstractRelationshipInteractionResultPrior
    {
        return $this->fresh()->find(AbstractRelationshipInteractionResultPrior::class, ['resultHistoryEventId' => $resultHistoryEventId, 'actorIdentityId' => $actorIdentityId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipInteractionResultPrior> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipInteractionResultPrior::class)->findBy([], ['resultHistoryEventId' => 'ASC', 'actorIdentityId' => 'ASC'], max(1, $limit)));
    }
}
