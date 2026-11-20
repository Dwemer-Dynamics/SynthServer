<?php

// Reads core_actor_identity. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActorIdentity;

/** Finds rows of core_actor_identity. */
final class ActorIdentityDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractActorIdentity
    {
        return $this->fresh()->find(AbstractActorIdentity::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActorIdentity> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActorIdentity::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
