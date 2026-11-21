<?php

// Reads core_rechat_chain. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatChain;

/** Finds rows of core_rechat_chain. */
final class RechatChainDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractRechatChain
    {
        return $this->fresh()->find(AbstractRechatChain::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatChain> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatChain::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** Chains owned by a root request, or reserved for it by a continuation. @return list<array<string, mixed>>|null null when the read fails */
    public function forRoot(string $sessionId, string $rootRequest): ?array
    {
        return $this->rowsOrNull("SELECT c.* FROM public.core_rechat_chain c
        WHERE c.session_id=:p1 AND (c.root_request_id=:p2 OR EXISTS (
            SELECT 1 FROM public.core_rechat_continuation d WHERE d.chain_id=c.id
                AND d.session_id=c.session_id AND d.request_id=:p2 AND d.status='reserved'))", [$sessionId, $rootRequest]);
    }

    /**
     * Inserts a chain and returns it. Parameters, in order: session id, root request id, generation, context sequence, turn
     * id, runtime variant, mode, policy JSON, budget, terminal reason. Null when the insert fails.
     *
     * @return array<string, mixed>|null
     */
    public function insert(array $parameters): ?array
    {
        return $this->rowOrNull("INSERT INTO public.core_rechat_chain
                    (session_id,root_request_id,generation,context_sequence,turn_id,runtime_variant,mode,policy,budget,terminal_reason)
                    VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8::jsonb,:p9,:p10) RETURNING *", $parameters);
    }

    /** Records the rounds used and the terminal reason of a chain; false when the update fails. */
    public function updateBudget(int|string $chainId, int $usedRounds, ?string $terminalReason): bool
    {
        return $this->rowsOrNull("UPDATE public.core_rechat_chain
                SET used_rounds=:p2,terminal_reason=:p3 WHERE id=:p1 RETURNING id", [$chainId, $usedRounds, $terminalReason]) !== null;
    }

    /** Stops a chain with a terminal reason; false when the update fails. */
    public function setTerminal(int|string $chainId, string $terminalReason): bool
    {
        return $this->rowsOrNull('UPDATE public.core_rechat_chain SET terminal_reason=:p2 WHERE id=:p1 RETURNING id', [$chainId, $terminalReason]) !== null;
    }

    /** The root request id of a session's chain: [] when none, null when the read fails. @return array<string, mixed>|null */
    public function rootRequest(int|string $chainId, string $sessionId): ?array
    {
        return $this->rowOrNull('SELECT root_request_id FROM public.core_rechat_chain WHERE id=:p1 AND session_id=:p2', [$chainId, $sessionId]);
    }
}
