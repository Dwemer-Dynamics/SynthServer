<?php

// Reads core_middleterm_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermSource;

/** Finds rows of core_middleterm_source. */
final class MiddletermSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $inputId, int $position): ?AbstractMiddletermSource
    {
        return $this->fresh()->find(AbstractMiddletermSource::class, ['inputId' => $inputId, 'position' => $position]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermSource::class)->findBy([], ['inputId' => 'ASC', 'position' => 'ASC'], max(1, $limit)));
    }

    /**
     * Effective summaries of one actor not yet covered by the narrative chain, oldest first, at most 100, read through the caller's
     * middleterm history CTE ($cte ends with a space). Throws when the statement fails.
     *
     * @return list<array<string, mixed>>
     */
    public function unusedSummaries(string $cte, int $actorId): array
    {
        return $this->connection()->fetchAllAssociative(
            $cte." SELECT s.id,s.summary_revision,s.summary,b.last_gamets
                FROM native_memory_effective_summaries s JOIN native_memory_batches b ON b.id=s.batch_id
                WHERE b.actor_identity_id = ? AND NOT EXISTS(SELECT 1 FROM middleterm_covered used WHERE used.summary_id=s.id)
                ORDER BY b.last_gamets,s.id LIMIT 100",
            [$actorId]
        );
    }

    /** Records one frozen source of a narrative input (a replay of the same position changes nothing). False when the statement fails. */
    public function freezeSource(int $inputId, int $position, int $summaryId, int $summaryRevision, string $sourceSha256): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_middleterm_source
                    (input_id,position,summary_id,summary_revision,source_sha256) VALUES(?,?,?,?,?) ON CONFLICT DO NOTHING',
                [$inputId, $position, $summaryId, $summaryRevision, $sourceSha256]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * The pinned sources of a regeneration target replaced by the latest valid summaries of the same families, in position order,
     * read through the caller's middleterm history CTE. Throws when the statement fails.
     *
     * @return list<array<string, mixed>>
     */
    public function regenerationSources(string $cte, int $narrativeId): array
    {
        return $this->connection()->fetchAllAssociative(
            $cte." SELECT d.position,current.id,current.summary_revision,current.summary,b.last_gamets,
                    family.root_id AS source_family_id
                FROM middleterm_effective i JOIN public.core_middleterm_source d ON d.input_id=i.input_id
                JOIN public.core_memory_summary original ON original.id=d.summary_id
                JOIN public.core_memory_batch original_batch ON original_batch.id=original.batch_id
                    AND original_batch.actor_identity_id=i.actor_identity_id AND original_batch.playthrough_id=i.playthrough_id
                JOIN native_memory_batch_families family ON family.id=original.batch_id
                JOIN native_memory_latest_batches latest ON latest.root_id=family.root_id
                JOIN native_memory_batches b ON b.id=latest.id AND b.actor_identity_id=i.actor_identity_id AND b.playthrough_id=i.playthrough_id
                JOIN native_memory_effective_summaries current ON current.batch_id=b.id
                LEFT JOIN public.core_memory_summary_revision pinned ON pinned.summary_id=original.id AND pinned.revision=d.summary_revision
                WHERE i.history_event_id = ?
                    AND i.input_payload->'sources'->d.position->>'id'=d.summary_id::text
                    AND i.input_payload->'sources'->d.position->>'revision'=d.summary_revision::text
                    AND i.input_payload->'sources'->d.position->>'gamets'=original_batch.last_gamets::text
                    AND i.input_payload->'sources'->d.position->>'summary'=CASE WHEN d.summary_revision=0 THEN original.summary ELSE pinned.summary END
                    AND d.source_sha256=encode(sha256(convert_to(CASE WHEN d.summary_revision=0 THEN original.summary ELSE pinned.summary END,'UTF8')),'hex')
                ORDER BY d.position",
            [$narrativeId]
        );
    }

    /**
     * The frozen sources of one input that are still effective summaries with the same digest, read through the caller's
     * middleterm history CTE. Throws when the statement fails.
     *
     * @return list<array<string, mixed>>
     */
    public function currentSources(string $cte, int $inputId, int $actorId): array
    {
        return $this->connection()->fetchAllAssociative(
            $cte." SELECT d.position,d.summary_id,d.summary_revision,d.source_sha256,s.summary,b.last_gamets
                FROM public.core_middleterm_source d JOIN native_memory_effective_summaries s ON s.id=d.summary_id
                    AND s.summary_revision=d.summary_revision AND d.source_sha256=encode(sha256(convert_to(s.summary,'UTF8')),'hex')
                JOIN native_memory_batches b ON b.id=s.batch_id AND b.actor_identity_id = :actor
                WHERE d.input_id = :input ORDER BY d.position",
            ['actor' => $actorId, 'input' => $inputId]
        );
    }
}
