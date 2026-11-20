<?php

// Reads core_npc_history. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcHistory;

/** Finds rows of core_npc_history. */
final class NpcHistoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $historyId): ?AbstractNpcHistory
    {
        return $this->fresh()->find(AbstractNpcHistory::class, $historyId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcHistory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcHistory::class)->findBy([], ['historyId' => 'ASC'], max(1, $limit)));
    }
}
