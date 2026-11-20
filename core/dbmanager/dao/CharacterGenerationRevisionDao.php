<?php

// Reads core_character_generation_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCharacterGenerationRevision;

/** Finds rows of core_character_generation_revision. */
final class CharacterGenerationRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractCharacterGenerationRevision
    {
        return $this->fresh()->find(AbstractCharacterGenerationRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCharacterGenerationRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCharacterGenerationRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
