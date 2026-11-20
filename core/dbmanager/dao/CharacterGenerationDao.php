<?php

// Reads core_character_generation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCharacterGeneration;

/** Finds rows of core_character_generation. */
final class CharacterGenerationDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $jobId): ?AbstractCharacterGeneration
    {
        return $this->fresh()->find(AbstractCharacterGeneration::class, $jobId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCharacterGeneration> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCharacterGeneration::class)->findBy([], ['jobId' => 'ASC'], max(1, $limit)));
    }
}
