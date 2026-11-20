<?php

// Reads core_dialogue_utterance. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDialogueUtterance;

/** Finds rows of core_dialogue_utterance. */
final class DialogueUtteranceDao
{
    use UsesEntityManager;

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
}
