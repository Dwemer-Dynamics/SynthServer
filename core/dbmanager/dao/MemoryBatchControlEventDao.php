<?php

// Reads core_memory_batch_control_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatchControlEvent;

/** Finds rows of core_memory_batch_control_event. */
final class MemoryBatchControlEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $batchId, int|string $revision): ?AbstractMemoryBatchControlEvent
    {
        return $this->fresh()->find(AbstractMemoryBatchControlEvent::class, ['batchId' => $batchId, 'revision' => $revision]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatchControlEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatchControlEvent::class)->findBy([], ['batchId' => 'ASC', 'revision' => 'ASC'], max(1, $limit)));
    }
}
