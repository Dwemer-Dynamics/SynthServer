<?php

// Reads core_protocol_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractProtocolEvent;

/** Finds rows of core_protocol_event. */
class ProtocolEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractProtocolEvent
    {
        return $this->fresh()->find(AbstractProtocolEvent::class, $id);
    }

    /** Stores the canonical event of a claimed request. */
    public function insertClaim(string $sessionId, string $requestId, string $turnId, int $generation, string $eventType, string $canonicalJson, string $eventSha256): bool
    {
        return $this->tryStatement(
            'INSERT INTO public.core_protocol_event (session_id, request_id, turn_id, generation, event_type, canonical_event, event_sha256)
             VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb), ?)',
            [$sessionId, $requestId, $turnId, $generation, $eventType, $canonicalJson, $eventSha256]
        );
    }

    /** The turn id and canonical event (JSON text) of an earlier request of this generation, or null. @return array<string, mixed>|null */
    public function findAnchor(string $sessionId, int $generation, string $requestId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT turn_id, canonical_event FROM public.core_protocol_event WHERE session_id = ? AND generation = ? AND request_id = ?',
            [$sessionId, $generation, $requestId]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractProtocolEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractProtocolEvent::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
