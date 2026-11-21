<?php

// Reads core_request_execution. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRequestExecution;

/** Finds rows of core_request_execution. */
class RequestExecutionDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractRequestExecution
    {
        return $this->fresh()->find(AbstractRequestExecution::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /**
     * The durable owner state of one request: its execution state and error code and the session's runtime state, active
     * generation, active turn id and active turn sequence; null when the request has no row for this generation.
     *
     * @return array<string, mixed>|null
     */
    public function ownerState(string $sessionId, string $requestId, int $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT e.state, e.error_code, r.state AS runtime_state, r.active_generation, r.active_turn_id, r.active_turn_sequence
             FROM public.core_request_execution e JOIN public.core_runtime_session r USING (session_id)
             WHERE e.session_id = ? AND e.request_id = ? AND e.generation = ?',
            [$sessionId, $requestId, $generation]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRequestExecution> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRequestExecution::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /**
     * Explicit player cancel of a whole conversation inside the cancel event's claim transaction: every in-flight or
     * completed-undelivered request of the conversation in this session generation is cancelled (completed player input
     * stays as history), its worker jobs are cancelled and the session's active turn is cleared when both its id and its
     * context sequence belong to a cancelled request. Three statements on the caller's session, in that order. Returns the
     * cancelled request ids; a failed statement is skipped, as the legacy statements were.
     *
     * @return list<string>
     */
    public function cancelConversation(string $sessionId, int $generation, string $ownRequestId, string $conversationId, string $capability): array
    {
        $members = "SELECT request_id FROM public.core_protocol_event WHERE session_id=:p1
            AND generation=:p2 AND request_id<>:p3
            AND event_type IN ('input_text','input_audio','trigger')
            AND jsonb_exists(canonical_event->'capabilities', :p4)
            AND canonical_event#>>'{payload,conversation_id}'=:p5";
        $scope = [$sessionId, $generation, $ownRequestId, $capability, $conversationId];
        $cancelled = $this->rowsOrNull("UPDATE public.core_request_execution SET
                state=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete' ELSE 'cancelled' END,
                response_body=NULL,error_code='request_cancelled',
                completed_at=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN completed_at ELSE NOW() END
            WHERE session_id=:p1 AND generation=:p2 AND state IN ('claimed','streaming','complete')
                AND request_id IN ({$members}) RETURNING request_id,turn_id", $scope) ?? [];
        // A failed statement is skipped, as the legacy statements were (their failures were logged and ignored).
        $this->statementOk("UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
            WHERE session_id=:p1 AND generation=:p2 AND state IN ('pending','running')
                AND request_id IN ({$members})", $scope);
        $ids = array_column($cancelled, 'request_id');
        if ($ids !== []) {
            $literal = '{'.implode(',', array_map(static fn($id) => '"'.addcslashes(strval($id), '"\\').'"', $ids)).'}';
            $this->statementOk("UPDATE public.core_runtime_session SET active_turn_id=NULL, updated_at=NOW()
                WHERE session_id=:p1 AND active_generation=:p2
                    AND (active_turn_id, active_turn_sequence) IN (
                        SELECT turn_id, (canonical_event->>'context_sequence')::bigint FROM public.core_protocol_event
                        WHERE session_id=:p1 AND generation=:p2 AND request_id = ANY(CAST(:p3 AS text[])))",
                [$sessionId, $generation, $literal]);
        }

        return $ids;
    }

    /**
     * One completed ancestor request with its stored response and the runtime context it ran in, for the exact turn owner.
     * Parameters, in order: session id, request id, context sequence, generation, turn id, runtime variant. [] when it is
     * not available, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function completedResponse(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT p.canonical_event,p.event_sha256,p.event_type,e.response_body,c.context_payload
        FROM public.core_request_execution e JOIN public.core_protocol_event p USING(session_id,request_id)
        JOIN public.core_runtime_context c ON c.session_id=e.session_id AND c.generation=e.generation AND c.snapshot_sequence=:p3
        JOIN public.core_runtime_session s ON s.session_id=e.session_id AND s.playthrough_id=c.playthrough_id
        WHERE e.session_id=:p1 AND e.request_id=:p2 AND e.state='complete' AND e.generation=:p4 AND p.generation=e.generation
            AND e.turn_id=:p5 AND p.turn_id=e.turn_id AND e.event_type=p.event_type AND e.request_sha256=p.event_sha256
            AND s.runtime_variant=:p6 AND octet_length(e.response_body)<=2097152", $parameters);
    }

    /**
     * The runtime context of a still-running request whose canonical event and hash match, for a dialogue route owner check.
     * Parameters, in order: session id, request id, context sequence, canonical event JSON, event sha256, generation, turn
     * id, runtime variant. [] when it is not available, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function runningContext(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT c.context_payload
            FROM public.core_request_execution e
            JOIN public.core_protocol_event p USING(session_id,request_id)
            JOIN public.core_runtime_context c ON c.session_id=e.session_id AND c.generation=e.generation
                AND c.snapshot_sequence=:p3
            JOIN public.core_runtime_session s ON s.session_id=e.session_id AND s.playthrough_id=c.playthrough_id
            WHERE e.session_id=:p1 AND e.request_id=:p2 AND e.state IN ('claimed','streaming')
                AND p.canonical_event=:p4::jsonb AND p.event_sha256=:p5 AND e.request_sha256=p.event_sha256
                AND e.generation=:p6 AND p.generation=e.generation AND e.turn_id=:p7 AND p.turn_id=e.turn_id
                AND p.event_type=e.event_type AND s.runtime_variant=:p8", $parameters);
    }

    /**
     * Evidence that a rechat parent completed under this child's exact owner: the parent's canonical event, response and
     * context. Parameters, in order: session id, parent request id, context sequence (text), generation, turn id, runtime
     * variant, child canonical event JSON, child request id, child event sha256, 't'|'f' (also accept the child's own failed,
     * unrevoked execution). [] when there is none, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function completedParentEvidence(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT p.canonical_event,p.event_sha256,p.event_type,e.response_body,c.context_payload
        FROM public.core_request_execution e JOIN public.core_protocol_event p USING(session_id,request_id)
        JOIN public.core_runtime_context c ON c.session_id=e.session_id AND c.generation=e.generation AND c.snapshot_sequence=:p3
        JOIN public.core_runtime_session s ON s.session_id=c.session_id AND s.playthrough_id=c.playthrough_id
        JOIN public.core_request_execution child ON child.session_id=e.session_id AND child.request_id=:p8
        JOIN public.core_protocol_event cp ON cp.session_id=child.session_id AND cp.request_id=child.request_id
        WHERE e.session_id=:p1 AND e.request_id=:p2 AND e.state='complete'
            AND e.generation=:p4 AND e.turn_id=:p5 AND p.generation=e.generation AND p.turn_id=e.turn_id
            AND octet_length(e.response_body)<=2097152
            AND p.event_sha256=e.request_sha256 AND p.event_type=e.event_type
            AND p.canonical_event->>'protocol_version'='2' AND p.canonical_event->>'context_sequence'=:p3::text
            AND p.canonical_event->>'runtime_variant'=:p6 AND s.runtime_variant=:p6
            AND (child.state IN ('claimed','streaming','complete') OR (:p10::boolean AND child.state='failed'
                AND COALESCE(child.error_code,'') NOT IN ('turn_cancelled','request_cancelled','superseded_turn')))
            AND child.generation=:p4 AND child.turn_id=:p5
            AND cp.canonical_event=:p7::jsonb AND cp.event_sha256=:p9 AND child.request_sha256=cp.event_sha256
            AND cp.generation=child.generation AND cp.turn_id=child.turn_id AND cp.event_type=child.event_type", $parameters);
    }
}
