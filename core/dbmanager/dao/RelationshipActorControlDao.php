<?php

// Reads core_relationship_actor_control. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipActorControl;

/** Finds rows of core_relationship_actor_control. */
final class RelationshipActorControlDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $actorIdentityId): ?AbstractRelationshipActorControl
    {
        return $this->fresh()->find(AbstractRelationshipActorControl::class, $actorIdentityId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipActorControl> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipActorControl::class)->findBy([], ['actorIdentityId' => 'ASC'], max(1, $limit)));
    }
}
