<?php

// Reads core_rechat_selection. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatSelection;

/** Finds rows of core_rechat_selection. */
final class RechatSelectionDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractRechatSelection
    {
        return $this->fresh()->find(AbstractRechatSelection::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatSelection> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatSelection::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /** The request's selection with its continuation and chain, once prepared and owned by this exact turn. @return array<string, mixed>|null */
    public function preparedForOwner(string $sessionId, string $requestId, int $generation, int $contextSequence, string $turnId, string $runtimeVariant): ?array
    {
        return $this->rowOrNull("SELECT s.*,d.status,d.parent_request_id,d.parent_line_id,d.parent_response_sha256,
                d.round_number,d.chain_id,c.budget,c.policy,c.terminal_reason AS chain_terminal
            FROM public.core_rechat_selection s JOIN public.core_rechat_continuation d USING(session_id,request_id)
            JOIN public.core_rechat_chain c ON c.id=d.chain_id AND c.session_id=d.session_id
            WHERE s.session_id=:p1 AND s.request_id=:p2 AND s.prepared_at IS NOT NULL
                AND c.generation=:p3 AND c.context_sequence=:p4 AND c.turn_id=:p5 AND c.runtime_variant=:p6",
            [$sessionId, $requestId, $generation, $contextSequence, $turnId, $runtimeVariant]);
    }

    /** Stamps prepared_at once and returns it. [] when no row is stamped, null when the update fails. @return array<string, mixed>|null */
    public function markPrepared(string $sessionId, string $requestId): ?array
    {
        return $this->rowOrNull('UPDATE public.core_rechat_selection
                SET prepared_at=COALESCE(prepared_at,clock_timestamp()) WHERE session_id=:p1 AND request_id=:p2 RETURNING prepared_at',
            [$sessionId, $requestId]);
    }

    /** The stored selection of one request. @return array<string, mixed>|null */
    public function forRequest(string $sessionId, string $requestId): ?array
    {
        return $this->rowOrNull('SELECT * FROM public.core_rechat_selection WHERE session_id=:p1 AND request_id=:p2', [$sessionId, $requestId]);
    }

    /**
     * Inserts a selection and returns it. Parameters, in order: session id, request id, role, actor identity id, npc id,
     * profile id, identity JSON, terminal reason.
     *
     * @return array<string, mixed>|null
     */
    public function insert(array $parameters): ?array
    {
        return $this->rowOrNull("INSERT INTO public.core_rechat_selection
                    (session_id,request_id,role,actor_identity_id,npc_id,profile_id,identity,terminal_reason)
                    VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7::jsonb,:p8) RETURNING *", $parameters);
    }

    /** Role and identity of one prepared, reserved selection in a chain. @return array<string, mixed>|null */
    public function preparedIdentity(string $sessionId, string $requestId, int|string $chainId): ?array
    {
        return $this->rowOrNull("SELECT s.role,s.identity FROM public.core_rechat_selection s
            JOIN public.core_rechat_continuation d USING(session_id,request_id)
            WHERE s.session_id=:p1 AND s.request_id=:p2 AND d.chain_id=:p3 AND d.status='reserved'
                AND s.prepared_at IS NOT NULL AND s.role IN ('npc','narrator')", [$sessionId, $requestId, $chainId]);
    }

    /** Earlier selections of a chain, by round. @return list<array<string, mixed>>|null */
    public function chainHistory(int|string $chainId, int|string $roundNumber): ?array
    {
        return $this->rowsOrNull('SELECT s.role,s.identity,d.round_number
                        FROM public.core_rechat_selection s JOIN public.core_rechat_continuation d USING(session_id,request_id)
                        WHERE d.chain_id=:p1 AND d.round_number<:p2 ORDER BY d.round_number', [$chainId, $roundNumber]);
    }
}
