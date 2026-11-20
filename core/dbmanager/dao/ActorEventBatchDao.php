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
}
