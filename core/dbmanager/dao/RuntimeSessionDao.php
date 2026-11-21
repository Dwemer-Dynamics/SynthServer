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

    /** Active generation and state of one session, or null when it has no row. @return array{active_generation: mixed, state: mixed}|null */
    public function generationAndState(string $sessionId): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT active_generation, state FROM public.core_runtime_session WHERE session_id = ?', [$sessionId]);

        return $row === false ? null : $row;
    }
}
