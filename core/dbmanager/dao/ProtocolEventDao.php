<?php

// Reads core_protocol_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractProtocolEvent;

/** Finds rows of core_protocol_event. */
class ProtocolEventDao
{
    use UsesEntityManager;
    use FailSoftQueries;

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

    /**
     * The proven action ancestry of one action_result: the intent, its recorded result, the accepted receipt event and the
     * emitting request, joined as the rechat ancestry walk requires. Parameters, in order: action id, idempotency key,
     * session id, generation, turn id, result JSON, runtime variant, receipt request id, receipt canonical JSON, receipt
     * sha256, 't'|'f' (also accept the receipt's own failed, unrevoked execution). Null when the statement fails, [] when
     * no row proves the ancestry.
     *
     * @return array<string, mixed>|null
     */
    public function actionAncestry(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT p.canonical_event,p.event_sha256,p.event_type,e.response_body,
                i.request_id,i.capability,i.arguments,a.full_form_id,a.origin_plugin,a.playthrough_id,
                target.full_form_id AS target_form_id,target.origin_plugin AS target_plugin,target.playthrough_id AS target_playthrough
            FROM public.core_action_intent i
            JOIN public.core_action_result t ON t.action_id=i.action_id AND t.idempotency_key=i.idempotency_key
            JOIN public.core_protocol_event receipt ON receipt.session_id=i.session_id AND receipt.request_id=:p8
                AND receipt.generation=i.generation AND receipt.turn_id=i.turn_id AND receipt.event_type='action_result'
                AND receipt.canonical_event=:p9::jsonb AND receipt.event_sha256=:p10
                AND receipt.canonical_event->'payload'->'result'=t.result
            JOIN public.core_request_execution accepted ON accepted.session_id=receipt.session_id
                AND accepted.request_id=receipt.request_id AND accepted.generation=receipt.generation
                AND accepted.turn_id=receipt.turn_id AND accepted.event_type=receipt.event_type
                AND accepted.request_sha256=receipt.event_sha256 AND (accepted.state IN ('claimed','streaming','complete')
                    OR (:p11::boolean AND accepted.state='failed'
                        AND COALESCE(accepted.error_code,'') NOT IN ('turn_cancelled','request_cancelled','superseded_turn')))
            JOIN public.core_request_execution e ON e.session_id=i.session_id AND e.request_id=i.request_id
                AND e.generation=i.generation AND e.turn_id=i.turn_id
            JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                AND p.event_sha256=e.request_sha256
            JOIN public.core_runtime_session s ON s.session_id=i.session_id
            JOIN public.core_actor_identity a ON a.id=i.actor_identity_id AND a.game='fo4' AND a.playthrough_id=s.playthrough_id
            LEFT JOIN public.core_actor_identity target ON target.id=i.target_identity_id
            WHERE i.action_id=:p1 AND i.idempotency_key=:p2 AND i.session_id=:p3 AND i.generation=:p4 AND i.turn_id=:p5
                AND i.state='complete' AND t.result=:p6::jsonb AND t.status=t.result->>'status'
                AND s.runtime_variant=:p7 AND e.state='complete'", $parameters);
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
