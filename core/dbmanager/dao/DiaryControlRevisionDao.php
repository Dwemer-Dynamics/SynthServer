<?php

// Reads core_diary_control_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryControlRevision;

/** Finds rows of core_diary_control_revision. */
final class DiaryControlRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractDiaryControlRevision
    {
        return $this->fresh()->find(AbstractDiaryControlRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryControlRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryControlRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
