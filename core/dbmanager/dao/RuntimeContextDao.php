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

    /**
     * The player identity of one context snapshot, only when it is a fo4 actor of the snapshot's playthrough whose form id, origin plugin
     * and playthrough match the snapshot's saved player; 0 when there is none.
     */
    public function savedPlayerIdentityId(string $sessionId, int $generation, int $snapshotSequence): int
    {
        $id = $this->connection()->fetchOne(
            "SELECT c.player_identity_id FROM public.core_runtime_context c JOIN public.core_actor_identity a
                ON a.id=c.player_identity_id AND a.game='fo4' AND a.playthrough_id=c.playthrough_id
                    AND upper(a.full_form_id)=upper(c.context_payload#>>'{player,form_id}')
                    AND lower(a.origin_plugin)=lower(c.context_payload#>>'{player,origin_plugin}')
                    AND a.playthrough_id=c.context_payload#>>'{player,playthrough_id}'
             WHERE c.session_id=? AND c.generation=? AND c.snapshot_sequence=?",
            [$sessionId, $generation, $snapshotSequence]
        );

        return $id === false || $id === null ? 0 : (int) $id;
    }
}
