<?php

// Reads core_narrator_context_state. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorContextState;

/** Finds rows of core_narrator_context_state. */
final class NarratorContextStateDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, int|string $generation, int|string $snapshotSequence): ?AbstractNarratorContextState
    {
        return $this->fresh()->find(AbstractNarratorContextState::class, ['sessionId' => $sessionId, 'generation' => $generation, 'snapshotSequence' => $snapshotSequence]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorContextState> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorContextState::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC', 'snapshotSequence' => 'ASC'], max(1, $limit)));
    }
}
