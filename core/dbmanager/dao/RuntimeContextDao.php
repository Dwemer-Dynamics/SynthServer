<?php

// Reads core_runtime_context. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRuntimeContext;

/** Finds rows of core_runtime_context. */
final class RuntimeContextDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, int|string $generation, int|string $snapshotSequence): ?AbstractRuntimeContext
    {
        return $this->fresh()->find(AbstractRuntimeContext::class, ['sessionId' => $sessionId, 'generation' => $generation, 'snapshotSequence' => $snapshotSequence]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRuntimeContext> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRuntimeContext::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC', 'snapshotSequence' => 'ASC'], max(1, $limit)));
    }

    /** The acknowledged history event of one context snapshot, or 0 when there is none (or the statement fails). */
    public function historyEventId(string $sessionId, int $generation, int $sequence): int
    {
        try {
            $value = $this->connection()->fetchOne(
                'SELECT history_event_id FROM public.core_runtime_context WHERE session_id = ? AND generation = ? AND snapshot_sequence = ?',
                [$sessionId, $generation, $sequence]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return 0;
        }

        return $value === false ? 0 : (int) $value;
    }

    /**
     * The context snapshot (with its session's runtime variant) that a regeneration approval may be recorded against: an active
     * save, an acknowledged snapshot and a non-player actor of the same playthrough. Null when none. Throws when the statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function approvalScope(string $sessionId, int|string $generation, int|string $sequence, int|string $actorId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT c.*,r.runtime_variant FROM public.core_runtime_context c
             JOIN public.core_runtime_session r USING(session_id)
             JOIN public.core_actor_identity a ON a.id = :actor AND a.game='fo4' AND a.playthrough_id=c.playthrough_id
             WHERE c.session_id = :session AND c.generation = :generation AND c.snapshot_sequence = :sequence
                AND r.state='active' AND r.active_generation=c.generation AND r.playthrough_id=c.playthrough_id
                AND c.history_event_id IS NOT NULL AND c.player_identity_id IS NOT NULL AND a.id<>c.player_identity_id",
            ['session' => $sessionId, 'generation' => $generation, 'sequence' => $sequence, 'actor' => $actorId]
        );

        return $row === false ? null : $row;
    }
}
