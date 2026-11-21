<?php

// Reads core_request_execution. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRequestExecution;

/** Finds rows of core_request_execution. */
class RequestExecutionDao
{
    use FailSoftQueries;
    use UsesEntityManager;
    use TextRows;

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

    /**
     * Records the first terminal outcome of a live claim (state claimed or streaming) as the replayable response; later calls
     * change nothing. $body is the raw response bytes, an empty $errorCode stores NULL. True when the statement ran.
     */
    public function recordOutcome(string $sessionId, string $requestId, string $state, string $contentType, string $body, string $errorCode): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_request_execution
                    SET state = ?, response_content_type = ?, response_body = decode(?, 'hex'), error_code = ?, completed_at = NOW()
                  WHERE session_id = ? AND request_id = ? AND state IN ('claimed','streaming')",
                [$state, $contentType, bin2hex($body), $errorCode === '' ? null : $errorCode, $sessionId, $requestId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * Takes the session's transaction-scoped advisory lock (shared by claim, cancel, supersession and outcome); it is held until
     * the caller's open transaction ends. False when the statement fails.
     */
    public function lockSessionForOutcome(string $sessionId): bool
    {
        try {
            return $this->connection()->fetchAssociative('SELECT pg_advisory_xact_lock(hashtext(?))', [$sessionId]) !== false;
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }
    }

    /** The state and error code of one request's row, locked FOR UPDATE until the caller's open transaction ends; null when there is no row. @return array<string, mixed>|null */
    public function lockOutcomeRow(string $sessionId, string $requestId, int $generation): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT state, error_code FROM public.core_request_execution WHERE session_id = ? AND request_id = ? AND generation = ? FOR UPDATE',
                [$sessionId, $requestId, $generation]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * Writes the outcome of a request still claimed or streaming and returns the state stored, or null when no live row matched.
     * $bodyHex is the hex of the response bytes, or null for no replay body; an empty $errorCode stores NULL.
     */
    public function finishOutcome(string $sessionId, string $requestId, int $generation, string $state, string $contentType, ?string $bodyHex, string $errorCode): ?string
    {
        try {
            $stored = $this->connection()->fetchOne(
                "UPDATE public.core_request_execution
                    SET state = ?, response_content_type = ?, response_body = decode(CAST(? AS text), 'hex'), error_code = ?, completed_at = NOW()
                  WHERE session_id = ? AND request_id = ? AND generation = ? AND state IN ('claimed','streaming') RETURNING state",
                [$state, $contentType, $bodyHex, $errorCode === '' ? null : $errorCode, $sessionId, $requestId, $generation]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $stored === false ? null : (string) $stored;
    }

    /**
     * Takes the session's transaction-scoped advisory lock that orders claim, cancel, supersession and outcome; held until the
     * caller's open transaction ends. False when the statement fails.
     */
    public function lockSession(string $sessionId): bool
    {
        try {
            return $this->connection()->fetchAssociative('SELECT pg_advisory_xact_lock(hashtext(?))', [$sessionId]) !== false;
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }
    }

    /** The existing row of a request locked FOR UPDATE (hash, state, stored response and claim time), or null. @return array<string, mixed>|null */
    public function lockForClaim(string $sessionId, string $requestId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT request_sha256, state, response_content_type, response_body, error_code, claimed_at
               FROM public.core_request_execution WHERE session_id = ? AND request_id = ? FOR UPDATE',
            [$sessionId, $requestId]
        );

        return $row === false ? null : $row;
    }

    /** Inserts the claim of a request. */
    public function insertClaim(string $sessionId, string $requestId, string $turnId, int $generation, string $requestSha256, string $eventType): bool
    {
        return $this->tryStatement(
            "INSERT INTO public.core_request_execution (session_id, request_id, turn_id, generation, request_sha256, event_type, state)
             VALUES (?, ?, ?, ?, ?, ?, 'claimed')",
            [$sessionId, $requestId, $turnId, $generation, $requestSha256, $eventType]
        );
    }

    /** Cancels live requests of older generations (a new init supersedes them). */
    public function cancelOlderGenerations(string $sessionId, int $generation): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_request_execution SET state='cancelled', response_body=NULL, error_code='stale_generation', completed_at=NOW()
              WHERE session_id = ? AND generation < ? AND state IN ('claimed','streaming')",
            [$sessionId, $generation]
        );
    }

