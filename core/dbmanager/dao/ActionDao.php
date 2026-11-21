<?php

// Reads core_action. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractAction;

/** Finds rows of core_action. */
final class ActionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractAction
    {
        return $this->fresh()->find(AbstractAction::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractAction> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractAction::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
