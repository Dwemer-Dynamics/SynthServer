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

    /**
     * The request id of the first trigger of this generation that carries this player reaction or companion combat comment
     * event id for this playthrough: null when none, false when the read failed.
     */
    public function firstReactionRequestId(string $sessionId, int $generation, string $eventId, string $playthroughId): string|null|false
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT request_id FROM public.core_protocol_event
                 WHERE session_id = :session AND generation = :generation AND event_type = 'trigger'
                   AND canonical_event->'payload'->>'kind' IN ('player_reaction','companion_combat_comment')
                   AND canonical_event->'payload'->>'event_id' = :event
                   AND canonical_event->'payload'->'actor'->>'playthrough_id' = :playthrough ORDER BY id LIMIT 1",
                ['session' => $sessionId, 'generation' => $generation, 'event' => $eventId, 'playthrough' => $playthroughId]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }

        return $row === false ? null : (string) $row['request_id'];
    }
}
