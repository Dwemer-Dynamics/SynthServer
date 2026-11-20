<?php

// Reads core_relationship_evaluation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipEvaluation;

/** Finds rows of core_relationship_evaluation. */
final class RelationshipEvaluationDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractRelationshipEvaluation
    {
        return $this->fresh()->find(AbstractRelationshipEvaluation::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipEvaluation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipEvaluation::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
