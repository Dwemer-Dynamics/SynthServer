<?php

// Reads core_diary_job_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryJobResult;

/** Finds rows of core_diary_job_result. */
final class DiaryJobResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $jobId): ?AbstractDiaryJobResult
    {
        return $this->fresh()->find(AbstractDiaryJobResult::class, $jobId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryJobResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryJobResult::class)->findBy([], ['jobId' => 'ASC'], max(1, $limit)));
    }
}
