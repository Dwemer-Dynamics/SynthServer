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
     * Records an issued action as a pending intent whose deadline is $deadlineMs from now. A null target stores NULL. False when the
     * statement fails (the caller raises its own error).
     */
    public function insertIntent(string $actionId, string $idempotencyKey, string $sessionId, string $requestId, string $turnId, int $generation, string $actionName, string $capability, int $actorIdentityId, ?int $targetIdentityId, string $argumentsJson, int $deadlineMs): bool
    {
        return $this->tryStatement(
            "INSERT INTO public.core_action_intent
                (action_id, idempotency_key, session_id, request_id, turn_id, generation,
                 action_name, capability, actor_identity_id, target_identity_id, arguments, deadline_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), NOW() + (? * INTERVAL '1 millisecond'))",
            [$actionId, $idempotencyKey, $sessionId, $requestId, $turnId, $generation, $actionName, $capability,
                $actorIdentityId, $targetIdentityId, $argumentsJson, $deadlineMs]
        );
    }

    /**
     * The intent of an earlier action in this turn together with the canonical event that issued it, only while the runtime session
     * is active in this generation and variant; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function followupOrigin(string $actionId, string $idempotencyKey, string $sessionId, int $generation, string $turnId, string $runtimeVariant): ?array
    {
        try {
        $row = $this->connection()->fetchAssociative(
                "SELECT i.action_name, e.canonical_event
                   FROM public.core_action_intent i
                   JOIN public.core_protocol_event e ON e.session_id=i.session_id
                    AND e.request_id=i.request_id AND e.generation=i.generation AND e.turn_id=i.turn_id
                   JOIN public.core_runtime_session r ON r.session_id=i.session_id
                  WHERE i.action_id = ? AND i.idempotency_key = ?
                    AND i.session_id = ? AND i.generation = ? AND i.turn_id = ?
                    AND r.state='active' AND r.active_generation = ? AND r.runtime_variant = ?",
                [$actionId, $idempotencyKey, $sessionId, $generation, $turnId, $generation, $runtimeVariant]
            );

        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * For each action name, the latest issuance of this reader's actions (action name, game timestamp, timestamp) over the saved
     * history scopes of $historyCte, plus the current turn's own issuance. $historyCte is the reader's history CTE chain ending in
     * `native_history_scopes`; jsonb `?` operators in it are rewritten first.
     *
     * @return list<array<string, mixed>>
     */
    public function lastIssuedByAction(string $historyCte, string $sessionId, int $generation, int $sequence, string $turnId): array
    {
        return $this->connection()->fetchAllAssociative(JsonbOperatorSql::plain($historyCte)."
            SELECT DISTINCT ON (i.action_name) i.action_name AS action,
                (h.event_payload->>'gamets')::bigint AS gamets,
                (h.event_payload->>'ts')::bigint AS ts
            FROM native_history_scopes s
            JOIN public.core_history_event h ON h.session_id=s.session_id AND h.generation=s.generation
                AND h.playthrough_id=s.playthrough_id AND h.event_payload->>'type'='funccall'
            JOIN public.core_action_intent i ON i.action_id=h.event_payload->>'data'
                AND i.session_id=h.session_id AND i.generation=h.generation AND i.request_id=h.request_id
                AND i.actor_identity_id=s.reader_identity_id
            WHERE h.id<=s.cutoff OR (h.session_id = ? AND h.generation = ?
                AND h.context_sequence = ? AND i.turn_id = ?)
            ORDER BY i.action_name, (h.event_payload->>'gamets')::bigint DESC, h.id DESC",
            [$sessionId, $generation, $sequence, $turnId]
        );
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
