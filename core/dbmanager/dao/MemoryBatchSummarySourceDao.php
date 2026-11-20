<?php

// Reads core_memory_batch_summary_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatchSummarySource;

/** Finds rows of core_memory_batch_summary_source. */
final class MemoryBatchSummarySourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $batchId): ?AbstractMemoryBatchSummarySource
    {
        return $this->fresh()->find(AbstractMemoryBatchSummarySource::class, $batchId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatchSummarySource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatchSummarySource::class)->findBy([], ['batchId' => 'ASC'], max(1, $limit)));
    }
}
