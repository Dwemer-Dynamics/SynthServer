<?php

// Reads core_runtime_session. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRuntimeSession;

/** Finds rows of core_runtime_session. */
final class RuntimeSessionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId): ?AbstractRuntimeSession
    {
        return $this->fresh()->find(AbstractRuntimeSession::class, $sessionId);
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
}
