<?php

// Reads core_runtime_context. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRuntimeContext;

/** Finds rows of core_runtime_context. */
final class RuntimeContextDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, int|string $generation, int|string $snapshotSequence): ?AbstractRuntimeContext
    {
        return $this->fresh()->find(AbstractRuntimeContext::class, ['sessionId' => $sessionId, 'generation' => $generation, 'snapshotSequence' => $snapshotSequence]);
    }

    /** The target identity, playthrough and context payload of one saved snapshot (the owner a memory job writes for), or null. @return array<string, mixed>|null */
    public function memoryOwner(string $sessionId, int|string $generation, int|string $snapshotSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT target_identity_id,playthrough_id,context_payload
        FROM public.core_runtime_context WHERE session_id=? AND generation=? AND snapshot_sequence=?',
            [$sessionId, $generation, $snapshotSequence]
        );

        return $row === false ? null : $row;
    }

    /** The player identity of one saved snapshot, or 0 when there is none. */
    public function playerIdentityId(string $sessionId, int|string $generation, int|string $snapshotSequence): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT player_identity_id FROM public.core_runtime_context WHERE session_id=? AND generation=? AND snapshot_sequence=?',
            [$sessionId, $generation, $snapshotSequence]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRuntimeContext> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRuntimeContext::class)->findBy([], ['sessionId' => 'ASC', 'generation' => 'ASC', 'snapshotSequence' => 'ASC'], max(1, $limit)));
    }

    /**
     * The fresh scene context a rechat child names, with the previous and root contexts and the parent event it continues.
     * Parameters, in order: session id, generation, scene context sequence, turn id, child request id, child canonical event
     * JSON, parent request id, child context sequence, child event sha256, runtime variant, 't'|'f' (also accept the child's
     * own failed, unrevoked execution). [] when there is none, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function sceneContext(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT fresh.context_payload,previous.context_payload AS previous_context,
            p.canonical_event AS parent_event,root.context_payload AS root_context
        FROM public.core_runtime_context fresh
        JOIN public.core_runtime_session s ON s.session_id=fresh.session_id
            AND s.active_generation=fresh.generation AND s.playthrough_id=fresh.playthrough_id AND s.state='active'
        JOIN public.core_request_execution child ON child.session_id=s.session_id
            AND child.request_id=:p5 AND child.generation=fresh.generation AND child.turn_id=:p4
        JOIN public.core_protocol_event cp ON cp.session_id=child.session_id AND cp.request_id=child.request_id
            AND cp.generation=child.generation AND cp.turn_id=child.turn_id AND cp.event_type=child.event_type
            AND cp.event_sha256=child.request_sha256 AND cp.canonical_event=:p6::jsonb AND cp.event_sha256=:p9
        JOIN public.core_protocol_event p ON p.session_id=s.session_id AND p.request_id=:p7
            AND p.generation=child.generation AND p.turn_id=child.turn_id
            AND p.canonical_event->>'context_sequence'=:p8::text
        JOIN public.core_request_execution parent ON parent.session_id=p.session_id AND parent.request_id=p.request_id
            AND parent.generation=p.generation AND parent.turn_id=p.turn_id AND parent.event_type=p.event_type
            AND parent.request_sha256=p.event_sha256 AND parent.state='complete'
        JOIN public.core_runtime_context previous ON previous.session_id=fresh.session_id
            AND previous.generation=fresh.generation AND previous.playthrough_id=fresh.playthrough_id
            AND previous.player_identity_id=fresh.player_identity_id
            AND previous.snapshot_sequence=COALESCE((p.canonical_event#>>'{payload,scene_context_sequence}')::bigint,:p8::bigint)
        JOIN public.core_runtime_context root ON root.session_id=fresh.session_id AND root.generation=fresh.generation
            AND root.playthrough_id=fresh.playthrough_id AND root.snapshot_sequence=:p8::bigint
        WHERE fresh.session_id=:p1 AND fresh.generation=:p2 AND fresh.snapshot_sequence=:p3
            AND fresh.snapshot_sequence>previous.snapshot_sequence AND s.runtime_variant=:p10
            AND (child.state IN ('claimed','streaming','complete') OR (:p11::boolean AND child.state='failed'
                AND COALESCE(child.error_code,'') NOT IN ('turn_cancelled','request_cancelled','superseded_turn')))
            AND fresh.received_at BETWEEN child.claimed_at-interval '10 seconds' AND child.claimed_at", $parameters);
    }

    /** The context of an earlier snapshot of the same player and playthrough as a later one. [] when none, null when the read fails. @return array<string, mixed>|null */
    public function previousSnapshotContext(string $sessionId, int $generation, int $previousSequence, int $freshSequence): ?array
    {
        return $this->rowOrNull("SELECT previous.context_payload
            FROM public.core_runtime_context previous JOIN public.core_runtime_context fresh
                ON fresh.session_id=previous.session_id AND fresh.generation=previous.generation
                AND fresh.playthrough_id=previous.playthrough_id AND fresh.player_identity_id=previous.player_identity_id
            WHERE previous.session_id=:p1 AND previous.generation=:p2 AND previous.snapshot_sequence=:p3
                AND fresh.snapshot_sequence=:p4", [$sessionId, $generation, $previousSequence, $freshSequence]);
    }

    /**
     * Playthrough and receive time of the newest accepted game context whose session and player identity agree on the
     * playthrough and game; null when there is none.
     *
     * @return array{playthrough_id: string, received_at: string}|null
     */
    public function latestAcceptedForPlayer(): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT c.playthrough_id, c.received_at FROM public.core_runtime_context c
             JOIN public.core_runtime_session s ON s.session_id = c.session_id AND s.playthrough_id = c.playthrough_id
             JOIN public.core_actor_identity p ON p.id = c.player_identity_id AND p.playthrough_id = c.playthrough_id AND p.game = s.game
             ORDER BY c.received_at DESC, c.snapshot_sequence DESC, c.session_id ASC LIMIT 1'
        );

        return $row === false ? null : $row;
    }

    /**
     * The context payload of the newest earlier snapshot that completed or partly observed the active quests, for a context whose
     * session is active and whose target actor is active, when it is the snapshot with sequence $previousSequence; null otherwise.
     */
    public function previousQuestContextPayload(string $sessionId, int $generation, int $sequence, int $previousSequence): ?string
    {
        $payload = $this->connection()->fetchOne(
            "SELECT p.context_payload
             FROM public.core_runtime_context c
             JOIN public.core_runtime_session r ON r.session_id = c.session_id AND r.active_generation = c.generation
                AND r.playthrough_id = c.playthrough_id AND r.state = 'active'
             JOIN public.core_actor_activation a ON a.session_id = c.session_id AND a.generation = c.generation
                AND a.actor_identity_id = c.target_identity_id AND a.state = 'active'
             JOIN public.core_runtime_context p ON p.session_id = c.session_id AND p.generation = c.generation
                AND p.playthrough_id = c.playthrough_id AND p.player_identity_id = c.player_identity_id AND p.snapshot_sequence = ?
             WHERE c.session_id = ? AND c.generation = ? AND c.snapshot_sequence = ?
               AND p.snapshot_sequence = (SELECT max(q.snapshot_sequence) FROM public.core_runtime_context q
                WHERE q.session_id = c.session_id AND q.generation = c.generation AND q.playthrough_id = c.playthrough_id
                  AND q.player_identity_id = c.player_identity_id AND q.snapshot_sequence < c.snapshot_sequence
                  AND q.context_payload->>'active_quests_observation' IN ('complete', 'partial'))",
            [$previousSequence, $sessionId, $generation, $sequence]
        );

        return $payload === false ? null : (string) $payload;
    }

    /**
     * The context payload of the snapshot a claimed or streaming request answers, with the age of that frame in milliseconds and
     * whether it is fresh (received within ten seconds before the claim, the claim within thirty seconds). Null unless the event
     * is the stored canonical event of the request, the request is live and the session's active turn is this one.
     *
     * @return array<string, mixed>|null
     */
    public function combatFrame(string $sessionId, string $requestId, string $turnId, int $generation, int $sequence, string $canonicalJson, string $sha256): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT c.context_payload,
                    GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (clock_timestamp() - c.received_at)) * 1000)) AS combat_frame_age_ms,
                    (c.received_at <= e.claimed_at AND c.received_at >= e.claimed_at - INTERVAL '10 seconds'
                     AND e.claimed_at >= clock_timestamp() - INTERVAL '30 seconds') AS combat_frame_fresh
             FROM public.core_protocol_event p
             JOIN public.core_request_execution e USING (session_id, request_id)
             JOIN public.core_runtime_session r USING (session_id)
             JOIN public.core_runtime_context c ON c.session_id = p.session_id AND c.generation = p.generation AND c.snapshot_sequence = ?
             WHERE p.session_id = ? AND p.request_id = ? AND p.generation = ? AND p.turn_id = ?
               AND p.canonical_event = CAST(? AS jsonb) AND p.event_sha256 = ? AND e.request_sha256 = ?
               AND e.state IN ('claimed', 'streaming') AND COALESCE(e.error_code, '') NOT IN ('turn_cancelled', 'request_cancelled')
               AND r.state = 'active' AND r.active_generation = ? AND r.active_turn_id = ? AND r.active_turn_sequence = ?
               AND r.playthrough_id = c.playthrough_id",
            [$sequence, $sessionId, $requestId, $generation, $turnId, $canonicalJson, $sha256, $sha256, $generation, $turnId, $sequence]
        );

        return $row === false ? null : $row;
    }

    /** Whether this snapshot is the newest of its generation, received within the last five seconds, in the active flat session. */
    public function jevCaptureFresh(string $sessionId, int $generation, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_runtime_context c
             JOIN public.core_runtime_session r ON r.session_id = c.session_id
             WHERE c.session_id = ? AND c.generation = ? AND c.snapshot_sequence = ?
               AND r.state = 'active' AND r.active_generation = c.generation AND r.runtime_variant = 'flat'
               AND c.playthrough_id = r.playthrough_id
               AND c.received_at <= clock_timestamp() AND c.received_at >= clock_timestamp() - INTERVAL '5 seconds'
               AND NOT EXISTS (SELECT 1 FROM public.core_runtime_context newer
                   WHERE newer.session_id = c.session_id AND newer.generation = c.generation AND newer.snapshot_sequence > c.snapshot_sequence)",
            [$sessionId, $generation, $sequence]
        ) !== false;
    }
}
