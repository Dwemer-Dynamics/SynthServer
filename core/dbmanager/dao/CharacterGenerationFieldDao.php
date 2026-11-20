<?php

// Reads core_character_generation_field. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCharacterGenerationField;

/** Finds rows of core_character_generation_field. */
final class CharacterGenerationFieldDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $jobId, string $field): ?AbstractCharacterGenerationField
    {
        return $this->fresh()->find(AbstractCharacterGenerationField::class, ['jobId' => $jobId, 'field' => $field]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCharacterGenerationField> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCharacterGenerationField::class)->findBy([], ['jobId' => 'ASC', 'field' => 'ASC'], max(1, $limit)));
    }
}
