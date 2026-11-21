<?php

// Reads core_runtime_session. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRuntimeSession;

/** Finds rows of core_runtime_session. */
final class RuntimeSessionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId): ?AbstractRuntimeSession
    {
        return $this->fresh()->find(AbstractRuntimeSession::class, $sessionId);
    }

    /**
     * Picks one active session whose latest completed dialogue turn still needs memory work and reserves it for thirty seconds
     * (FOR UPDATE OF the session row, SKIP LOCKED, inside the caller's open transaction). The turn's origin (session_id, request_id,
     * turn_id, generation, event_type, canonical_event, event_sha256, context_sequence), or null when nothing is due.
     *
     * @return array<string, mixed>|null
     */
    public function reserveMemoryDispatch(): ?array
    {
        $row = $this->connection()->fetchAssociative("WITH candidate AS MATERIALIZED (
            SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,
                o.canonical_event,o.event_sha256,r.active_turn_sequence AS context_sequence
            FROM public.core_runtime_session r
            CROSS JOIN LATERAL (
                SELECT e.request_id,e.turn_id,e.generation,e.event_type,p.canonical_event,p.event_sha256
                FROM public.core_request_execution e
                JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                    AND p.event_sha256=e.request_sha256
                WHERE e.session_id=r.session_id AND e.generation=r.active_generation
                    AND e.turn_id=r.active_turn_id AND e.state='complete'
                    AND e.event_type IN ('input_text','input_audio','trigger','action_result','activate')
                    AND p.canonical_event->>'protocol_version'='2'
                    AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                    AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                    AND jsonb_exists(p.canonical_event->'capabilities','dialogue.turn_ownership')
                    AND (e.event_type<>'activate' OR p.canonical_event->'payload'->>'source'='manual')
                    -- Closed or non-NPC continuations must not hide this turn's completed NPC dialogue.
                    AND (e.event_type<>'trigger' OR p.canonical_event->'payload'->>'kind' IS DISTINCT FROM 'rechat'
                        OR EXISTS (SELECT 1 FROM public.core_rechat_selection selection
                            JOIN public.core_rechat_continuation continuation USING(session_id,request_id)
                            JOIN public.core_rechat_chain chain
                                ON chain.id=continuation.chain_id AND chain.session_id=continuation.session_id
                            WHERE selection.session_id=e.session_id AND selection.request_id=e.request_id
                                AND selection.prepared_at IS NOT NULL AND selection.role='npc'
                                AND continuation.status='reserved'
                                AND COALESCE(chain.terminal_reason,'') NOT IN ('private','no_candidate','ineligible')
                                AND chain.generation=e.generation AND chain.turn_id=e.turn_id
                                AND chain.context_sequence=r.active_turn_sequence AND chain.runtime_variant=r.runtime_variant))
                ORDER BY e.claimed_at DESC,e.request_id DESC LIMIT 1
            ) o
            JOIN public.core_runtime_context c ON c.session_id=r.session_id AND c.generation=r.active_generation
                AND c.snapshot_sequence=r.active_turn_sequence AND c.playthrough_id=r.playthrough_id
                AND c.target_identity_id IS NOT NULL AND c.history_event_id IS NOT NULL
            WHERE r.state='active' AND (r.memory_dispatch_after IS NULL OR r.memory_dispatch_after<=clock_timestamp())
            ORDER BY r.memory_dispatch_after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
        ), reserved AS (
            UPDATE public.core_runtime_session r SET memory_dispatch_after=clock_timestamp()+INTERVAL '30 seconds'
            FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
        ) SELECT c.* FROM candidate c JOIN reserved USING (session_id)");

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRuntimeSession> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRuntimeSession::class)->findBy([], ['sessionId' => 'ASC'], max(1, $limit)));
    }
}
