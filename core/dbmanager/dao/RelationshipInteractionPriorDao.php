<?php

// Reads core_relationship_interaction_prior. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionPrior;

/** Finds rows of core_relationship_interaction_prior. */
final class RelationshipInteractionPriorDao
{
    use UsesEntityManager;

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
}
