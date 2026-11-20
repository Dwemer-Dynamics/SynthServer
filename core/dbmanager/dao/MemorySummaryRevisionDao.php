<?php

// Reads core_memory_summary_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemorySummaryRevision;

/** Finds rows of core_memory_summary_revision. */
final class MemorySummaryRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $summaryId, int|string $revision): ?AbstractMemorySummaryRevision
    {
        return $this->fresh()->find(AbstractMemorySummaryRevision::class, ['summaryId' => $summaryId, 'revision' => $revision]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemorySummaryRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemorySummaryRevision::class)->findBy([], ['summaryId' => 'ASC', 'revision' => 'ASC'], max(1, $limit)));
    }
}
