<?php

// Reads core_action_intent. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionIntent;

/** Finds rows of core_action_intent. */
class ActionIntentDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $actionId): ?AbstractActionIntent
    {
        return $this->fresh()->find(AbstractActionIntent::class, $actionId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActionIntent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActionIntent::class)->findBy([], ['actionId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The facts an action result is formatted from: action name, capability, arguments, the target's and actor's names and the actor
     * and target identities (form id, plugin, playthrough); null when the action is unknown to this session generation.
     *
     * @return array<string, mixed>|null
     */
    public function resultBinding(string $actionId, string $sessionId, int $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.action_name, i.capability, i.arguments, COALESCE(t.display_name, a.display_name, '') AS target_name,
                    COALESCE(a.display_name, '') AS actor_name,
                    a.full_form_id AS actor_form_id, a.origin_plugin AS actor_plugin, a.playthrough_id AS actor_playthrough,
                    t.full_form_id AS target_form_id, t.origin_plugin AS target_plugin, t.playthrough_id AS target_playthrough
               FROM public.core_action_intent i
               JOIN public.core_actor_identity a ON a.id=i.actor_identity_id
               LEFT JOIN public.core_actor_identity t ON t.id=i.target_identity_id
              WHERE i.action_id = ? AND i.session_id = ? AND i.generation = ?",
            [$actionId, $sessionId, $generation]
        );

        return $row === false ? null : $row;
    }

    /**
     * The acting identity (form id, plugin, playthrough) of a live action result owned by this exact turn, context sequence and
     * runtime variant of an active session; null when no intent matches.
     *
     * @return array<string, mixed>|null
     */
    public function resultOwnerActor(string $actionId, string $idempotencyKey, string $sessionId, string $turnId, int $generation, string $contextSequence, string $runtimeVariant): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT a.full_form_id,a.origin_plugin,a.playthrough_id
               FROM public.core_action_intent i
               JOIN public.core_protocol_event e ON e.session_id=i.session_id AND e.request_id=i.request_id
                AND e.generation=i.generation AND e.turn_id=i.turn_id
               JOIN public.core_actor_identity a ON a.id=i.actor_identity_id AND a.game='fo4'
               JOIN public.core_runtime_session r ON r.session_id=i.session_id AND r.playthrough_id=a.playthrough_id
              WHERE i.action_id = ? AND i.idempotency_key = ? AND i.session_id = ?
                AND i.turn_id = ? AND i.generation = ?
                AND r.state='active' AND r.active_generation = ? AND r.runtime_variant = ?
                AND e.canonical_event->>'protocol_version'='2'
                AND e.canonical_event->>'session_id' = ? AND e.canonical_event->>'turn_id' = ?
                AND e.canonical_event->>'generation' = ?
                AND e.canonical_event->>'context_sequence' = ?
                AND e.canonical_event->>'runtime_variant' = ?",
            [$actionId, $idempotencyKey, $sessionId, $turnId, $generation, $generation, $runtimeVariant,
                $sessionId, $turnId, (string) $generation, $contextSequence, $runtimeVariant]
        );

        return $row === false ? null : $row;
    }

    /**
     * The live intent of an action with its actor and target identities. Parameters, in order: action id, idempotency key,
     * session id, request id, generation, turn id, deadline in milliseconds. [] when none is live, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function liveForEmission(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT i.*,a.full_form_id,a.origin_plugin,a.playthrough_id,
                target.full_form_id AS target_form_id,target.origin_plugin AS target_plugin,target.playthrough_id AS target_playthrough
            FROM public.core_action_intent i JOIN public.core_actor_identity a ON a.id=i.actor_identity_id
            LEFT JOIN public.core_actor_identity target ON target.id=i.target_identity_id
            WHERE i.action_id=:p1 AND i.idempotency_key=:p2 AND i.session_id=:p3 AND i.request_id=:p4 AND i.generation=:p5 AND i.turn_id=:p6
                AND i.state IN ('pending','dispatched') AND i.deadline_at>clock_timestamp()
                AND EXTRACT(EPOCH FROM (i.deadline_at-i.created_at))*1000 >= :p7", $parameters);
    }
}
