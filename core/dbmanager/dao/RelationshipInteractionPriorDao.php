<?php

// Reads core_relationship_interaction_prior. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionPrior;

/** Finds rows of core_relationship_interaction_prior. */
final class RelationshipInteractionPriorDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $interactionId, int|string $actorIdentityId): ?AbstractRelationshipInteractionPrior
    {
        return $this->fresh()->find(AbstractRelationshipInteractionPrior::class, ['interactionId' => $interactionId, 'actorIdentityId' => $actorIdentityId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipInteractionPrior> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipInteractionPrior::class)->findBy([], ['interactionId' => 'ASC', 'actorIdentityId' => 'ASC'], max(1, $limit)));
    }

    /** Freezes the saved revision this actor held before the interaction. */
    public function add(int $interactionId, int $actorId, int|string $priorHistoryEventId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_relationship_interaction_prior
                (interaction_id,actor_identity_id,prior_history_event_id) VALUES(:p1,:p2,:p3)',
            ['p1' => $interactionId, 'p2' => $actorId, 'p3' => $priorHistoryEventId]
        );
    }

    /** The frozen priors of one interaction, by actor. @return list<array{actor_identity_id: string, prior_history_event_id: string}> */
    public function forInteraction(int $interactionId): array
    {
        return self::textRows($this->connection()->fetchAllAssociative(
            'SELECT actor_identity_id,prior_history_event_id FROM public.core_relationship_interaction_prior
            WHERE interaction_id=:p1 ORDER BY actor_identity_id',
            ['p1' => $interactionId]
        ));
    }
}
