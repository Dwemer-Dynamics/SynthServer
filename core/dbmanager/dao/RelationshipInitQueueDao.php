<?php

// Reads core_relationship_init_queue. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInitQueue;

/** Finds rows of core_relationship_init_queue. */
final class RelationshipInitQueueDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractRelationshipInitQueue
    {
        return $this->fresh()->find(AbstractRelationshipInitQueue::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipInitQueue> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipInitQueue::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
