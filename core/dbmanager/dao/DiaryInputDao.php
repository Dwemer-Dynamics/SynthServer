<?php

// Reads core_diary_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryInput;

/** Finds rows of core_diary_input. */
final class DiaryInputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractDiaryInput
    {
        return $this->fresh()->find(AbstractDiaryInput::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryInput::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
