<?php

// Reads, locks and writes core_action_intent on the caller's legacy session; the caller owns the transaction.

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
     * state, action name and whether its deadline passed more than five minutes ago ('expired'); null when none or the statement fails
     * (the legacy fetchOne contract: the caller rejects the receipt as unknown_or_stale_action).
     *
     * @return array<string, mixed>|null
     */
    public function lockIntent(string $actionId, string $sessionId, int $generation): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT action_id, idempotency_key, state, action_name, (deadline_at + INTERVAL '5 minutes' < NOW()) AS expired
                   FROM public.core_action_intent WHERE action_id = ? AND session_id = ? AND generation = ? FOR UPDATE",
                [$actionId, $sessionId, $generation]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** The context sequence and protocol version of the event that issued this action ('context_sequence', 'protocol_version'); null when none or the statement fails (the caller then reports action_context_mismatch). @return array<string, mixed>|null */
    public function issuingEvent(string $actionId, string $sessionId, int $generation): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT e.canonical_event->>'context_sequence' AS context_sequence,
                        e.canonical_event->>'protocol_version' AS protocol_version
                   FROM public.core_action_intent i
                   JOIN public.core_protocol_event e ON e.session_id=i.session_id AND e.request_id=i.request_id AND e.generation=i.generation
                  WHERE i.action_id = ? AND i.session_id = ? AND i.generation = ?",
                [$actionId, $sessionId, $generation]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
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
     * The recorded mutation owner of an inventory action: issuing turn and request, action name and capability, the actor and
     * recipient identities, and the issuing canonical event; only while the intent is live and its turn is the session's active one.
     * Null when there is none or the statement fails (the legacy fetchOne contract: the caller rejects the receipt's inventory).
     *
     * @return array<string, mixed>|null
     */
    public function inventoryOwner(string $actionId, string $sessionId, int $generation): ?array
    {
        try {
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
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** Marks the action's intent complete. False when the statement fails. */
    public function markComplete(string $actionId): bool
    {
        return $this->tryStatement("UPDATE public.core_action_intent SET state='complete' WHERE action_id = ?", [$actionId]);
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

    /**
     * The accepted, completed intent a native inventory observation descends from: the intent, its terminal result, the origin
     * request and the actors, proven against the receipt request and the session's active turn. With $retainsGiftOwner a
     * completed accepted/parent request pair may stand in for the active turn. Null when nothing proves the ancestry.
     *
     * @return array<string, mixed>|null
     */
    public function inventoryAncestry(
        bool $retainsGiftOwner,
        string $actionId,
        string $idempotencyKey,
        string $receiptRequestId,
        string $canonicalReceiptJson,
        string $sessionId,
        string $turnId,
        int $generation,
        int $sequence,
        string $runtimeVariant
    ): ?array {
        $turnOwner = $retainsGiftOwner
            ? "((r.active_turn_id = ? AND r.active_turn_sequence = ?)
                OR (accepted.state = 'complete' AND accepted.error_code IS NULL AND parent.state = 'complete' AND parent.error_code IS NULL))"
            : '(r.active_turn_id = ? AND r.active_turn_sequence = ?)';
        $row = $this->connection()->fetchAssociative(
            "SELECT i.action_name, i.capability, i.request_id AS origin_request_id, a.full_form_id, a.origin_plugin, a.playthrough_id,
                    e.canonical_event, t.result, b.full_form_id AS recipient_form_id,
                    b.origin_plugin AS recipient_plugin, b.playthrough_id AS recipient_playthrough_id
             FROM public.core_action_intent i
             JOIN public.core_actor_identity a ON a.id = i.actor_identity_id AND a.game = 'fo4'
             LEFT JOIN public.core_actor_identity b ON b.id = i.target_identity_id AND b.game = 'fo4'
             JOIN public.core_action_result t ON t.action_id = i.action_id AND t.idempotency_key = i.idempotency_key
                AND t.status = t.result->>'status'
             JOIN public.core_protocol_event e ON e.session_id = i.session_id AND e.request_id = i.request_id
                AND e.generation = i.generation AND e.turn_id = i.turn_id
             JOIN public.core_request_execution parent ON parent.session_id = e.session_id AND parent.request_id = e.request_id
                AND parent.state IN ('streaming', 'complete')
             JOIN public.core_protocol_event receipt ON receipt.session_id = i.session_id AND receipt.request_id = ?
                AND receipt.generation = i.generation AND receipt.turn_id = i.turn_id AND receipt.event_type = 'action_result'
                AND receipt.canonical_event = CAST(? AS jsonb) AND receipt.canonical_event->'payload'->'result' = t.result
             JOIN public.core_request_execution accepted ON accepted.session_id = receipt.session_id
                AND accepted.request_id = receipt.request_id AND accepted.state IN ('claimed', 'streaming', 'complete')
             JOIN public.core_runtime_session r ON r.session_id = i.session_id AND r.playthrough_id = a.playthrough_id
             WHERE i.action_id = ? AND i.idempotency_key = ? AND i.session_id = ?
                AND i.turn_id = ? AND i.generation = ? AND i.state = 'complete'
                AND r.state = 'active' AND r.active_generation = ? AND {$turnOwner} AND r.runtime_variant = ?",
            [$receiptRequestId, $canonicalReceiptJson, $actionId, $idempotencyKey, $sessionId, $turnId, $generation, $generation, $turnId, $sequence, $runtimeVariant]
        );

        return $row === false ? null : $row;
    }

    /**
     * The successful take-item or open-inventory intent a player gift result proves, when the terminal result equals the given
     * canonical result and the receipt event is the given canonical event and both requests are accepted; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function giftProof(string $actionId, string $idempotencyKey, string $sessionId, string $turnId, int $generation, string $canonicalResultJson, string $canonicalEventJson): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.action_name, i.arguments, p.canonical_event, a.full_form_id, a.origin_plugin, a.playthrough_id,
                    b.full_form_id AS player_form_id, b.origin_plugin AS player_plugin, b.playthrough_id AS player_playthrough
             FROM public.core_action_intent i
             JOIN public.core_action_result t ON t.action_id = i.action_id AND t.idempotency_key = i.idempotency_key
                AND t.status = 'succeeded' AND t.result = CAST(? AS jsonb)
             JOIN public.core_protocol_event p ON p.session_id = i.session_id AND p.request_id = i.request_id AND p.generation = i.generation AND p.turn_id = i.turn_id
             JOIN public.core_request_execution origin ON origin.session_id = p.session_id AND origin.request_id = p.request_id
                AND origin.request_sha256 = p.event_sha256 AND origin.state = 'complete' AND origin.error_code IS NULL
             JOIN public.core_protocol_event receipt ON receipt.session_id = i.session_id AND receipt.canonical_event = CAST(? AS jsonb)
             JOIN public.core_request_execution accepted ON accepted.session_id = receipt.session_id AND accepted.request_id = receipt.request_id
                AND accepted.request_sha256 = receipt.event_sha256 AND accepted.state IN ('claimed', 'streaming', 'complete') AND accepted.error_code IS NULL
             JOIN public.core_actor_identity a ON a.id = i.actor_identity_id AND a.game = 'fo4'
             JOIN public.core_actor_identity b ON b.id = i.target_identity_id AND b.game = 'fo4'
             WHERE i.action_id = ? AND i.idempotency_key = ? AND i.session_id = ? AND i.turn_id = ? AND i.generation = ?
                AND ((i.action_name = 'TakeItemFromPlayer' AND i.capability = 'action.take_item_from_player')
                    OR (i.action_name = 'OpenInventory' AND i.capability = 'action.open_inventory')) AND i.state = 'complete'",
            [$canonicalResultJson, $canonicalEventJson, $actionId, $idempotencyKey, $sessionId, $turnId, $generation]
        );

        return $row === false ? null : $row;
    }

    /**
     * The accepted wait or release-wait intent a native result belongs to, with its origin and receipt events, the actor identity
     * and the context snapshot of the result's sequence; null when the intent, receipt and active turn do not line up.
     *
     * @return array<string, mixed>|null
     */
    public function waitResultContext(string $sessionId, string $requestId, string $turnId, int $generation, int $sequence, string $actionId, string $idempotencyKey): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.actor_identity_id, i.action_name, i.capability, i.arguments, i.target_identity_id, i.created_at AS intent_created_at,
                    receipt.received_at AS result_received_at,
                    a.full_form_id, a.origin_plugin, a.playthrough_id, source.canonical_event AS origin_event, source.event_sha256 AS origin_sha256,
                    receipt.canonical_event AS receipt_event, receipt.event_sha256 AS receipt_sha256, c.context_payload
             FROM public.core_action_intent i JOIN public.core_actor_identity a ON a.id = i.actor_identity_id AND a.game = 'fo4'
             JOIN public.core_protocol_event source ON source.session_id = i.session_id AND source.request_id = i.request_id AND source.generation = i.generation AND source.turn_id = i.turn_id
             JOIN public.core_request_execution issued ON issued.session_id = source.session_id AND issued.request_id = source.request_id AND issued.request_sha256 = source.event_sha256
             JOIN public.core_protocol_event receipt ON receipt.session_id = i.session_id AND receipt.request_id = ? AND receipt.generation = i.generation AND receipt.turn_id = i.turn_id
             JOIN public.core_request_execution accepted ON accepted.session_id = receipt.session_id AND accepted.request_id = receipt.request_id AND accepted.request_sha256 = receipt.event_sha256
             JOIN public.core_runtime_context c ON c.session_id = i.session_id AND c.generation = i.generation AND c.snapshot_sequence = ?
             JOIN public.core_runtime_session r ON r.session_id = i.session_id AND r.playthrough_id = a.playthrough_id
             WHERE i.action_id = ? AND i.idempotency_key = ? AND i.session_id = ? AND i.generation = ? AND i.turn_id = ?
                AND i.state IN ('pending', 'dispatched', 'complete') AND source.canonical_event->>'context_sequence' = ?
                AND receipt.event_type = 'action_result' AND accepted.state IN ('claimed', 'streaming', 'complete')
                AND r.state = 'active' AND r.active_generation = i.generation AND r.active_turn_id = i.turn_id AND r.active_turn_sequence = c.snapshot_sequence
                AND r.runtime_variant = 'flat' AND c.playthrough_id = a.playthrough_id",
            [$requestId, $sequence, $actionId, $idempotencyKey, $sessionId, $generation, $turnId, (string) $sequence]
        );

        return $row === false ? null : $row;
    }
}
