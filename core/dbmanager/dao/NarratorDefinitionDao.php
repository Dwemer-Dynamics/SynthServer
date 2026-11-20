<?php

// Reads core_narrator_definition. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorDefinition;

/** Finds rows of core_narrator_definition. */
final class NarratorDefinitionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $definitionId): ?AbstractNarratorDefinition
    {
        return $this->fresh()->find(AbstractNarratorDefinition::class, $definitionId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorDefinition> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorDefinition::class)->findBy([], ['definitionId' => 'ASC'], max(1, $limit)));
    }
}
