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

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRequestExecution> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRequestExecution::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
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
