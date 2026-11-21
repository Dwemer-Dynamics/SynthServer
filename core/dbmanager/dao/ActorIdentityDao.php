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

    /** The id of the Fallout 4 actor identity with this form id (any case), origin plugin (any case) and playthrough, or null. */
    public function findFo4IdentityId(string $formId, string $originPlugin, string $playthroughId): ?string
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_actor_identity WHERE game='fo4'
            AND upper(full_form_id)=upper(?) AND lower(origin_plugin)=lower(?) AND playthrough_id=?",
            [$formId, $originPlugin, $playthroughId]
        );

        return $id === false ? null : (string) $id;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActorIdentity> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActorIdentity::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
