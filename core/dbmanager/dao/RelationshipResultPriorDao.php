<?php

// Reads core_relationship_result_prior. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipResultPrior;

/** Finds rows of core_relationship_result_prior. */
final class RelationshipResultPriorDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $resultHistoryEventId, int|string $priorHistoryEventId): ?AbstractRelationshipResultPrior
    {
        return $this->fresh()->find(AbstractRelationshipResultPrior::class, ['resultHistoryEventId' => $resultHistoryEventId, 'priorHistoryEventId' => $priorHistoryEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipResultPrior> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipResultPrior::class)->findBy([], ['resultHistoryEventId' => 'ASC', 'priorHistoryEventId' => 'ASC'], max(1, $limit)));
    }

    /** Freezes that this result was computed from this saved relationship revision. */
    public function add(int $resultHistoryEventId, int|string $priorHistoryEventId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_relationship_result_prior VALUES(:p1,:p2)',
            ['p1' => $resultHistoryEventId, 'p2' => $priorHistoryEventId]
        );
    }
}
