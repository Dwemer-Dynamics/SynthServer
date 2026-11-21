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

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRequestExecution> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRequestExecution::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
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
}
