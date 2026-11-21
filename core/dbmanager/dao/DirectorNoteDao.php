<?php

// Reads core_director_note. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDirectorNote;

/** Finds rows of core_director_note. */
final class DirectorNoteDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractDirectorNote
    {
        return $this->fresh()->find(AbstractDirectorNote::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDirectorNote> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDirectorNote::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
