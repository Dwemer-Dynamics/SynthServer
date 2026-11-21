<?php

// Reads core_narrator_pending_output. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorPendingOutput;

/** Finds rows of core_narrator_pending_output. */
final class NarratorPendingOutputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractNarratorPendingOutput
    {
        return $this->fresh()->find(AbstractNarratorPendingOutput::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorPendingOutput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorPendingOutput::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /** Cancels the pending or generated replies of this playthrough that belong to any lane other than this session generation. */
    public function cancelSuperseded(string $playthroughId, string $sessionId, int $generation): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_narrator_pending_output SET state = 'cancelled', updated_at = NOW()
             WHERE playthrough_id = ? AND state IN ('pending', 'generated') AND NOT (session_id = ? AND generation = ?)",
            [$playthroughId, $sessionId, $generation]
        );
    }
}
