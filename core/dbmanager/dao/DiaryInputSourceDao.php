<?php

// Reads core_diary_input_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryInputSource;

/** Finds rows of core_diary_input_source. */
final class DiaryInputSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $inputId, int|string $historyEventId): ?AbstractDiaryInputSource
    {
        return $this->fresh()->find(AbstractDiaryInputSource::class, ['inputId' => $inputId, 'historyEventId' => $historyEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryInputSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryInputSource::class)->findBy([], ['inputId' => 'ASC', 'historyEventId' => 'ASC'], max(1, $limit)));
    }
}
