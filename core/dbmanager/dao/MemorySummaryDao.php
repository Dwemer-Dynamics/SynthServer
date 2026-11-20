<?php

// Reads core_memory_summary. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemorySummary;

/** Finds rows of core_memory_summary. */
final class MemorySummaryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractMemorySummary
    {
        return $this->fresh()->find(AbstractMemorySummary::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemorySummary> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemorySummary::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
