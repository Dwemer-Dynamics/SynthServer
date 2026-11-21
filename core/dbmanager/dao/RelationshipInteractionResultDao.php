<?php

// Reads core_relationship_interaction_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionResult;

/** Finds rows of core_relationship_interaction_result. */
final class RelationshipInteractionResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipInteractionResult
    {
        return $this->fresh()->find(AbstractRelationshipInteractionResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipInteractionResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipInteractionResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /** The history event of the interaction result this job already stored for exactly this payload and input digest, or null. */
    public function replayFor(int $jobId, int|string $interactionId, string $resultPayload, string $sessionId, string $requestId,
        int $generation, int $contextSequence, string $evaluationSha256): ?string
    {
        $id = $this->connection()->fetchOne(
            "SELECT r.history_event_id FROM public.core_relationship_interaction_result r
                JOIN public.core_history_event h ON h.id=r.history_event_id AND h.session_id=:p4 AND h.request_id=:p5
                    AND h.generation=:p6 AND h.context_sequence=:p7
                WHERE r.job_id=:p1 AND r.interaction_id=:p2 AND r.result_payload=:p3::jsonb
                    AND h.event_payload->>'type'='relationship_interaction_result'
                    AND h.event_payload->>'evaluation_sha256'=:p8
                    AND h.event_payload->>'result_sha256'=encode(sha256(convert_to(r.result_payload::text,'UTF8')),'hex')",
            ['p1' => $jobId, 'p2' => $interactionId, 'p3' => $resultPayload, 'p4' => $sessionId, 'p5' => $requestId,
                'p6' => $generation, 'p7' => $contextSequence, 'p8' => $evaluationSha256]
        );

        return $id === false ? null : (string) $id;
    }

    /** Publishes an interaction result with its derived history event. Returns the new history event id. */
    public function publish(string $playthroughId, int $gameTime, int $now, string $sessionId, int $generation, int $contextSequence,
        string $requestId, string $evaluationSha256, string $resultPayload, int|string $interactionId, int $jobId): int
    {
        return (int) $this->connection()->fetchOne(
            "WITH publication AS (
            INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('relationship_interaction_result','Native NPC interaction evaluation',:p1,:p2,:p3,:p3,'','','','derived',:p4,:p5,:p6,:p7) RETURNING *
        ), history AS (
            INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:p1,rowid,to_jsonb(publication)
                ||jsonb_build_object('evaluation_sha256',:p8::text,'result_sha256',encode(sha256(convert_to((:p9::jsonb)::text,'UTF8')),'hex'))
            FROM publication RETURNING id
        ) INSERT INTO public.core_relationship_interaction_result(history_event_id,interaction_id,job_id,result_payload)
            SELECT id,:p10,:p11,:p9::jsonb FROM history RETURNING history_event_id",
            ['p1' => $playthroughId, 'p2' => $gameTime, 'p3' => $now, 'p4' => $sessionId, 'p5' => $generation, 'p6' => $contextSequence,
                'p7' => $requestId, 'p8' => $evaluationSha256, 'p9' => $resultPayload, 'p10' => $interactionId, 'p11' => $jobId]
        );
    }
}
