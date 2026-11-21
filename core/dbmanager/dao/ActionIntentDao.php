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
     * The intent of this action in this generation locked FOR UPDATE until the caller's open transaction ends: id, idempotency key,
     * state, action name and whether its deadline passed more than five minutes ago ('expired'); null when none.
     *
     * @return array<string, mixed>|null
     */
    public function lockIntent(string $actionId, string $sessionId, int $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT action_id, idempotency_key, state, action_name, (deadline_at + INTERVAL '5 minutes' < NOW()) AS expired
               FROM public.core_action_intent WHERE action_id = ? AND session_id = ? AND generation = ? FOR UPDATE",
            [$actionId, $sessionId, $generation]
        );

        return $row === false ? null : $row;
    }

    /** The context sequence and protocol version of the event that issued this action ('context_sequence', 'protocol_version'); null when none. @return array<string, mixed>|null */
    public function issuingEvent(string $actionId, string $sessionId, int $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT e.canonical_event->>'context_sequence' AS context_sequence,
                    e.canonical_event->>'protocol_version' AS protocol_version
               FROM public.core_action_intent i
               JOIN public.core_protocol_event e ON e.session_id=i.session_id AND e.request_id=i.request_id AND e.generation=i.generation
              WHERE i.action_id = ? AND i.session_id = ? AND i.generation = ?",
            [$actionId, $sessionId, $generation]
        );

        return $row === false ? null : $row;
    }

    /**
     * The recorded mutation owner of an inventory action: issuing turn and request, action name and capability, the actor and
     * recipient identities, and the issuing canonical event; only while the intent is live and its turn is the session's active one.
     *
     * @return array<string, mixed>|null
     */
    public function inventoryOwner(string $actionId, string $sessionId, int $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.turn_id, i.request_id, i.action_name, i.capability,
                    a.full_form_id, a.origin_plugin, a.playthrough_id, e.canonical_event,
                    t.full_form_id AS recipient_form_id, t.origin_plugin AS recipient_plugin,
                    t.playthrough_id AS recipient_playthrough_id
                FROM public.core_action_intent i
                JOIN public.core_actor_identity a ON a.id=i.actor_identity_id AND a.game='fo4'
                LEFT JOIN public.core_actor_identity t ON t.id=i.target_identity_id AND t.game='fo4'
                JOIN public.core_protocol_event e ON e.session_id=i.session_id AND e.request_id=i.request_id
                    AND e.generation=i.generation AND e.turn_id=i.turn_id
                JOIN public.core_runtime_session r ON r.session_id=i.session_id AND r.playthrough_id=a.playthrough_id
                WHERE i.action_id = ? AND i.session_id = ? AND i.generation = ?
                    AND i.state IN ('pending','dispatched','complete')
                    AND r.state='active' AND r.active_generation = ? AND r.active_turn_id=i.turn_id
                    AND r.active_turn_sequence::text=e.canonical_event->>'context_sequence'
                    AND r.runtime_variant=e.canonical_event->>'runtime_variant'",
            [$actionId, $sessionId, $generation, $generation]
        );

        return $row === false ? null : $row;
    }

    /** Marks the action's intent complete. False when the statement fails. */
    public function markComplete(string $actionId): bool
    {
        return $this->tryStatement("UPDATE public.core_action_intent SET state='complete' WHERE action_id = ?", [$actionId]);
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
