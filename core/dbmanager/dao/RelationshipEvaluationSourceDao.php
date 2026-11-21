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

    /** Freezes that this evaluation read this history event. */
    public function add(int $evaluationId, int $historyEventId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_relationship_evaluation_source VALUES(:p1,:p2)',
            ['p1' => $evaluationId, 'p2' => $historyEventId]
        );
    }

    /** The history events this evaluation read, ascending. @return list<int> */
    public function historyEventIds(int $evaluationId): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT history_event_id FROM public.core_relationship_evaluation_source WHERE evaluation_id=:p1 ORDER BY history_event_id',
            ['p1' => $evaluationId]
        ));
    }

    /** How many history events this evaluation read. */
    public function countFor(int $evaluationId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) AS n FROM public.core_relationship_evaluation_source WHERE evaluation_id=:p1',
            ['p1' => $evaluationId]
        );
    }
}
