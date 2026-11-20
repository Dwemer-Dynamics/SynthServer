<?php

// Reads core_memory_batch_rebuild. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatchRebuild;

/** Finds rows of core_memory_batch_rebuild. */
final class MemoryBatchRebuildDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $batchId): ?AbstractMemoryBatchRebuild
    {
        return $this->fresh()->find(AbstractMemoryBatchRebuild::class, $batchId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatchRebuild> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatchRebuild::class)->findBy([], ['batchId' => 'ASC'], max(1, $limit)));
    }
}
