<?php

// Reads core_request_execution. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRequestExecution;

/** Finds rows of core_request_execution. */
class RequestExecutionDao
{
    use UsesEntityManager;

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

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRequestExecution> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRequestExecution::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
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
}
