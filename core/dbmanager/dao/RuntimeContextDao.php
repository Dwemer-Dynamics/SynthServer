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
     * The player identity of one context snapshot, only when it is a fo4 actor of the snapshot's playthrough whose form id, origin plugin
     * and playthrough match the snapshot's saved player; 0 when there is none.
     */
    public function savedPlayerIdentityId(string $sessionId, int $generation, int $snapshotSequence): int
    {
        $id = $this->connection()->fetchOne(
            "SELECT c.player_identity_id FROM public.core_runtime_context c JOIN public.core_actor_identity a
                ON a.id=c.player_identity_id AND a.game='fo4' AND a.playthrough_id=c.playthrough_id
                    AND upper(a.full_form_id)=upper(c.context_payload#>>'{player,form_id}')
                    AND lower(a.origin_plugin)=lower(c.context_payload#>>'{player,origin_plugin}')
                    AND a.playthrough_id=c.context_payload#>>'{player,playthrough_id}'
             WHERE c.session_id=? AND c.generation=? AND c.snapshot_sequence=?",
            [$sessionId, $generation, $snapshotSequence]
        );

        return $id === false || $id === null ? 0 : (int) $id;
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
}
