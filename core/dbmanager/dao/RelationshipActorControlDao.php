<?php

// Reads core_relationship_actor_control. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipActorControl;

/** Finds rows of core_relationship_actor_control. */
final class RelationshipActorControlDao
{
    use UsesEntityManager;
    use TextRows;

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

    /** Takes the actor-wide relationship control advisory lock for the rest of the caller's transaction. */
    public function lock(int $actorId): void
    {
        $this->connection()->executeQuery(
            "SELECT pg_advisory_xact_lock(hashtextextended('synth-relationship-control:'||:p1,0))",
            ['p1' => $actorId]
        );
    }

    /** @return array{revision: string, locked: string, barrier_event_id: string}|null the control row as text, or null */
    public function control(int $actorId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT revision,locked,barrier_event_id FROM public.core_relationship_actor_control WHERE actor_identity_id=:p1',
            ['p1' => $actorId]
        );

        return self::textRow($row);
    }
}
