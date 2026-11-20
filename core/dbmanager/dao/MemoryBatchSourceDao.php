<?php

// Reads core_memory_batch_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatchSource;

/** Finds rows of core_memory_batch_source. */
final class MemoryBatchSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $batchId, int|string $historyEventId): ?AbstractMemoryBatchSource
    {
        return $this->fresh()->find(AbstractMemoryBatchSource::class, ['batchId' => $batchId, 'historyEventId' => $historyEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatchSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatchSource::class)->findBy([], ['batchId' => 'ASC', 'historyEventId' => 'ASC'], max(1, $limit)));
    }
}
