<?php

// Reads core_diary_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryResult;

/** Finds rows of core_diary_result. */
final class DiaryResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractDiaryResult
    {
        return $this->fresh()->find(AbstractDiaryResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
