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
     * The first-observation input of a witnessed dialogue observation of this owner while its generation is the active one:
     * its input JSON, null when there is none, false when the read failed.
     */
    public function observedDialogueInput(string $sessionId, int $generation, string $playthroughId, string $observationId): string|null|false
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT h.event_payload->'synth_witness_observation'->'input' AS input
                 FROM public.core_history_event h JOIN public.core_runtime_session s ON s.session_id = h.session_id
                 WHERE h.session_id = :session AND h.generation = :generation AND h.playthrough_id = :playthrough
                   AND h.event_payload->'synth_witness_observation'->>'observation_id' = :observation
                   AND s.active_generation = h.generation AND s.state = 'active' LIMIT 1",
                ['session' => $sessionId, 'generation' => $generation, 'playthrough' => $playthroughId, 'observation' => $observationId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row === false ? null : (string) $row['input'];
    }

    /**
     * Publishes a witnessed dialogue as a background-chat context event and its history row in one statement, against the
     * active runtime context. True when the history row was written, false when the context is not active or the statement failed.
     */
    public function publishObservedDialogue(string $sessionId, int $generation, int $snapshotSequence, string $playthroughId, string $requestId, string $text, int $gameTimeTicks, int $localTime, string $location, string $observationJson, string $hearerIdsJson): bool
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "WITH captured AS (
                    SELECT c.session_id, c.generation, c.snapshot_sequence, c.playthrough_id
                      FROM public.core_runtime_context c JOIN public.core_runtime_session s USING (session_id)
                     WHERE c.session_id = :session AND c.generation = :generation AND c.snapshot_sequence = :sequence AND c.playthrough_id = :playthrough
                       AND s.active_generation = c.generation AND s.state = 'active'
                ), publication AS (
                    INSERT INTO public.core_context_event(type, data, sess, gamets, localts, ts, people, location, party, delivery_state,
                        synth_session_id, synth_generation, synth_context_sequence, synth_request_id)
                    SELECT 'backgroundchat', :text, playthrough_id, :ticks, :localts, :localts, '', :location, CAST(:observation AS jsonb), 'received',
                        session_id, generation, snapshot_sequence, :request FROM captured RETURNING *
                ) INSERT INTO public.core_history_event
                    (session_id, generation, context_sequence, request_id, playthrough_id, eventlog_rowid, event_payload)
                    SELECT synth_session_id, synth_generation, synth_context_sequence, synth_request_id, sess, rowid,
                        to_jsonb(publication)||jsonb_build_object('synth_witness_observation', CAST(:observation AS jsonb), 'synth_dialogue_hearer_ids', CAST(:hearers AS jsonb))
                      FROM publication RETURNING id",
                ['session' => $sessionId, 'generation' => $generation, 'sequence' => $snapshotSequence, 'playthrough' => $playthroughId,
                 'request' => $requestId, 'text' => $text, 'ticks' => $gameTimeTicks, 'localts' => $localTime, 'location' => $location,
                 'observation' => $observationJson, 'hearers' => $hearerIdsJson]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row !== false;
    }

    /** The party JSON of an earlier player_event history row with this event id for this owner: null when none, false when the read failed. */
    public function playerEventParty(string $sessionId, int $generation, string $playthroughId, string $eventId): string|null|false
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT (event_payload->>'party')::jsonb AS party FROM public.core_history_event
                 WHERE session_id = :session AND generation = :generation AND playthrough_id = :playthrough AND event_payload->>'type' = 'player_event'
                   AND (event_payload->>'party')::jsonb->>'event_id' = :event LIMIT 1",
                ['session' => $sessionId, 'generation' => $generation, 'playthrough' => $playthroughId, 'event' => $eventId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row === false ? null : (string) $row['party'];
    }

    /**
     * The acknowledged player_event history row a still-recent active context points at, with the milliseconds elapsed since it
     * was recorded: array{party: string, elapsed_ms: mixed}, null when there is none, false when the read failed.
     *
     * @return array<string, mixed>|null|false
     */
    public function recentPlayerEventEvidence(string $sessionId, int $generation, int $contextSequence, string $eventId): array|null|false
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT (h.event_payload->>'party')::jsonb AS party,
                        EXTRACT(EPOCH FROM (clock_timestamp()-h.recorded_at))*1000 AS elapsed_ms
                 FROM public.core_runtime_context c
                 JOIN public.core_runtime_session r ON r.session_id=c.session_id AND r.active_generation=c.generation
                    AND r.playthrough_id=c.playthrough_id AND r.state='active'
                 JOIN public.core_actor_activation a ON a.session_id=c.session_id AND a.generation=c.generation
                    AND a.actor_identity_id=c.target_identity_id AND a.state='active'
                 JOIN public.core_history_event h ON h.session_id=c.session_id AND h.generation=c.generation
                    AND h.playthrough_id=c.playthrough_id AND h.id<=c.history_event_id
                    AND h.context_sequence<=c.snapshot_sequence AND h.event_payload->>'type'='player_event'
                    AND (h.event_payload->>'party')::jsonb->>'event_id'=:event
                 JOIN public.core_runtime_context original ON original.session_id=h.session_id AND original.generation=h.generation
                    AND original.snapshot_sequence=h.context_sequence AND original.player_identity_id=c.player_identity_id
                 WHERE c.session_id=:session AND c.generation=:generation AND c.snapshot_sequence=:sequence
                   AND c.received_at BETWEEN clock_timestamp()-interval '10 seconds' AND clock_timestamp()",
                ['session' => $sessionId, 'generation' => $generation, 'sequence' => $contextSequence, 'event' => $eventId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row === false ? null : $row;
    }
}
