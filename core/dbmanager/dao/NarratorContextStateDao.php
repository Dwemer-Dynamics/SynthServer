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

    /** Whether the private narrator tables of the context and pending-output storage exist (the migration has run). */
    public function storageReady(): bool
    {
        $ready = $this->connection()->fetchOne(
            "SELECT to_regclass('public.core_narrator_context_state') IS NOT NULL AND to_regclass('public.core_narrator_pending_output') IS NOT NULL"
        );

        return in_array($ready, [true, 't', 1, '1'], true);
    }

    /**
     * The private narrator state a saved snapshot of this playthrough recorded, or null when it recorded none or no row matches.
     * Failures throw.
     */
    public function savedStateId(string $sessionId, int $generation, int $snapshotSequence, string $playthroughId): ?int
    {
        $id = $this->connection()->fetchOne(
            'SELECT n.state_id FROM public.core_narrator_context_state n
             LEFT JOIN public.core_narrator_state s ON s.state_id = n.state_id
             WHERE n.session_id = ? AND n.generation = ? AND n.snapshot_sequence = ? AND n.playthrough_id = ?
               AND (n.state_id IS NULL OR s.playthrough_id = n.playthrough_id)',
            [$sessionId, $generation, $snapshotSequence, $playthroughId]
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    /** Records the private narrator state captured with one context snapshot. */
    public function capture(string $sessionId, int $generation, int $snapshotSequence, string $playthroughId, int|string|null $stateId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_narrator_context_state (session_id, generation, snapshot_sequence, playthrough_id, state_id) VALUES (?, ?, ?, ?, ?)',
            [$sessionId, $generation, $snapshotSequence, $playthroughId, $stateId === null ? null : (int) $stateId]
        );
    }
}
