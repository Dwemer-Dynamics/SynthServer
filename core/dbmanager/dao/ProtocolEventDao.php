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
     * The stored canonical event and its hash for a request whose execution completed, on a still active session of the same
     * runtime variant, for this event type; null when no such request exists.
     *
     * @return array<string, mixed>|null canonical_event and event_sha256
     */
    public function completedOrigin(string $sessionId, string $requestId, string $eventType): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT p.canonical_event, p.event_sha256 FROM public.core_protocol_event p
             JOIN public.core_request_execution e ON e.session_id = p.session_id AND e.request_id = p.request_id
                AND e.generation = p.generation AND e.turn_id = p.turn_id AND e.event_type = p.event_type
                AND e.request_sha256 = p.event_sha256 AND e.state = 'complete'
             JOIN public.core_runtime_session r ON r.session_id = e.session_id AND r.active_generation = e.generation
                AND r.state = 'active' AND p.canonical_event->>'runtime_variant' = r.runtime_variant
             WHERE p.session_id = ? AND p.request_id = ? AND p.event_type = ?",
            [$sessionId, $requestId, $eventType]
        );

        return $row === false ? null : $row;
    }
}
