<?php

// Reads core_runtime_session. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRuntimeSession;

/** Finds rows of core_runtime_session. */
class RuntimeSessionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId): ?AbstractRuntimeSession
    {
        return $this->fresh()->find(AbstractRuntimeSession::class, $sessionId);
    }

    /**
     * The session row locked FOR UPDATE for a claim: generation, state, playthrough, capabilities, runtime variant, active turn id
     * and sequence, and the protocol version of its init event ('1' without one); null when there is no row.
     *
     * @return array<string, mixed>|null
     */
    public function lockForClaim(string $sessionId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT r.active_generation, r.state, r.playthrough_id, r.capabilities, r.runtime_variant,
                    r.active_turn_id, r.active_turn_sequence,
                    COALESCE((SELECT e.canonical_event->>'protocol_version'
                                FROM public.core_protocol_event e
                               WHERE e.session_id=r.session_id AND e.generation=r.active_generation
                                 AND e.event_type='init' LIMIT 1), '1') AS protocol_version
               FROM public.core_runtime_session r WHERE r.session_id = ? FOR UPDATE",
            [$sessionId]
        );

        return $row === false ? null : $row;
    }

    /** True when the table still has the audience_reset_epoch column (the Everyone migration). */
    public function supportsAudienceReset(): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 AS ready FROM pg_attribute WHERE attrelid='public.core_runtime_session'::regclass
                AND attname='audience_reset_epoch' AND NOT attisdropped"
        ) !== false;
    }

    /**
     * Registers an init (or a retirement that overtook it) as the session's active generation and clears the active turn. With
     * $resetAudience it also returns the session to the Normal audience and advances the epoch, and returns
     * ['audience_intent', 'audience_reset_epoch']; otherwise it returns [] when the statement ran. Null when the statement failed.
     *
     * @return array<string, mixed>|null
     */
    public function upsertInit(string $sessionId, string $playthroughId, string $runtimeVariant, string $clientVersion, string $runtimeVersion, int $generation, string $capabilitiesJson, bool $resetAudience): ?array
    {
        $audienceSql = $resetAudience ? ", audience_intent='normal',
                     audience_reset_epoch=public.core_runtime_session.audience_reset_epoch+1" : '';
        $returning = $resetAudience ? ' RETURNING audience_intent, audience_reset_epoch' : '';
        $sql = "INSERT INTO public.core_runtime_session
                    (session_id, playthrough_id, game, runtime_variant, client_version, runtime_version,
                     active_generation, capabilities, state)
                 VALUES (?, ?, 'fo4', ?, ?, ?, ?, CAST(? AS jsonb), 'active')
                 ON CONFLICT (session_id) DO UPDATE SET
                     game='fo4', runtime_variant=EXCLUDED.runtime_variant,
                     client_version=EXCLUDED.client_version, runtime_version=EXCLUDED.runtime_version,
                     active_generation=EXCLUDED.active_generation, capabilities=EXCLUDED.capabilities,
                     state='active', active_turn_id=NULL, active_turn_sequence=0, updated_at=NOW(){$audienceSql}{$returning}";
        $parameters = [$sessionId, $playthroughId, $runtimeVariant, $clientVersion, $runtimeVersion, $generation, $capabilitiesJson];
        if (!$resetAudience) {
            return $this->tryStatement($sql, $parameters) ? [] : null;
        }
        try {
            $row = $this->connection()->fetchAssociative($sql, $parameters);
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** Makes a newer turn the session's active one (only while the generation is still active). */
    public function adoptTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->tryStatement(
            'UPDATE public.core_runtime_session SET active_turn_id = ?, active_turn_sequence = ?, updated_at = NOW() WHERE session_id = ? AND active_generation = ?',
            [$turnId, $sequence, $sessionId, $generation]
        );
    }

    /** Clears the active turn when it is exactly this turn and sequence of the active generation. */
    public function clearTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->tryStatement(
            'UPDATE public.core_runtime_session SET active_turn_id = NULL, updated_at = NOW()
              WHERE session_id = ? AND active_generation = ? AND active_turn_id = ? AND active_turn_sequence = ?',
            [$sessionId, $generation, $turnId, $sequence]
        );
    }

    /** Marks the session halted. */
    public function halt(string $sessionId): bool
    {
        return $this->tryStatement("UPDATE public.core_runtime_session SET state = 'halted', updated_at = NOW() WHERE session_id = ?", [$sessionId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRuntimeSession> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRuntimeSession::class)->findBy([], ['sessionId' => 'ASC'], max(1, $limit)));
    }

    /**
     * Active game sessions that can use the Everyone audience, by session id, at most $limit. Runs inside the caller's management
     * transaction. A missing audience column or table reads as 'audience_migration_missing'.
     *
     * @return list<array<string, mixed>>
     */
    public function activeAudienceSessions(int $limit): array
    {
        $this->requireTransaction();
        try {
            return $this->connection()->fetchAllAssociative(
                "SELECT session_id, active_generation, runtime_variant, playthrough_id, client_version,
                        audience_intent, audience_reset_epoch
                   FROM public.core_runtime_session
                  WHERE state='active' AND jsonb_exists(capabilities, 'dialogue.everyone')
                  ORDER BY session_id LIMIT ".max(1, $limit)
            );
        } catch (\Throwable $error) {
            throw $this->audienceFailure($error);
        }
    }

    /** Takes the transaction-scoped advisory lock of a session id (released when the caller's transaction ends). */
    public function lockSessionAdvisory(string $sessionId): void
    {
        $this->requireTransaction();
        try {
            $this->connection()->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?))', [$sessionId]);
        } catch (\Throwable $error) {
            throw $this->audienceFailure($error);
        }
    }

    private function requireTransaction(): void
    {
        $native = $this->connection()->getNativeConnection();
        if ($native instanceof \PgSql\Connection && pg_transaction_status($native) !== PGSQL_TRANSACTION_INTRANS) {
            throw new \LogicException('audience_transaction_required');
        }
    }

    private function audienceFailure(\Throwable $error): \RuntimeException
    {
        // A missing column or table means the audience migration has not run; never create it here.
        $missing = preg_match('/(column|relation) ".*" does not exist/i', $error->getMessage()) === 1;

        return new \RuntimeException($missing ? 'audience_migration_missing' : 'audience_query_failed');
    }

    /**
     * Picks one active session whose latest completed dialogue turn still needs memory work and reserves it for thirty seconds
     * (FOR UPDATE OF the session row, SKIP LOCKED, inside the caller's open transaction). The turn's origin (session_id, request_id,
     * turn_id, generation, event_type, canonical_event, event_sha256, context_sequence), or null when nothing is due.
     *
     * @return array<string, mixed>|null
     */
    public function reserveMemoryDispatch(): ?array
    {
        $row = $this->connection()->fetchAssociative("WITH candidate AS MATERIALIZED (
            SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,
                o.canonical_event,o.event_sha256,r.active_turn_sequence AS context_sequence
            FROM public.core_runtime_session r
            CROSS JOIN LATERAL (
                SELECT e.request_id,e.turn_id,e.generation,e.event_type,p.canonical_event,p.event_sha256
                FROM public.core_request_execution e
                JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                    AND p.event_sha256=e.request_sha256
                WHERE e.session_id=r.session_id AND e.generation=r.active_generation
                    AND e.turn_id=r.active_turn_id AND e.state='complete'
                    AND e.event_type IN ('input_text','input_audio','trigger','action_result','activate')
                    AND p.canonical_event->>'protocol_version'='2'
                    AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                    AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                    AND jsonb_exists(p.canonical_event->'capabilities','dialogue.turn_ownership')
                    AND (e.event_type<>'activate' OR p.canonical_event->'payload'->>'source'='manual')
                    -- Closed or non-NPC continuations must not hide this turn's completed NPC dialogue.
                    AND (e.event_type<>'trigger' OR p.canonical_event->'payload'->>'kind' IS DISTINCT FROM 'rechat'
                        OR EXISTS (SELECT 1 FROM public.core_rechat_selection selection
                            JOIN public.core_rechat_continuation continuation USING(session_id,request_id)
                            JOIN public.core_rechat_chain chain
                                ON chain.id=continuation.chain_id AND chain.session_id=continuation.session_id
                            WHERE selection.session_id=e.session_id AND selection.request_id=e.request_id
                                AND selection.prepared_at IS NOT NULL AND selection.role='npc'
                                AND continuation.status='reserved'
                                AND COALESCE(chain.terminal_reason,'') NOT IN ('private','no_candidate','ineligible')
                                AND chain.generation=e.generation AND chain.turn_id=e.turn_id
                                AND chain.context_sequence=r.active_turn_sequence AND chain.runtime_variant=r.runtime_variant))
                ORDER BY e.claimed_at DESC,e.request_id DESC LIMIT 1
            ) o
            JOIN public.core_runtime_context c ON c.session_id=r.session_id AND c.generation=r.active_generation
                AND c.snapshot_sequence=r.active_turn_sequence AND c.playthrough_id=r.playthrough_id
                AND c.target_identity_id IS NOT NULL AND c.history_event_id IS NOT NULL
            WHERE r.state='active' AND (r.memory_dispatch_after IS NULL OR r.memory_dispatch_after<=clock_timestamp())
            ORDER BY r.memory_dispatch_after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
        ), reserved AS (
            UPDATE public.core_runtime_session r SET memory_dispatch_after=clock_timestamp()+INTERVAL '30 seconds'
            FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
        ) SELECT c.* FROM candidate c JOIN reserved USING (session_id)");

        return $row === false ? null : $row;
    }
}
