<?php

// Reads core_dialogue_utterance. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDialogueUtterance;

/** Finds rows of core_dialogue_utterance. */
final class DialogueUtteranceDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId, string $lineId): ?AbstractDialogueUtterance
    {
        return $this->fresh()->find(AbstractDialogueUtterance::class, ['sessionId' => $sessionId, 'requestId' => $requestId, 'lineId' => $lineId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDialogueUtterance> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDialogueUtterance::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC', 'lineId' => 'ASC'], max(1, $limit)));
    }

    /** Whether the line was delivered (spoken or presented) with a receipt: [] when not, null when the read fails. @return array<string, mixed>|null */
    public function heardLine(string $sessionId, string $requestId, string $lineId, int $generation, int $contextSequence, string $turnId): ?array
    {
        return $this->rowOrNull("SELECT 1 FROM public.core_dialogue_utterance
                    WHERE session_id=:p1 AND request_id=:p2 AND line_id=:p3 AND generation=:p4 AND context_sequence=:p5
                        AND turn_id=:p6 AND state IN ('spoken','presented') AND receipt_at IS NOT NULL",
            [$sessionId, $requestId, $lineId, $generation, $contextSequence, $turnId]);
    }

    /** Delivery state of every line of one request and context. @return list<array<string, mixed>>|null */
    public function lineStates(string $sessionId, string $requestId, int $generation, int $contextSequence): ?array
    {
        return $this->rowsOrNull('SELECT line_id,state FROM public.core_dialogue_utterance
        WHERE session_id=:p1 AND request_id=:p2 AND generation=:p3 AND context_sequence=:p4', [$sessionId, $requestId, $generation, $contextSequence]);
    }
}
