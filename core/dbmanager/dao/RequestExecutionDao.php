<?php

// Reads core_request_execution. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRequestExecution;

/** Finds rows of core_request_execution. */
class RequestExecutionDao
{
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

        return self::textRow($row);
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
}
