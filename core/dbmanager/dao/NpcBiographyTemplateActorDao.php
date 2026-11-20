<?php

// Reads core_npc_biography_template_actor. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcBiographyTemplateActor;

/** Finds rows of core_npc_biography_template_actor. */
final class NpcBiographyTemplateActorDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractNpcBiographyTemplateActor
    {
        return $this->fresh()->find(AbstractNpcBiographyTemplateActor::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcBiographyTemplateActor> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcBiographyTemplateActor::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
