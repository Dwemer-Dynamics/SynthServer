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

    /** The player actor identity of one runtime context snapshot, or 0 when the snapshot has none. */
    public function playerIdentityId(string $sessionId, int $generation, int $snapshotSequence): int
    {
        $id = $this->connection()->fetchOne(
            'SELECT player_identity_id FROM public.core_runtime_context WHERE session_id = ? AND generation = ? AND snapshot_sequence = ?',
            [$sessionId, $generation, $snapshotSequence]
        );

        return $id === false || $id === null ? 0 : (int) $id;
    }
}
