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
}
