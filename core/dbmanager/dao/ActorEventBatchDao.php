<?php

// Reads core_actor_event_batch. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActorEventBatch;

/** Finds rows of core_actor_event_batch. */
final class ActorEventBatchDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, int|string $generation, string $playthroughId, string $batchId): ?AbstractActorEventBatch
    {
        return $this->fresh()->find(AbstractActorEventBatch::class, ['sessionId' => $sessionId, 'generation' => $generation, 'playthroughId' => $playthroughId, 'batchId' => $batchId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActorEventBatch> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActorEventBatch::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC', 'playthroughId' => 'ASC', 'batchId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The stored payloads of one batch of this owner while its session is still the active one, null when there is none, or
     * false when the read failed.
     *
     * @return array<string, mixed>|null|false
     */
    public function activePayloads(string $sessionId, int $generation, string $playthroughId, string $batchId): array|null|false
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT b.player_payload, b.input_payload FROM public.core_actor_event_batch b
                 JOIN public.core_runtime_session s ON s.session_id = b.session_id
                 WHERE b.session_id = :session AND b.generation = :generation AND b.playthrough_id = :playthrough AND b.batch_id = :batch
                   AND s.active_generation = b.generation AND s.playthrough_id = b.playthrough_id AND s.state = 'active'",
                ['session' => $sessionId, 'generation' => $generation, 'playthrough' => $playthroughId, 'batch' => $batchId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row === false ? null : $row;
    }

    /**
     * Records the batch against the active runtime context it was captured in. True when the row was written, false when the
     * context is not the active one or the statement failed.
     */
    public function insertForActiveContext(string $sessionId, int $generation, string $playthroughId, string $batchId, int $snapshotSequence, string $requestId, string $playerJson, string $inputJson, int $captureAgeMs): bool
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "INSERT INTO public.core_actor_event_batch
                 (session_id, generation, playthrough_id, batch_id, context_sequence, request_id, player_payload, input_payload, capture_age_ms)
                 SELECT c.session_id, c.generation, c.playthrough_id, :batch, c.snapshot_sequence, :request, CAST(:player AS jsonb), CAST(:input AS jsonb), :age
                   FROM public.core_runtime_context c JOIN public.core_runtime_session s USING (session_id)
                  WHERE c.session_id = :session AND c.generation = :generation AND c.playthrough_id = :playthrough AND c.snapshot_sequence = :sequence
                    AND s.active_generation = c.generation AND s.playthrough_id = c.playthrough_id AND s.state = 'active' RETURNING batch_id",
                ['session' => $sessionId, 'generation' => $generation, 'playthrough' => $playthroughId, 'batch' => $batchId,
                 'sequence' => $snapshotSequence, 'request' => $requestId, 'player' => $playerJson, 'input' => $inputJson, 'age' => $captureAgeMs]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row !== false;
    }
}
