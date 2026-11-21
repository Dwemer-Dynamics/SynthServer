<?php

// Reads core_memory_batch. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemoryBatch;

/** Finds rows of core_memory_batch. */
final class MemoryBatchDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractMemoryBatch
    {
        return $this->fresh()->find(AbstractMemoryBatch::class, $id);
    }

    // Native memory batches. $visibleCte is the text of synth_memory_visible_batch_cte() (a WITH list ending in a space); it is only a
    // fragment of fixed SQL built by the manager, every value is bound. Statements throw a Doctrine exception when they fail, and run
    // inside the caller's native request transaction.

    /** Takes the actor's transaction-scoped memory advisory lock; it is held until the caller's open transaction ends. */
    public function lockActorMemory(int|string $actorId): void
    {
        $this->connection()->fetchAssociative("SELECT pg_advisory_xact_lock(hashtextextended('synth-memory:'||?,0))", [$actorId]);
    }

    /** The id and actor of a batch that is fully visible to the current reader, or null. @return array<string, mixed>|null */
    public function visibleBatch(string $visibleCte, int $batchId): ?array
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        $row = $this->connection()->fetchAssociative($visibleCte.'SELECT id,actor_identity_id FROM native_memory_batches WHERE id=?', [$batchId]);

        return $row === false ? null : $row;
    }

    /** True when the batch is fully visible to the current reader. */
    public function isBatchVisible(string $visibleCte, int $batchId): bool
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        return $this->connection()->fetchAssociative($visibleCte.'SELECT id FROM native_memory_batches WHERE id=?', [$batchId]) !== false;
    }

    /** True when the batch belongs to this actor and is fully visible to the current reader. */
    public function isBatchVisibleToActor(string $visibleCte, int $batchId, int $actorId): bool
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        return $this->connection()->fetchAssociative(
            $visibleCte.'SELECT id FROM native_memory_batches WHERE id=? AND actor_identity_id=?',
            [$batchId, $actorId]
        ) !== false;
    }

    /** The input payload, packed text and input hash of a frozen batch, or null. @return array<string, mixed>|null */
    public function claimedInput(int $batchId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT input_payload,packed_message,
            encode(sha256(convert_to(input_payload::text,'UTF8')),'hex') AS input_sha256
            FROM public.core_memory_batch WHERE id=?",
            [$batchId]
        );

        return $row === false ? null : $row;
    }

    /** The oldest visible batch with no summary and no live, ready or (for this request) failed job, or null. */
    public function nextUnscheduledBatchId(string $visibleCte, string $sessionId, string $requestId): ?int
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        $id = $this->connection()->fetchOne(
            $visibleCte."SELECT b.id FROM native_memory_batches b
                    WHERE NOT EXISTS (SELECT 1 FROM public.core_memory_summary s WHERE s.batch_id=b.id)
                    AND NOT EXISTS (SELECT 1 FROM public.core_worker_job j WHERE j.memory_batch_id=b.id
                        AND j.job_type='memory' AND (j.state='ready'
                            OR (j.state IN ('pending','running') AND j.deadline_at>clock_timestamp()
                                AND NOT (j.attempts>=3 AND j.lease_until<=clock_timestamp()))
                            OR (j.state='failed' AND j.session_id=:session
                                AND j.request_id=:request)))
                    ORDER BY b.first_gamets,b.id LIMIT 1",
            ['session' => $sessionId, 'request' => $requestId]
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * The next descendant batch whose frozen prior summary is stale (batch columns plus root_id, previous_id, previous_text and
     * summary_revision), or null. @return array<string, mixed>|null
     */
    public function nextRebuildSource(string $visibleCte): ?array
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        $row = $this->connection()->fetchAssociative($visibleCte."
            SELECT b.*,latest.root_id,parent.id AS previous_id,parent.summary AS previous_text,parent.summary_revision
            FROM native_memory_evidence_batches b JOIN native_memory_latest_batches latest ON latest.id=b.id
            JOIN public.core_memory_batch_summary_source edge ON edge.batch_id=b.id
            JOIN public.core_memory_summary frozen ON frozen.id=edge.summary_id
            JOIN native_memory_batch_families parent_family ON parent_family.id=frozen.batch_id
            JOIN native_memory_latest_batches parent_latest ON parent_latest.root_id=parent_family.root_id
            JOIN native_memory_batches parent_batch ON parent_batch.id=parent_latest.id
            JOIN native_memory_effective_summaries parent ON parent.batch_id=parent_batch.id
            LEFT JOIN public.core_memory_batch_control control ON control.batch_id=latest.root_id
            WHERE NOT COALESCE(control.archived,FALSE) AND parent_batch.last_gamets<b.first_gamets
                AND NOT EXISTS (SELECT 1 FROM native_memory_batches active WHERE active.id=b.id)
                AND (control.regenerate_batch_id=b.id OR NOT EXISTS (
                    SELECT 1 FROM public.core_memory_summary summary
                    JOIN public.core_memory_summary_revision correction ON correction.summary_id=summary.id
                    WHERE summary.batch_id=b.id))
            ORDER BY b.first_gamets,b.id LIMIT 1");

        return $row === false ? null : $row;
    }

    /**
     * The next complete scene group of visible, settled, unsummarized history events (history_event_id, type, data, gamets,
     * source_location), at most 150 rows in chronological order. Scene groups are split by location and by $intervalTicks time buckets.
     *
     * @return list<array<string, mixed>>
     */
    public function uncoveredMemoryEvents(string $visibleCte, int $intervalTicks, int $settledCutoff, int $minimumEvents): array
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        return $this->connection()->fetchAllAssociative($visibleCte.", memory_candidates AS (
            SELECT *,lower(btrim(regexp_replace(COALESCE(source_location,''),'\\s+',' ','g'))) AS scene_location,
                round(gamets::numeric/CAST(:interval AS numeric),0) AS time_bucket
            FROM native_history WHERE gamets>0 AND gamets<CAST(:cutoff AS bigint)
                AND type IN ('inputtext','inputtext_s','chat','backgroundchat','narration',
                    'conversation_start','conversation_end','infoaction','funcret','death','combatend',
                    'combatbark','itemfound','itemtransfer')
                AND COALESCE(delivery_state,'spoken') IN ('emitted','spoken','presented')
                AND data NOT ILIKE '%#MEMORY:%'
        ), memory_boundaries AS (
            SELECT *,CASE WHEN scene_location='' OR
                scene_location IS DISTINCT FROM lag(scene_location) OVER chronology OR
                time_bucket IS DISTINCT FROM lag(time_bucket) OVER chronology THEN 1 ELSE 0 END AS boundary
            FROM memory_candidates WINDOW chronology AS (ORDER BY gamets,ts,history_event_id)
        ), memory_groups AS (
            SELECT *,sum(boundary) OVER (ORDER BY gamets,ts,history_event_id) AS scene_group
            FROM memory_boundaries
        ), memory_uncovered AS (
            SELECT g.* FROM memory_groups g WHERE NOT EXISTS (
                SELECT 1 FROM native_memory_evidence_batches b JOIN public.core_memory_batch_source d ON d.batch_id=b.id
                WHERE d.history_event_id=g.history_event_id)
        ), memory_next_group AS (
            SELECT scene_group FROM memory_uncovered GROUP BY scene_group
            HAVING count(*)>=CAST(:minimum AS integer) ORDER BY scene_group LIMIT 1
        ) SELECT history_event_id,type,data,gamets,source_location FROM memory_uncovered
            WHERE scene_group=(SELECT scene_group FROM memory_next_group)
            ORDER BY gamets ASC,ts ASC,history_event_id ASC LIMIT 150", ['interval' => $intervalTicks, 'cutoff' => $settledCutoff, 'minimum' => $minimumEvents]);
    }

    /** The latest visible summary of a batch that ends strictly before $firstGamets (id, summary, summary_revision), or null. @return array<string, mixed>|null */
    public function priorSummary(string $visibleCte, int|string $firstGamets): ?array
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        $row = $this->connection()->fetchAssociative(
            $visibleCte.'SELECT s.id,s.summary,s.summary_revision FROM native_memory_batches b JOIN native_memory_effective_summaries s ON s.batch_id=b.id
              WHERE b.last_gamets<? ORDER BY b.last_gamets DESC,s.id DESC LIMIT 1',
            [$firstGamets]
        );

        return $row === false ? null : $row;
    }

    /**
     * Freezes a batch input unless the actor already froze identical input (same source hash); the new id, or null when it already
     * existed.
     */
    public function insertFrozenBatch(int|string $actorId, string $playthroughId, string $sessionId, int|string $generation, int|string $contextSequence,
        string $sourceHash, string $inputJson, string $packedMessage, int|string $firstGamets, int|string $lastGamets): ?int
    {
        $id = $this->connection()->fetchOne(
            'INSERT INTO public.core_memory_batch (actor_identity_id,playthrough_id,session_id,generation,
                context_sequence,source_hash,input_payload,packed_message,first_gamets,last_gamets)
             VALUES (?,?,?,?,?,?,CAST(? AS jsonb),?,?,?)
             ON CONFLICT (actor_identity_id,source_hash) DO NOTHING RETURNING id',
            [$actorId, $playthroughId, $sessionId, $generation, $contextSequence, $sourceHash, $inputJson, $packedMessage, $firstGamets, $lastGamets]
        );

        return $id === false ? null : (int) $id;
    }

    /** Freezes a rebuilt batch input (no conflict clause); the new id. */
    public function insertRebuiltBatch(int|string $actorId, string $playthroughId, string $sessionId, int|string $generation, int|string $contextSequence,
        string $sourceHash, string $inputJson, string $packedMessage, int|string $firstGamets, int|string $lastGamets): int
    {
        return (int) $this->connection()->fetchOne(
            'INSERT INTO public.core_memory_batch
            (actor_identity_id,playthrough_id,session_id,generation,context_sequence,source_hash,input_payload,packed_message,first_gamets,last_gamets)
            VALUES(?,?,?,?,?,?,CAST(? AS jsonb),?,?,?) RETURNING id',
            [$actorId, $playthroughId, $sessionId, $generation, $contextSequence, $sourceHash, $inputJson, $packedMessage, $firstGamets, $lastGamets]
        );
    }

    /** The id of the actor's batch with this source hash, or null. */
    public function findBatchIdBySourceHash(int|string $actorId, string $sourceHash): ?int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM public.core_memory_batch WHERE actor_identity_id=? AND source_hash=?', [$actorId, $sourceHash]);

        return $id === false ? null : (int) $id;
    }

    /** Records which history events a new batch froze, in the order of the frozen input's events. */
    public function freezeSourcesFromInput(int|string $batchId, string $inputJson): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_memory_batch_source (batch_id,history_event_id,position)
             SELECT ?,(e.event->>\'history_event_id\')::bigint,e.ordinality
             FROM jsonb_array_elements(CAST(? AS jsonb)->\'events\') WITH ORDINALITY e(event,ordinality)',
            [$batchId, $inputJson]
        );
    }

    /** Copies another batch's frozen history sources onto a rebuilt batch. */
    public function copySources(int|string $batchId, int|string $fromBatchId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_memory_batch_source(batch_id,history_event_id,position)
            SELECT ?,history_event_id,position FROM public.core_memory_batch_source WHERE batch_id=?',
            [$batchId, $fromBatchId]
        );
    }

    /** Records the prior summary revision a batch froze. */
    public function freezeSummarySource(int|string $batchId, int|string $summaryId, int|string $summaryRevision): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_memory_batch_summary_source(batch_id,summary_id,summary_revision) VALUES(?,?,?)',
            [$batchId, $summaryId, $summaryRevision]
        );
    }

    /** Records that a batch rebuilds another (root) batch. */
    public function freezeRebuildLink(int|string $batchId, int|string $rootBatchId): void
    {
        $this->connection()->executeStatement('INSERT INTO public.core_memory_batch_rebuild(batch_id,root_batch_id) VALUES(?,?)', [$batchId, $rootBatchId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryBatch> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryBatch::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
