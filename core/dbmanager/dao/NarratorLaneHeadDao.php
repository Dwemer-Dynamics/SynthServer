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

    /**
     * The lane head of this session generation, locked FOR UPDATE until the caller's open transaction ends (the read joins that
     * transaction because it runs on the same session); null when the lane has no head. Failures throw.
     *
     * @return array{state_id: ?string, playthrough_id: string}|null
     */
    public function lockHead(string $sessionId, int $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT state_id, playthrough_id FROM public.core_narrator_lane_head WHERE session_id = ? AND generation = ? FOR UPDATE',
            [$sessionId, $generation]
        );

        return $row === false ? null : $row;
    }

    /** Opens the lane of this session generation at the given private narrator state (null for the empty state). */
    public function open(string $sessionId, int $generation, string $playthroughId, ?int $stateId): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_narrator_lane_head (session_id, generation, playthrough_id, state_id) VALUES (?, ?, ?, ?)',
            [$sessionId, $generation, $playthroughId, $stateId]
        );
    }
}
