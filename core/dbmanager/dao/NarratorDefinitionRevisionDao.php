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

    /** Appends a revision to a definition and returns its revision id. Runs inside the caller's transaction. */
    public function insertRevision(string $definitionId, ?int $priorRevisionId, string $name, string $personality, string $background, string $style, string $voice, ?int $profileId): int
    {
        return (int) $this->connection()->fetchOne(
            'INSERT INTO public.core_narrator_definition_revision
                (definition_id,prior_revision_id,name,personality,background,style,voice,profile_id)
                VALUES(?,?,?,?,?,?,?,?) RETURNING revision_id',
            [$definitionId, $priorRevisionId, $name, $personality, $background, $style, $voice, $profileId]
        );
    }

    /** The newest revisions across all definitions, at most $limit. @return list<array<string, mixed>> */
    public function recent(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT definition_id,revision_id,name,created_at FROM public.core_narrator_definition_revision ORDER BY revision_id DESC LIMIT '.max(1, $limit)
        );
    }
}
