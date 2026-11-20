<?php

// Reads core_relationship_actor_control_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipActorControlEvent;

/** Finds rows of core_relationship_actor_control_event. */
final class RelationshipActorControlEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $actorIdentityId, int|string $revision): ?AbstractRelationshipActorControlEvent
    {
        return $this->fresh()->find(AbstractRelationshipActorControlEvent::class, ['actorIdentityId' => $actorIdentityId, 'revision' => $revision]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipActorControlEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipActorControlEvent::class)->findBy([], ['actorIdentityId' => 'ASC', 'revision' => 'ASC'], max(1, $limit)));
    }
}
