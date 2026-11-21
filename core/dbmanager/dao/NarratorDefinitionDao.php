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

    /** Retires a live narrator definition; false when it was already retired or does not exist. Runs inside the caller's transaction. */
    public function retire(string $definitionId): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE public.core_narrator_definition SET retired_at=NOW() WHERE definition_id=? AND retired_at IS NULL',
            [$definitionId]
        ) === 1;
    }

    /** Takes SHARE ROW EXCLUSIVE on the definition table until the caller's transaction ends, so the library size check cannot race. */
    public function lockLibrary(): void
    {
        $this->connection()->executeStatement('LOCK TABLE public.core_narrator_definition IN SHARE ROW EXCLUSIVE MODE');
    }

    /** Number of narrator definitions not retired. */
    public function countActive(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_narrator_definition WHERE retired_at IS NULL');
    }

    /** Creates a definition row with this id. */
    public function insertDefinition(string $definitionId): void
    {
        $this->connection()->executeStatement('INSERT INTO public.core_narrator_definition(definition_id) VALUES(?)', [$definitionId]);
    }

    /**
     * Locks a live definition row FOR UPDATE (the caller's transaction holds it) and returns its latest revision id, or null when
     * the definition is retired or missing. The lock serializes concurrent edits into one linear revision chain.
     *
     * @return array{prior: int|null}|null
     */
    public function lockLiveForRevision(string $definitionId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT (SELECT MAX(revision_id) FROM public.core_narrator_definition_revision r
                WHERE r.definition_id=d.definition_id) AS prior FROM public.core_narrator_definition d
                WHERE d.definition_id=? AND d.retired_at IS NULL FOR UPDATE',
            [$definitionId]
        );
        if ($row === false) {
            return null;
        }

        return ['prior' => $row['prior'] === null ? null : (int) $row['prior']];
    }

    /** Bumps the edit generation of a definition. */
    public function bumpEditGeneration(string $definitionId): void
    {
        $this->connection()->executeStatement('UPDATE public.core_narrator_definition SET edit_generation=edit_generation+1 WHERE definition_id=?', [$definitionId]);
    }

    /** The newest revision of every live definition, with its revision count. @return list<array<string, mixed>> */
    public function currentWithRevisionCounts(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT DISTINCT ON (d.definition_id) d.definition_id,r.revision_id,r.name,r.personality,
                r.background,r.style,r.voice,r.profile_id,r.created_at,
                (SELECT COUNT(*) FROM public.core_narrator_definition_revision x WHERE x.definition_id=d.definition_id) AS revisions
            FROM public.core_narrator_definition d JOIN public.core_narrator_definition_revision r USING(definition_id)
            WHERE d.retired_at IS NULL ORDER BY d.definition_id,r.revision_id DESC'
        );
    }
}
