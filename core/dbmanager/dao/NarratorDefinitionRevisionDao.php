<?php

// Reads core_narrator_definition_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorDefinitionRevision;

/** Finds rows of core_narrator_definition_revision. */
final class NarratorDefinitionRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $revisionId): ?AbstractNarratorDefinitionRevision
    {
        return $this->fresh()->find(AbstractNarratorDefinitionRevision::class, $revisionId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorDefinitionRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorDefinitionRevision::class)->findBy([], ['revisionId' => 'ASC'], max(1, $limit)));
    }
}
