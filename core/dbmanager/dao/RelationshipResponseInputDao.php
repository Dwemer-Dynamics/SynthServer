<?php

// Reads core_relationship_response_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipResponseInput;

/** Finds rows of core_relationship_response_input. */
final class RelationshipResponseInputDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipResponseInput
    {
        return $this->fresh()->find(AbstractRelationshipResponseInput::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipResponseInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipResponseInput::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /** The ids among $historyEventIds that are frozen relationship responses. @param list<int> $historyEventIds @return list<int> */
    public function responseIdsAmong(array $historyEventIds): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT history_event_id FROM public.core_relationship_response_input WHERE history_event_id = ANY(:p1::bigint[])',
            ['p1' => '{'.implode(',', array_map('intval', $historyEventIds)).'}']
        );

        return array_map('intval', $ids);
    }

    /** True once this request has frozen a relationship response. */
    public function existsForRequest(string $sessionId, string $requestId): bool
    {
        return $this->connection()->fetchAssociative(
            'SELECT history_event_id FROM public.core_relationship_response_input WHERE session_id=:p1 AND request_id=:p2',
            ['p1' => $sessionId, 'p2' => $requestId]
        ) !== false;
    }

    /** Publishes a derived history event and freezes the response behind it. Returns the new history event id. */
    public function publish(string $playthroughId, int|string $gameTime, int $now, string $sessionId, int $generation, int $contextSequence,
        string $requestId, int $actorIdentityId, string $responseSha256, string $responsePayload): int
    {
        return (int) $this->connection()->fetchOne(
            "WITH publication AS (
                INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                    synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                VALUES('relationship_response','Accepted native model speech',:p1,:p2,:p3,:p3,'','','','derived',:p4,:p5,:p6,:p7) RETURNING *
            ), history AS (
                INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:p1,rowid,to_jsonb(publication)
                    ||jsonb_build_object('response_sha256',:p9::text,'response_payload_sha256',
                        encode(sha256(convert_to((:p10::jsonb)::text,'UTF8')),'hex'))
                    FROM publication RETURNING id
            ) INSERT INTO public.core_relationship_response_input
                (history_event_id,session_id,request_id,actor_identity_id,response_sha256,response_payload)
                SELECT id,:p4,:p7,:p8,:p9,:p10::jsonb FROM history RETURNING history_event_id",
            ['p1' => $playthroughId, 'p2' => $gameTime, 'p3' => $now, 'p4' => $sessionId, 'p5' => $generation, 'p6' => $contextSequence,
                'p7' => $requestId, 'p8' => $actorIdentityId, 'p9' => $responseSha256, 'p10' => $responsePayload]
        );
    }

    /**
     * The owner request, the response's own request event and the response payload for a completed request whose canonical event
     * is exactly $canonicalEvent and whose response is $responseId; null when any of them does not match.
     *
     * @return array{owner_event_id: string, response_event_id: string, response_payload: string}|null
     */
    public function interactionOwner(string $sessionId, string $requestId, string $canonicalEvent, string $eventSha256, int $responseId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT p.id AS owner_event_id,origin.id AS response_event_id,i.response_payload
            FROM public.core_protocol_event p JOIN public.core_request_execution e USING(session_id,request_id)
            CROSS JOIN public.core_relationship_response_input i
            JOIN public.core_protocol_event origin ON origin.session_id=i.session_id AND origin.request_id=i.request_id
            WHERE p.session_id=:p1 AND p.request_id=:p2 AND p.canonical_event=:p3::jsonb AND p.event_sha256=:p4
                AND e.request_sha256=p.event_sha256 AND e.state='complete' AND i.history_event_id=:p5
                AND i.session_id=p.session_id AND i.request_id=p.request_id",
            ['p1' => $sessionId, 'p2' => $requestId, 'p3' => $canonicalEvent, 'p4' => $eventSha256, 'p5' => $responseId]
        );

        return self::textRow($row);
    }

    /**
     * The oldest completed, verified gift action whose dedicated evaluation has not run (or whose job is retryable) and that no
     * actor control blocks. Null when none is waiting.
     *
     * @return array<string, string|null>|null
     */
    public function pendingGift(): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.id AS evaluation_id,j.id AS job_id,response.history_event_id AS response_id,
                e.session_id,e.request_id,e.turn_id,e.generation,e.event_type,h.context_sequence,
                p.canonical_event,p.event_sha256,TRUE AS retained_gift
            FROM public.core_relationship_response_input response
            JOIN public.core_history_event h ON h.id=response.history_event_id
            JOIN public.core_request_execution e ON e.session_id=response.session_id AND e.request_id=response.request_id
                AND e.generation=h.generation AND e.event_type='action_result' AND e.state='complete' AND e.error_code IS NULL
            JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_sha256=e.request_sha256
                AND p.canonical_event=response.response_payload->'request'
            JOIN public.core_runtime_session r ON r.session_id=e.session_id AND r.active_generation=e.generation
                AND r.state='active' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
            JOIN public.core_runtime_context c ON c.session_id=h.session_id AND c.generation=h.generation
                AND c.snapshot_sequence=h.context_sequence AND c.playthrough_id=r.playthrough_id
                AND c.target_identity_id=response.actor_identity_id
            LEFT JOIN public.core_relationship_evaluation i ON i.response_history_event_id=response.history_event_id
                AND i.session_id=e.session_id AND i.request_id=e.request_id AND i.generation=e.generation
            LEFT JOIN public.core_worker_job j ON j.relationship_evaluation_id=i.id
            WHERE response.response_payload->>'verified_gift_action_id'=p.canonical_event#>>'{payload,result,action_id}'
                AND response.response_payload->>'evaluation_mode'='dedicated'
                AND NOT EXISTS(SELECT 1 FROM public.core_relationship_evaluation earlier
                    WHERE earlier.actor_identity_id=response.actor_identity_id AND earlier.response_history_event_id<>response.history_event_id
                        AND earlier.input_payload#>>'{received_gift,fact,action_id}'=response.response_payload->>'verified_gift_action_id')
                AND h.recorded_at>clock_timestamp()-INTERVAL '10 minutes'
                AND NOT EXISTS(SELECT 1 FROM public.core_relationship_actor_control control
                    WHERE control.actor_identity_id=response.actor_identity_id AND (control.locked OR p.id<=control.barrier_event_id))
                AND (j.id IS NULL OR (j.payload->>'schema'='synth.relationship.gift-job.v1'
                    AND j.available_at<=clock_timestamp() AND j.deadline_at>clock_timestamp() AND j.attempts<3
                    AND (j.state='pending' OR (j.state='running' AND j.lease_until<=clock_timestamp()))))
            ORDER BY response.history_event_id LIMIT 1"
        );

        return self::textRow($row);
    }
}
