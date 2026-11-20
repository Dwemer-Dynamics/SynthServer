<?php

// Reads core_visual_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractVisualResult;

/** Finds rows of core_visual_result. */
final class VisualResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractVisualResult
    {
        return $this->fresh()->find(AbstractVisualResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractVisualResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractVisualResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
