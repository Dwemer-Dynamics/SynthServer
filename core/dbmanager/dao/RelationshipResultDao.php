<?php

// Reads core_relationship_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipResult;

/** Finds rows of core_relationship_result. */
final class RelationshipResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipResult
    {
        return $this->fresh()->find(AbstractRelationshipResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
