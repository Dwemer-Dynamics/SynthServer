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
     * True when this request has a protocol event past the control barrier and, if a response is named, that response's own
     * request also lies past it for this actor.
     */
    public function pastControlBarrier(string $sessionId, string $requestId, int|string $barrierEventId, ?int $responseId, int $actorId): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_protocol_event p
            WHERE p.session_id=:p1 AND p.request_id=:p2 AND p.id>:p3
                AND (:p4::bigint IS NULL OR EXISTS(SELECT 1 FROM public.core_relationship_response_input i
                    JOIN public.core_protocol_event origin ON origin.session_id=i.session_id AND origin.request_id=i.request_id
                    WHERE i.history_event_id=:p4 AND i.actor_identity_id=:p5 AND origin.id>:p3))',
            ['p1' => $sessionId, 'p2' => $requestId, 'p3' => $barrierEventId, 'p4' => $responseId, 'p5' => $actorId]
        ) !== false;
    }
}
