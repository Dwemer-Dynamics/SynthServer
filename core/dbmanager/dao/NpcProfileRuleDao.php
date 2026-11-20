<?php

// Reads core_npc_profile_rule. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcProfileRule;

/** Finds rows of core_npc_profile_rule. */
final class NpcProfileRuleDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractNpcProfileRule
    {
        return $this->fresh()->find(AbstractNpcProfileRule::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcProfileRule> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcProfileRule::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
