<?php

// Reads core_rechat_continuation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatContinuation;

/** Finds rows of core_rechat_continuation. */
final class RechatContinuationDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractRechatContinuation
    {
        return $this->fresh()->find(AbstractRechatContinuation::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatContinuation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatContinuation::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /** Continuations of a session that are this request or continue this parent, with their chain's policy. @return list<array<string, mixed>>|null */
    public function replayRows(string $sessionId, string $requestId, string $parentRequestId): ?array
    {
        return $this->rowsOrNull("SELECT d.*,c.mode,c.policy,c.budget,c.used_rounds
                FROM public.core_rechat_continuation d JOIN public.core_rechat_chain c ON c.id=d.chain_id
                WHERE d.session_id=:p1 AND (d.request_id=:p2 OR d.parent_request_id=:p3)", [$sessionId, $requestId, $parentRequestId]);
    }

    /**
     * Inserts a reservation and returns it. Parameters, in order: session id, request id, chain id, parent request id,
     * parent line id, parent response sha256, round number, status, terminal reason.
     *
     * @return array<string, mixed>|null
     */
    public function insert(array $parameters): ?array
    {
        return $this->rowOrNull("INSERT INTO public.core_rechat_continuation
                (session_id,request_id,chain_id,parent_request_id,parent_line_id,parent_response_sha256,round_number,status,terminal_reason)
                VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8,:p9) RETURNING *", $parameters);
    }

    /** The request's continuation with its chain's mode, policy and terminal reason, for the exact turn owner. @return array<string, mixed>|null */
    public function reservation(string $sessionId, string $requestId, int $generation, int $contextSequence, string $turnId, string $runtimeVariant): ?array
    {
        return $this->rowOrNull("SELECT d.*,c.mode,c.policy,c.terminal_reason AS chain_terminal FROM public.core_rechat_continuation d
                JOIN public.core_rechat_chain c ON c.id=d.chain_id AND c.session_id=d.session_id
                WHERE d.session_id=:p1 AND d.request_id=:p2 AND c.generation=:p3 AND c.context_sequence=:p4
                    AND c.turn_id=:p5 AND c.runtime_variant=:p6",
            [$sessionId, $requestId, $generation, $contextSequence, $turnId, $runtimeVariant]);
    }

    /** The reserved, prepared continuation edge of one request in a chain. @return array<string, mixed>|null */
    public function preparedEdge(string $sessionId, string $requestId, int|string $chainId): ?array
    {
        return $this->rowOrNull("SELECT d.* FROM public.core_rechat_continuation d
                    JOIN public.core_rechat_selection s USING(session_id,request_id)
                    WHERE d.session_id=:p1 AND d.request_id=:p2 AND d.chain_id=:p3 AND d.status='reserved'
                        AND s.prepared_at IS NOT NULL AND s.role IN ('npc','narrator')", [$sessionId, $requestId, $chainId]);
    }
}
