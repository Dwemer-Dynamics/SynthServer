<?php

// Reads core_relationship_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipResult;

/** Finds rows of core_relationship_result. */
final class RelationshipResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipResult
    {
        return $this->fresh()->find(AbstractRelationshipResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /** True when this result belongs to an evaluation of this actor in exactly this request, context sequence and generation. */
    public function ownedByEvaluation(int $historyEventId, string $sessionId, string $requestId, int $actorId, int $contextSequence, int $generation): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_relationship_result r JOIN public.core_relationship_evaluation i ON i.id=r.evaluation_id
                WHERE r.history_event_id=:p1 AND i.session_id=:p2 AND i.request_id=:p3 AND i.actor_identity_id=:p4 AND i.context_sequence=:p5 AND i.generation=:p6',
            ['p1' => $historyEventId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $actorId, 'p5' => $contextSequence, 'p6' => $generation]
        ) !== false;
    }

    /** The evaluation this result history event answers (as text), or null. */
    public function evaluationIdOf(int $historyEventId): ?string
    {
        $id = $this->connection()->fetchOne(
            'SELECT evaluation_id FROM public.core_relationship_result WHERE history_event_id=:p1',
            ['p1' => $historyEventId]
        );

        return $id === false ? null : (string) $id;
    }

    /** The history event of the result this job already stored with exactly this payload, as text, or null. */
    public function replayFor(int $jobId, string $resultPayload): ?string
    {
        $id = $this->connection()->fetchOne(
            'SELECT history_event_id FROM public.core_relationship_result WHERE job_id=:p1 AND result_payload=:p2::jsonb',
            ['p1' => $jobId, 'p2' => $resultPayload]
        );

        return $id === false ? null : (string) $id;
    }

    /** True when any evaluation of this response already has a stored result (every save, current or restored). */
    public function existsForResponse(int|string $responseHistoryEventId): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_relationship_result result
                JOIN public.core_relationship_evaluation prior ON prior.id=result.evaluation_id
                WHERE prior.response_history_event_id=:p1 LIMIT 1',
            ['p1' => $responseHistoryEventId]
        ) !== false;
    }

    /** True when a result of this response for this actor was stored after the context snapshot, so the evaluation is stale. */
    public function existsAfterContext(string $sessionId, int $generation, int|string $responseHistoryEventId, int|string $actorId, int $contextSequence): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_relationship_result r JOIN public.core_relationship_evaluation i ON i.id=r.evaluation_id
            WHERE i.session_id=:p1 AND i.generation=:p2 AND i.response_history_event_id=:p3 AND i.actor_identity_id=:p4 AND r.history_event_id>(
                SELECT history_event_id FROM public.core_runtime_context WHERE session_id=:p1 AND generation=:p2 AND snapshot_sequence=:p5) LIMIT 1',
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $responseHistoryEventId, 'p4' => $actorId, 'p5' => $contextSequence]
        ) !== false;
    }

    /** Publishes an evaluation result with its derived history event. Returns the new history event id. */
    public function publish(string $playthroughId, int $gameTime, int $now, string $sessionId, int $generation, int $contextSequence,
        string $requestId, string $evaluationSha256, string $resultPayload, int|string $evaluationId, int $jobId): int
    {
        return (int) $this->connection()->fetchOne(
            "WITH publication AS (
            INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('relationship_result','Native relationship evaluation',:p1,:p2,:p3,:p3,'','','','derived',:p4,:p5,:p6,:p7) RETURNING *
        ), history AS (
            INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:p1,rowid,to_jsonb(publication)
                ||jsonb_build_object('evaluation_sha256',:p8::text,'result_sha256',encode(sha256(convert_to((:p9::jsonb)::text,'UTF8')),'hex')) FROM publication RETURNING id
        ) INSERT INTO public.core_relationship_result(history_event_id,evaluation_id,job_id,result_payload)
            SELECT id,:p10,:p11,:p9::jsonb FROM history RETURNING history_event_id",
            ['p1' => $playthroughId, 'p2' => $gameTime, 'p3' => $now, 'p4' => $sessionId, 'p5' => $generation, 'p6' => $contextSequence,
                'p7' => $requestId, 'p8' => $evaluationSha256, 'p9' => $resultPayload, 'p10' => $evaluationId, 'p11' => $jobId]
        );
    }

    /** Serializes result publication for one actor and response until the caller's transaction ends. */
    public function lockResponse(int|string $actorId, int|string $responseHistoryEventId): void
    {
        $this->connection()->executeQuery(
            "SELECT pg_advisory_xact_lock(hashtextextended('synth-relationship-result:'||:p1||':'||:p2,0))",
            ['p1' => $actorId, 'p2' => $responseHistoryEventId]
        );
    }
}
