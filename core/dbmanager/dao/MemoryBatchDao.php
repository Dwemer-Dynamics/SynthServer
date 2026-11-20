<?php

// Reads core_memory_batch. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatch;

/** Finds rows of core_memory_batch. */
final class MemoryBatchDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractMemoryBatch
    {
        return $this->fresh()->find(AbstractMemoryBatch::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatch> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatch::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
