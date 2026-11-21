<?php

// Reads core_history_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractHistoryEvent;

/** Finds rows of core_history_event. */
class HistoryEventDao
{
    use UsesEntityManager;
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
