<?php

// Reads core_action_custom. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionCustom;

/** Finds rows of core_action_custom. */
final class ActionCustomDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractActionCustom
    {
        return $this->fresh()->find(AbstractActionCustom::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActionCustom> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActionCustom::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
