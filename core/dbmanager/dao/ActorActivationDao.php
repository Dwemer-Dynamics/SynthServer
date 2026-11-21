<?php

// Reads core_actor_activation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActorActivation;

/** Finds rows of core_actor_activation. */
class ActorActivationDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, int|string $generation, int|string $actorIdentityId): ?AbstractActorActivation
    {
        return $this->fresh()->find(AbstractActorActivation::class, ['sessionId' => $sessionId, 'generation' => $generation, 'actorIdentityId' => $actorIdentityId]);
    }

    /** Marks the active activations of the session, up to this generation, stale (a halt). */
    public function staleUpToGeneration(string $sessionId, int $generation): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_actor_activation SET state='stale', last_seen_at=NOW()
              WHERE session_id = ? AND generation <= ? AND state='active'",
            [$sessionId, $generation]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActorActivation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActorActivation::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC', 'actorIdentityId' => 'ASC'], max(1, $limit)));
    }
}
