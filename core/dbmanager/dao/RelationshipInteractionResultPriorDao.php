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

    /** Freezes the saved revision this actor held when the interaction result was computed. */
    public function add(int $resultHistoryEventId, int $actorId, int|string $priorHistoryEventId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_relationship_interaction_result_prior
                (result_history_event_id,actor_identity_id,prior_history_event_id) VALUES(:p1,:p2,:p3)',
            ['p1' => $resultHistoryEventId, 'p2' => $actorId, 'p3' => $priorHistoryEventId]
        );
    }
}
