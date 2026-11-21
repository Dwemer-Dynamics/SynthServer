<?php

// Reads core_dialogue_route. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDialogueRoute;

/** Finds rows of core_dialogue_route. */
final class DialogueRouteDao
{
    use UsesEntityManager;
    use FailSoftQueries;

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

    /** Every route of one request and context. @return list<array<string, mixed>>|null null when the read fails */
    public function forRequest(string $sessionId, string $requestId, int $generation, int $contextSequence): ?array
    {
        return $this->rowsOrNull('SELECT * FROM public.core_dialogue_route
        WHERE session_id=:p1 AND request_id=:p2 AND generation=:p3 AND context_sequence=:p4', [$sessionId, $requestId, $generation, $contextSequence]);
    }

    /** The route that already uses an utterance id: [] when none, null when the read fails. @return array<string, mixed>|null */
    public function forUtterance(string $sessionId, string $requestId, string $utteranceId): ?array
    {
        return $this->rowOrNull('SELECT line_id FROM public.core_dialogue_route
                WHERE session_id=:p1 AND request_id=:p2 AND utterance_id=:p3', [$sessionId, $requestId, $utteranceId]);
    }

    /**
     * Stores a route, or replays an identical one. Parameters, in order: session id, request id, line id, line sha256,
     * generation, context sequence, speaker JSON, listener state, listener JSON, utterance id. Returns the line id row; []
     * when an existing route conflicts; null when the statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function persist(array $parameters): ?array
    {
        return $this->rowOrNull("INSERT INTO public.core_dialogue_route
                (session_id,request_id,line_id,line_sha256,generation,context_sequence,speaker,listener_state,listener,utterance_id)
                VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7::jsonb,:p8,:p9::jsonb,:p10)
                ON CONFLICT(session_id,request_id,line_id) DO UPDATE SET line_sha256=EXCLUDED.line_sha256
                WHERE core_dialogue_route.line_sha256=EXCLUDED.line_sha256
                    AND core_dialogue_route.generation=EXCLUDED.generation AND core_dialogue_route.context_sequence=EXCLUDED.context_sequence
                    AND core_dialogue_route.speaker=EXCLUDED.speaker AND core_dialogue_route.listener_state=EXCLUDED.listener_state
                    AND core_dialogue_route.listener IS NOT DISTINCT FROM EXCLUDED.listener
                    AND core_dialogue_route.utterance_id IS NOT DISTINCT FROM EXCLUDED.utterance_id
                RETURNING line_id", $parameters);
    }
}