    /** Cancels live turn-owned requests that a new context sequence supersedes, even when its turn id is reused. */
    public function cancelSupersededTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_request_execution SET state='cancelled',
                    response_body=NULL, error_code='superseded_turn', completed_at=NOW()
                WHERE session_id = ? AND generation = ?
                  AND (turn_id <> ? OR EXISTS (
                    SELECT 1 FROM public.core_protocol_event owner
                    WHERE owner.session_id = ? AND owner.generation = ?
                      AND owner.request_id=core_request_execution.request_id
                      AND owner.event_sha256=core_request_execution.request_sha256
                      AND owner.canonical_event->>'context_sequence' IS DISTINCT FROM ?))
                  AND (event_type IN ('input_text','input_audio','trigger','action_result','narrator_turn')
                    OR (event_type='social_task_progress' AND request_id IN (
                        SELECT p.request_id FROM public.core_protocol_event p
                        WHERE p.session_id = ? AND p.generation = ?
                            AND p.event_type='social_task_progress'
                            AND p.canonical_event->'payload'->>'phase'='arrived'
                            AND p.event_sha256=core_request_execution.request_sha256)))
                  AND state IN ('claimed','streaming')",
            [$sessionId, $generation, $turnId, $sessionId, $generation, (string) $sequence, $sessionId, $generation]
        );
    }

    /** Cancels every live request of the session up to this generation except the halting request itself. */
    public function cancelForHalt(string $sessionId, int $generation, string $haltRequestId): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_request_execution SET state='cancelled', response_body=NULL, error_code='runtime_halted', completed_at=NOW()
              WHERE session_id = ? AND generation <= ? AND request_id <> ? AND state IN ('claimed','streaming')",
            [$sessionId, $generation, $haltRequestId]
        );
    }

    /** True when a context event with this snapshot sequence already has a cancelled request (a cancel committed first). */
    public function hasCancelledContext(string $sessionId, int $generation, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 AS cancelled FROM public.core_protocol_event p
                JOIN public.core_request_execution e USING (session_id, request_id)
                WHERE p.session_id = ? AND p.generation = ? AND p.event_type='context'
                  AND p.canonical_event->'payload'->>'snapshot_sequence' = ?
                  AND e.state='cancelled' LIMIT 1",
            [$sessionId, $generation, (string) $sequence]
        ) !== false;
    }

    /**
     * Cancels a turn the player cancelled: the cancelled request and every request of that turn and context sequence. Completed
     * player input is kept as history (state stays complete), but its delivery is revoked.
     */
    public function cancelTurn(string $sessionId, int $generation, string $cancelRequestId, string $cancelTurnId, int $cancelSequence): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_request_execution SET
                    state=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete' ELSE 'cancelled' END,
                    response_body=NULL,error_code='turn_cancelled',
                    completed_at=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN completed_at ELSE NOW() END
                WHERE session_id = ? AND generation = ?
                  AND state IN ('claimed','streaming','complete')
                  AND (request_id = ? OR request_id IN (
                    SELECT request_id FROM public.core_protocol_event WHERE session_id = ?
                      AND generation = ? AND turn_id = ?
                      AND canonical_event->>'context_sequence' = ?))",
            [$sessionId, $generation, $cancelRequestId, $sessionId, $generation, $cancelTurnId, (string) $cancelSequence]
        );
    }

    /** Cancels one request (completed player input keeps its complete state). */
    public function cancelRequest(string $sessionId, int $generation, string $cancelRequestId): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_request_execution SET
                    state=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete' ELSE 'cancelled' END,
                    response_body=NULL,error_code='request_cancelled',
                    completed_at=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN completed_at ELSE NOW() END
              WHERE session_id = ? AND request_id = ? AND generation = ? AND state IN ('claimed','streaming','complete')",
            [$sessionId, $cancelRequestId, $generation]
        );
    }


    /**
     * True when this request is complete and its stored canonical ingress event equals $canonicalJson with digest $sha256.
     * Throws when the statement fails.
     */
    public function completedIngressMatches(string $sessionId, string $requestId, int $generation, string $canonicalJson, string $sha256): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_request_execution e JOIN public.core_protocol_event p
                USING(session_id,request_id,generation,turn_id,event_type)
             WHERE e.session_id = :session AND e.request_id = :request AND e.generation = :generation AND e.state='complete'
                AND p.event_sha256=e.request_sha256 AND p.event_sha256 = :sha AND p.canonical_event = CAST(:canonical AS jsonb)",
            ['session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'canonical' => $canonicalJson, 'sha' => $sha256]
        ) !== false;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRequestExecution> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRequestExecution::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /**
     * True when this request's immutable ingress is the canonical diary_request event: the execution row, its protocol event
     * (same canonical JSON and hash) and, when $complete, the state complete.
     */
    public function diaryOwnerVerified(string $sessionId, string $requestId, int $generation, string $canonicalEvent, string $eventSha256, bool $complete): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_request_execution e JOIN public.core_protocol_event p
                ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.generation=e.generation AND p.turn_id=e.turn_id
                    AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
             WHERE e.session_id=? AND e.request_id=? AND e.generation=? AND e.event_type='diary_request'
                AND p.canonical_event=CAST(? AS jsonb) AND p.event_sha256=? AND (NOT CAST(? AS boolean) OR e.state='complete')",
            [$sessionId, $requestId, $generation, $canonicalEvent, $eventSha256, $complete ? 't' : 'f']
        ) !== false;
    }

    /** The stored response body (bytea as the driver returns it) of one request; null when there is no row. @return array<string, mixed>|null */
    public function responseBody(string $sessionId, string $requestId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT response_body FROM public.core_request_execution WHERE session_id=? AND request_id=?',
            [$sessionId, $requestId]
        );

        return $row === false ? null : $row;
    }

    /** Completes a still claimed request with its replayable NDJSON body; true when exactly that one row changed. */
    public function completeDiaryAdmission(string $sessionId, string $requestId, string $body): bool
    {
        try {
            $changed = $this->connection()->executeStatement(
                "UPDATE public.core_request_execution SET state='complete',
                    response_content_type='application/x-ndjson',response_body=convert_to(CAST(? AS text),'UTF8'),completed_at=clock_timestamp()
                 WHERE session_id=? AND request_id=? AND state='claimed'",
                [$body, $sessionId, $requestId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return $changed === 1;
    }

    /** Cancels the claimed or streaming narrator turns whose reply is still pending for a definition. Runs inside the caller's transaction. */
    public function cancelNarratorTurnsAwaitingDefinition(string $definitionId): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_request_execution e SET state='cancelled',response_body=NULL,
            error_code='request_cancelled',completed_at=NOW() WHERE e.event_type='narrator_turn' AND e.state IN ('claimed','streaming')
            AND EXISTS(SELECT 1 FROM public.core_narrator_pending_output p WHERE p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.definition_id=? AND p.state IN ('pending','generated'))",
            [$definitionId]
        );
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

    /** The execution state of a request (of any generation), or null when it has no row. */
    public function stateOf(string $sessionId, string $requestId): ?string
    {
        $state = $this->connection()->fetchOne('SELECT state FROM public.core_request_execution WHERE session_id = ? AND request_id = ? LIMIT 1', [$sessionId, $requestId]);

        return $state === false || $state === null ? null : (string) $state;
    }

    /**
     * The frozen context of a completed request whose response body is exactly the hex-encoded bytes given and whose runtime
     * session and target identity agree with it; null when nothing matches.
     *
     * @return array{target_identity_id: string, playthrough_id: string, canonical_event: string, event_sha256: string, gamets: string}|null
     */
    public function completedResponseContext(string $sessionId, string $requestId, int $contextSequence, string $bodyHex,
        string $turnId, int $generation, string $runtimeVariant): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT c.target_identity_id,c.playthrough_id,p.canonical_event,p.event_sha256,
                    COALESCE((c.context_payload->'world'->>'game_time_ticks')::bigint,0) AS gamets
                FROM public.core_request_execution r JOIN public.core_runtime_context c ON c.session_id=r.session_id
                    AND c.generation=r.generation AND c.snapshot_sequence=:p3
                JOIN public.core_runtime_session s ON s.session_id=c.session_id AND s.playthrough_id=c.playthrough_id
                JOIN public.core_protocol_event p ON p.session_id=r.session_id AND p.request_id=r.request_id
                    AND p.generation=r.generation AND p.turn_id=r.turn_id AND p.event_type=r.event_type
                    AND p.event_sha256=r.request_sha256
                JOIN public.core_actor_identity a ON a.id=c.target_identity_id AND a.playthrough_id=c.playthrough_id
                    AND a.game='fo4' AND upper(a.full_form_id)=upper(c.context_payload->'target'->>'form_id')
                    AND lower(a.origin_plugin)=lower(c.context_payload->'target'->>'origin_plugin')
                WHERE r.session_id=:p1 AND r.request_id=:p2 AND r.state='complete'
                    AND r.response_body=decode(:p4,'hex') AND c.history_event_id IS NOT NULL
                    AND r.turn_id=:p5 AND r.generation=:p6 AND s.runtime_variant=:p7 AND s.game='fo4'",
            ['p1' => $sessionId, 'p2' => $requestId, 'p3' => $contextSequence, 'p4' => $bodyHex, 'p5' => $turnId,
                'p6' => $generation, 'p7' => $runtimeVariant]
        );

        return self::textRow($row);
    }

    /** True when the request completed with exactly this canonical event (its digest matches the stored one). */
    public function completedOwner(string $sessionId, string $requestId, string $canonicalEvent, string $eventSha256): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_protocol_event p JOIN public.core_request_execution e
            ON e.session_id=p.session_id AND e.request_id=p.request_id AND e.request_sha256=p.event_sha256 AND e.state='complete'
            WHERE p.session_id=:p1 AND p.request_id=:p2 AND p.canonical_event=:p3::jsonb AND p.event_sha256=:p4",
            ['p1' => $sessionId, 'p2' => $requestId, 'p3' => $canonicalEvent, 'p4' => $eventSha256]
        ) !== false;
    }

    /**
     * The fresh-owner proof of an ambient NPC turn: the request's own claimed execution, its canonical protocol event, the active
     * session turn and the context it was admitted against, plus whether a recent ambient trigger of the same session is still
     * cooling down. Null when the owner is not (or no longer) fresh, false when the statement failed.
     *
     * @return array<string, mixed>|null|false
     */
    public function ambientFreshOwner(string $sessionId, string $requestId, int $generation, string $turnId, int $contextSequence, string $runtimeVariant, string $canonicalJson, string $sha256): array|null|false
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT c.context_payload, s.playthrough_id,
                    EXISTS(SELECT 1 FROM public.core_request_execution prior
                        JOIN public.core_protocol_event pp USING(session_id,request_id)
                        WHERE prior.session_id=e.session_id AND prior.request_id<>e.request_id
                            AND prior.claimed_at BETWEEN e.claimed_at-INTERVAL '60 seconds' AND e.claimed_at
                            AND prior.event_type='trigger' AND pp.event_sha256=prior.request_sha256
                            AND pp.canonical_event#>>'{payload,kind}'='bored'
                            AND pp.canonical_event->'capabilities' @> '[\"dialogue.ambient_npc\"]'::jsonb) AS cooling_down
                FROM public.core_request_execution e JOIN public.core_protocol_event p USING(session_id,request_id)
                JOIN public.core_runtime_session s ON s.session_id=e.session_id AND s.state='active'
                    AND s.active_generation=e.generation AND s.active_turn_id=e.turn_id AND s.active_turn_sequence=:sequence
                JOIN public.core_runtime_context c ON c.session_id=e.session_id AND c.generation=e.generation
                    AND c.snapshot_sequence=:sequence AND c.playthrough_id=s.playthrough_id
                WHERE e.session_id=:session AND e.request_id=:request AND e.state IN ('claimed','streaming')
                    AND e.generation=:generation AND e.turn_id=:turn AND s.runtime_variant=:variant
                    AND p.canonical_event=CAST(:canonical AS jsonb) AND p.event_sha256=:sha AND e.request_sha256=p.event_sha256
                    AND p.event_type=e.event_type AND p.generation=e.generation AND p.turn_id=e.turn_id
                    AND c.received_at BETWEEN e.claimed_at-INTERVAL '10 seconds' AND e.claimed_at",
                ['session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'turn' => $turnId, 'sequence' => $contextSequence,
                 'variant' => $runtimeVariant, 'canonical' => $canonicalJson, 'sha' => $sha256]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row === false ? null : $row;
    }

    /**
     * The stored response body of a completed request, at most 2 MiB: its bytea as the driver returns it (hex text). Null when
     * the request is not complete, the body is larger, or the statement failed.
     */
    public function completedResponseBody(string $sessionId, string $requestId): ?string
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT response_body FROM public.core_request_execution
                 WHERE session_id = :session AND request_id = :request AND state = 'complete' AND octet_length(response_body) <= 2097152",
                ['session' => $sessionId, 'request' => $requestId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        return $row === false || $row['response_body'] === null ? null : (string) $row['response_body'];
    }
}
