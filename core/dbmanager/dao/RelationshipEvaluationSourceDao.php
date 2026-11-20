<?php

// Reads core_relationship_evaluation_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipEvaluationSource;

/** Finds rows of core_relationship_evaluation_source. */
final class RelationshipEvaluationSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $evaluationId, int|string $historyEventId): ?AbstractRelationshipEvaluationSource
    {
        return $this->fresh()->find(AbstractRelationshipEvaluationSource::class, ['evaluationId' => $evaluationId, 'historyEventId' => $historyEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipEvaluationSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipEvaluationSource::class)->findBy([], ['evaluationId' => 'ASC', 'historyEventId' => 'ASC'], max(1, $limit)));
    }
}
