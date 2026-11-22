<?php

// Reads core_history_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractHistoryEvent;

/** Finds rows of core_history_event. */
class HistoryEventDao
{
    use UsesEntityManager;
    use TextRows;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractHistoryEvent
    {
        return $this->fresh()->find(AbstractHistoryEvent::class, $id);
    }

    // Reads over the saved-history CTE. $historyCte is the fixed WITH list built by synth_protocol_history_cte(); every value is bound.

    /** The reader identity of the first history scope the CTE resolves, or 0 when it resolves none. */
    public function scopeReaderIdentityId(string $historyCte): int
    {
        $historyCte = JsonbOperatorSql::plain($historyCte);
        return (int) $this->connection()->fetchOne($historyCte.' SELECT DISTINCT reader_identity_id FROM native_history_scopes LIMIT 1');
    }

    /**
     * The newest $limit event texts of the given types in the visible history, newest first. $visibleChatSql is the fixed delivery-state
     * condition built by synthBuildChatDeliveryStateSql(); chat events count only when it holds.
     *
     * @param list<string> $types
     * @return list<array<string, mixed>>
     */
    public function recentDataByType(string $historyCte, array $types, string $visibleChatSql, int $limit): array
    {
        $historyCte = JsonbOperatorSql::plain($historyCte);
        if ($types === []) return [];
        $marks = implode(',', array_fill(0, count($types), '?'));

        return $this->connection()->fetchAllAssociative(
            $historyCte."SELECT data FROM native_history WHERE type IN ({$marks})"
            ." AND (type<>'chat' OR {$visibleChatSql})"
            ." ORDER BY gamets DESC,ts DESC,rowid DESC LIMIT {$limit}",
            array_values($types)
        );
    }

    /**
     * The newest $limit dialogue lines (speech) the reader said or heard after $afterGamets, newest first, in the visible history.
     *
     * @return list<array<string, mixed>>
     */
    public function recentDialogueSince(string $historyCte, int $afterGamets, string $visibleChatSql, int $limit): array
    {
        $historyCte = JsonbOperatorSql::plain($historyCte);
        return $this->connection()->fetchAllAssociative(
            $historyCte."SELECT substring(data FROM position(':' IN data)+1) AS speech FROM native_history"
            ." WHERE type IN ('chat','inputtext','inputtext_s') AND gamets>".$afterGamets
            ." AND speaker_identity_id IN (reader_identity_id,reader_player_id)"
            ." AND (type<>'chat' OR {$visibleChatSql})"
            ." ORDER BY gamets DESC,ts DESC,rowid DESC LIMIT {$limit}"
        );
    }

    /** The in-game hours spanned by the newest $limit spoken chat events of the visible history, or 0 when there are none. */
    public function recentChatSpanHours(string $historyCte, int $limit): float
    {
        $historyCte = JsonbOperatorSql::plain($historyCte);
        $hours = $this->connection()->fetchOne(
            $historyCte."SELECT (MAX(gamets)-MIN(gamets))*0.0000024 AS hours
                FROM (SELECT gamets FROM native_history WHERE type='chat'
                    AND COALESCE(delivery_state,'spoken') IN ('emitted','spoken','presented')
                    ORDER BY gamets DESC,ts DESC,history_event_id DESC LIMIT {$limit}) recent_chat"
        );

        return $hours === false || $hours === null ? 0.0 : (float) $hours;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractHistoryEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractHistoryEvent::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * A SQL boolean over history rows (columns type and history_event_id) that prefers dialogue rows of one conversation:
     * every non-dialogue row counts as preferred, a dialogue row only when its protocol event carries the conversation id.
     * The values are quoted literals, because the text is spliced into the caller's own history statement.
     */
    public function conversationRankSql(string $sessionId, string $conversationId): string
    {
        $session = $this->connection()->quote($sessionId);
        $conversation = $this->connection()->quote($conversationId);

        return "(type NOT IN ('chat','inputtext','inputtext_s','backgroundchat') OR EXISTS (SELECT 1 FROM public.core_history_event conv_h
            JOIN public.core_protocol_event conv_e ON conv_e.session_id=conv_h.session_id AND conv_e.request_id=conv_h.request_id
                AND conv_e.generation=conv_h.generation
            WHERE conv_h.id=history_event_id AND conv_h.session_id={$session}
                AND conv_e.canonical_event#>>'{payload,conversation_id}'={$conversation}))";
    }

    /**
     * The processed text inputs of one request at one context sequence, oldest first, at most two (a third would already be
     * ambiguous to the caller).
     *
     * @return list<array{id: string, event_payload: string}>
     */
    public function processedInputs(string $sessionId, int $generation, string $requestId, int $contextSequence, string $playthroughId): array
    {
        return self::textRows($this->connection()->fetchAllAssociative(
            "SELECT id,event_payload FROM public.core_history_event
                    WHERE session_id=:p1 AND generation=:p2 AND request_id=:p3 AND context_sequence=:p4
                        AND playthrough_id=:p5 AND event_payload->>'type' IN ('inputtext','inputtext_s')
                    ORDER BY id LIMIT 2",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $requestId, 'p4' => $contextSequence, 'p5' => $playthroughId]
        ));
    }

    /** True when this event still carries exactly this payload type and data. */
    public function hasTypeAndData(int $id, string $type, string $data): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_history_event WHERE id=:p1 AND event_payload->>'type'=:p2 AND event_payload->>'data'=:p3",
            ['p1' => $id, 'p2' => $type, 'p3' => $data]
        ) !== false;
    }

    /**
     * How many of $sourceIds are visible relationship sources. $visibleCte is the reader's visibility CTE chain ending in
     * `relationship_visible_sources` (built by the relationship manager from the saved frontier; it contains the jsonb `?` operator, so
     * this statement binds no parameters and the ids are validated integers).
     *
     * @param list<int> $sourceIds
     */
    public function visibleSourceCount(string $visibleCte, array $sourceIds): int
    {
        return (int) $this->connection()->fetchOne(
            $visibleCte.'SELECT count(*) AS n FROM relationship_visible_sources WHERE history_event_id IN ('.implode(',', array_map('intval', $sourceIds)).')'
        );
    }

    /**
     * The newest ten visible dialogue events (oldest first) the reader's frozen relationship evaluation may quote. $visibleCte is the
     * reader's visibility CTE chain; the statement binds no parameters (see visibleSourceCount()).
     *
     * @return list<array{history_event_id: string, type: string|null, data: string|null}>
     */
    public function recentVisibleEvents(string $visibleCte): array
    {
        $rows = self::textRows($this->connection()->fetchAllAssociative(
            $visibleCte."SELECT h.history_event_id,h.type,h.data FROM native_history h JOIN relationship_visible_sources s USING(history_event_id)
                    ORDER BY h.gamets DESC NULLS LAST,h.ts DESC NULLS LAST,h.history_event_id DESC LIMIT 10"
        ));

        return array_reverse($rows);
    }

    /**
     * The reader's own frontier row (depth 1 of the saved ancestry) for the request's saved context, or null. $historyCte is the
     * history visibility CTE chain ending in `native_ancestry`; the statement binds no parameters (see visibleSourceCount()).
     *
     * @return array<string, string|null>|null
     */
    public function readerFrontier(string $historyCte): ?array
    {
        return self::textRow($this->connection()->fetchAssociative($historyCte.'SELECT * FROM native_ancestry WHERE depth=1'));
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
