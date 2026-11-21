<?php

// Reads core_action_intent. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionIntent;

/** Finds rows of core_action_intent. */
final class ActionIntentDao
{
    use UsesEntityManager;

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
