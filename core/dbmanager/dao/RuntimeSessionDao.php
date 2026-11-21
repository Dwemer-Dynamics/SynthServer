<?php

// Reads core_runtime_session. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRuntimeSession;

/** Finds rows of core_runtime_session. */
final class RuntimeSessionDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(string $sessionId): ?AbstractRuntimeSession
    {
        return $this->fresh()->find(AbstractRuntimeSession::class, $sessionId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRuntimeSession> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRuntimeSession::class)->findBy([], ['sessionId' => 'ASC'], max(1, $limit)));
    }

    /**
     * Reserves the one active session whose completed owner request is next for relationship dispatch: locks its runtime row
     * (FOR UPDATE ... SKIP LOCKED) and pushes its dispatch cooldown two seconds out in the same statement. Returns the owner
     * request's frozen origin, or null when no session is eligible. The caller owns the surrounding transaction, so the row
     * lock lasts exactly until it commits or rolls back.
     *
     * @return array<string, string|null>|null
     */
    public function reserveRelationshipDispatch(bool $interaction, bool $deliveryInline): ?array
    {
        $after=$interaction ? 'interaction_dispatch_after' : 'relationship_dispatch_after';
        $control=$interaction ? '' : "AND NOT EXISTS(SELECT 1 FROM public.core_relationship_actor_control control
            WHERE control.actor_identity_id=c.target_identity_id AND control.locked)";
        $inlineScope=$deliveryInline ? "AND EXISTS(SELECT 1 FROM public.core_relationship_response_input response
            WHERE response.session_id=e.session_id AND response.request_id=e.request_id
                AND response.response_payload->>'evaluation_mode'='inline'
                AND jsonb_typeof(response.response_payload->'delivery_lines')='array'
                AND NOT EXISTS(SELECT 1 FROM public.core_relationship_revision_source applied
                    JOIN public.core_relationship_revision revision ON revision.history_event_id=applied.relationship_history_event_id
                    JOIN public.core_history_event publication ON publication.id=revision.history_event_id
                    WHERE applied.source_history_event_id=response.history_event_id AND revision.actor_identity_id=response.actor_identity_id
                        AND publication.session_id=e.session_id AND publication.generation=e.generation AND publication.request_id=e.request_id))" : '';
        $row = $this->connection()->fetchAssociative(
"WITH candidate AS MATERIALIZED (
            SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,
                o.canonical_event,o.event_sha256,r.active_turn_sequence AS context_sequence
            FROM public.core_runtime_session r
            CROSS JOIN LATERAL (
                SELECT e.request_id,e.turn_id,e.generation,e.event_type,p.canonical_event,p.event_sha256
                FROM public.core_request_execution e
                JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
                WHERE e.session_id=r.session_id AND e.generation=r.active_generation AND e.turn_id=r.active_turn_id AND e.state='complete'
                    AND e.event_type IN ('input_text','input_audio','trigger','action_result','activate')
                    AND p.canonical_event->>'protocol_version'='2' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                    AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                    AND p.canonical_event->'capabilities' ? 'dialogue.turn_ownership'
                    $inlineScope
                    AND (e.event_type<>'activate' OR p.canonical_event->'payload'->>'source'='manual')
                    -- A terminal or non-NPC continuation cannot replace a usable completed dialogue owner.
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
            WHERE r.state='active' $control AND (r.$after IS NULL OR r.$after<=clock_timestamp())
            ORDER BY r.$after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
        ), reserved AS (
            UPDATE public.core_runtime_session r SET $after=clock_timestamp()+INTERVAL '2 seconds'
            FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
        ) SELECT c.* FROM candidate c JOIN reserved USING(session_id)"
        );

        return self::textRow($row);
    }
}
