<?php

// Reads core_memory_batch_control. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatchControl;

/** Finds rows of core_memory_batch_control. */
final class MemoryBatchControlDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $batchId): ?AbstractMemoryBatchControl
    {
        return $this->fresh()->find(AbstractMemoryBatchControl::class, $batchId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatchControl> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatchControl::class)->findBy([], ['batchId' => 'ASC'], max(1, $limit)));
    }
}
