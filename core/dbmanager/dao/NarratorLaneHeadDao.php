<?php

// Reads core_narrator_lane_head. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorLaneHead;

/** Finds rows of core_narrator_lane_head. */
final class NarratorLaneHeadDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, int|string $generation): ?AbstractNarratorLaneHead
    {
        return $this->fresh()->find(AbstractNarratorLaneHead::class, ['sessionId' => $sessionId, 'generation' => $generation]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorLaneHead> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorLaneHead::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC'], max(1, $limit)));
    }
}
