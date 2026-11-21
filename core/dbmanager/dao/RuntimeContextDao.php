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

    /** The target identity, playthrough and context payload of one saved snapshot (the owner a memory job writes for), or null. @return array<string, mixed>|null */
    public function memoryOwner(string $sessionId, int|string $generation, int|string $snapshotSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT target_identity_id,playthrough_id,context_payload
        FROM public.core_runtime_context WHERE session_id=? AND generation=? AND snapshot_sequence=?',
            [$sessionId, $generation, $snapshotSequence]
        );

        return $row === false ? null : $row;
    }

    /** The player identity of one saved snapshot, or 0 when there is none. */
    public function playerIdentityId(string $sessionId, int|string $generation, int|string $snapshotSequence): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT player_identity_id FROM public.core_runtime_context WHERE session_id=? AND generation=? AND snapshot_sequence=?',
            [$sessionId, $generation, $snapshotSequence]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRuntimeContext> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRuntimeContext::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC', 'snapshotSequence' => 'ASC'], max(1, $limit)));
    }
}
