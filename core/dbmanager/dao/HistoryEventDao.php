<?php

// Reads core_history_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractHistoryEvent;

/** Finds rows of core_history_event. */
final class HistoryEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractHistoryEvent
    {
        return $this->fresh()->find(AbstractHistoryEvent::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractHistoryEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractHistoryEvent::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * The party document (as JSON text) of the reading event with this event id, recorded in this session and playthrough at or
     * after $minSequence; null when none.
     */
    public function readingEventParty(string $sessionId, int $generation, string $playthroughId, string $eventId, int $minSequence): ?string
    {
        $party = $this->connection()->fetchOne(
            "SELECT (event_payload->>'party')::jsonb AS party FROM public.core_history_event
             WHERE session_id = ? AND generation = ? AND playthrough_id = ? AND event_payload->>'type' = 'reading_event'
               AND context_sequence >= ? AND (event_payload->>'party')::jsonb->>'event_id' = ? LIMIT 1",
            [$sessionId, $generation, $playthroughId, $minSequence, $eventId]
        );

        return $party === false ? null : (string) $party;
    }

    /**
     * Publishes a native wait consequence in one statement: an emitted infoaction context event and the history event that wraps
     * it together with the consequence document. Throws when the statement fails.
     */
    public function publishWaitConsequence(string $message, string $playthroughId, int $gameTime, int $now, string $location, string $sessionId, int $generation,
        int $contextSequence, string $requestId, string $consequenceJson): void
    {
        $this->connection()->executeStatement(
            "WITH publication AS (INSERT INTO public.core_context_event (type, data, sess, gamets, localts, ts, people, location, party, delivery_state,
                    synth_session_id, synth_generation, synth_context_sequence, synth_request_id)
                VALUES ('infoaction', ?, ?, ?, ?, ?, '', ?, '', 'emitted', ?, ?, ?, ?) RETURNING *)
             INSERT INTO public.core_history_event (session_id, generation, context_sequence, request_id, playthrough_id, eventlog_rowid, event_payload)
             SELECT synth_session_id, synth_generation, synth_context_sequence, synth_request_id, CAST(? AS text), rowid,
                    to_jsonb(publication) || jsonb_build_object('synth_wait_consequence', CAST(? AS jsonb))
             FROM publication",
            [$message, $playthroughId, $gameTime, $now, $now, $location, $sessionId, $generation, $contextSequence, $requestId, $playthroughId, $consequenceJson]
        );
    }
}
