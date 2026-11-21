<?php

// Reads core_tts_pronunciation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractTtsPronunciation;

/** Finds rows of core_tts_pronunciation. */
final class TtsPronunciationDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractTtsPronunciation
    {
        return $this->fresh()->find(AbstractTtsPronunciation::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractTtsPronunciation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractTtsPronunciation::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
