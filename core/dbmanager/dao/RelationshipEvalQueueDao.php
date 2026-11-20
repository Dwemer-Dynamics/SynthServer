<?php

// Reads core_relationship_eval_queue. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipEvalQueue;

/** Finds rows of core_relationship_eval_queue. */
final class RelationshipEvalQueueDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractRelationshipEvalQueue
    {
        return $this->fresh()->find(AbstractRelationshipEvalQueue::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipEvalQueue> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipEvalQueue::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
