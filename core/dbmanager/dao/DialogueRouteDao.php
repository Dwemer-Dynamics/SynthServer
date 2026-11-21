<?php

// Reads core_dialogue_route. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDialogueRoute;

/** Finds rows of core_dialogue_route. */
final class DialogueRouteDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId, string $lineId): ?AbstractDialogueRoute
    {
        return $this->fresh()->find(AbstractDialogueRoute::class, ['sessionId' => $sessionId, 'requestId' => $requestId, 'lineId' => $lineId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDialogueRoute> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDialogueRoute::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC', 'lineId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The emitted line of one utterance of a request at a context sequence: line id, line hash, speaker and listener JSON and
     * listener state. Null when there is none or the statement failed.
     *
     * @return array<string, mixed>|null
     */
    public function emittedLine(string $sessionId, string $requestId, int $generation, int $contextSequence, string $utteranceId): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT line_id, line_sha256, speaker, listener_state, listener
                 FROM public.core_dialogue_route WHERE session_id = :session AND request_id = :request AND generation = :generation
                    AND context_sequence = :sequence AND utterance_id = :utterance',
                ['session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'sequence' => $contextSequence, 'utterance' => $utteranceId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * Every route of a request at a context sequence that carries an utterance id, in storage order. Null when the statement failed.
     *
     * @return list<array<string, mixed>>|null
     */
    public function utteranceRoutes(string $sessionId, string $requestId, int $generation, int $contextSequence): ?array
    {
        try {
            return $this->connection()->fetchAllAssociative(
                'SELECT * FROM public.core_dialogue_route
                 WHERE session_id = :session AND request_id = :request AND generation = :generation AND context_sequence = :sequence AND utterance_id IS NOT NULL',
                ['session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'sequence' => $contextSequence]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }
    }
}
