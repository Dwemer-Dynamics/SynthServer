<?php

// Reads core_middleterm_control_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermControlRevision;

/** Finds rows of core_middleterm_control_revision. */
final class MiddletermControlRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractMiddletermControlRevision
    {
        return $this->fresh()->find(AbstractMiddletermControlRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermControlRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermControlRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /**
     * True when a confirmed correction in this session, generation and context snapshot is newer than the snapshot's acknowledged
     * history event for a narrative of this actor. Throws when the statement fails.
     */
    public function hasUnsavedForActor(int $actorId, string $sessionId, int $generation, int $sequence): bool
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT EXISTS(SELECT 1 FROM public.core_middleterm_control_revision c
                JOIN public.core_middleterm_input i ON i.actor_identity_id = :actor
                JOIN public.core_middleterm_result r ON r.input_id=i.id AND r.history_event_id=c.narrative_id
                JOIN public.core_history_event h ON h.id=c.history_event_id
                JOIN public.core_runtime_context snapshot ON snapshot.session_id=h.session_id AND snapshot.generation=h.generation
                WHERE h.session_id = :session AND h.generation = :generation
                AND snapshot.snapshot_sequence = :sequence
                AND h.id>COALESCE(snapshot.history_event_id,0)) AS pending',
            ['actor' => $actorId, 'session' => $sessionId, 'generation' => $generation, 'sequence' => $sequence]
        );

        return $row !== false && $row['pending'] === 't';
    }
}
