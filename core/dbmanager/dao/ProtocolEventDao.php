<?php

// Reads core_protocol_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractProtocolEvent;

/** Finds rows of core_protocol_event. */
final class ProtocolEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractProtocolEvent
    {
        return $this->fresh()->find(AbstractProtocolEvent::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractProtocolEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractProtocolEvent::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** The request id of the first quest_updated trigger recorded for this context sequence, or null. */
    public function firstQuestUpdateRequestId(string $sessionId, int $generation, string $contextSequence): ?string
    {
        $id = $this->connection()->fetchOne(
            "SELECT request_id FROM public.core_protocol_event
             WHERE session_id = ? AND generation = ? AND event_type = 'trigger'
               AND canonical_event->'payload'->>'kind' = 'quest_updated' AND canonical_event->>'context_sequence' = ? ORDER BY id LIMIT 1",
            [$sessionId, $generation, $contextSequence]
        );

        return $id === false ? null : (string) $id;
    }
}
