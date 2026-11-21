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
}
