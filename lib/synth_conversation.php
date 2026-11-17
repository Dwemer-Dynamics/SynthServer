<?php

// Conversation continuity and the uniform line lifecycle (capability dialogue.conversation).
//
// A conversation_id is a client-created UUID. The server never creates or changes it: it is stored with the
// admitted request (inside the canonical event bytes, so no schema column), echoed on the response_start of every
// response, used to prefer same-conversation history, and used to scope an explicit player cancel.

const SYNTH_CONVERSATION_CAPABILITY = 'dialogue.conversation';
const SYNTH_CONVERSATION_CANCEL_REASONS = ['player_cancel', 'dialogue_closed', 'conversation_reset', 'superseded'];
const SYNTH_CONVERSATION_CANCEL_SCOPES = ['request', 'conversation'];
// Events that may carry a conversation_id: player input and every trigger (rechat, ambient, NPC-NPC, reactions).
const SYNTH_CONVERSATION_CARRIERS = ['input_text', 'input_audio', 'trigger'];

function synth_conversation_id_valid($value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
}

// Payload keys the carrier types accept beyond their existing optional keys (v2 only).
function synth_conversation_payload_keys(string $type, int $version): array
{
    if ($version !== 2) return [];
    return in_array($type, SYNTH_CONVERSATION_CARRIERS, true) ? ['conversation_id'] : [];
}

// Shape of the extended cancel payload. The legacy {cancel_request_id} form is untouched.
function synth_conversation_validate_cancel(array $payload): void
{
    $extended = array_key_exists('scope', $payload) || array_key_exists('reason', $payload)
        || array_key_exists('conversation_id', $payload);
    if (!$extended) {
        if (!synth_protocol_valid_id($payload['cancel_request_id'] ?? null))
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.cancel_request_id', 'issue' => 'invalid']]);
        return;
    }
    $scope = $payload['scope'] ?? null;
    if (!in_array($scope, SYNTH_CONVERSATION_CANCEL_SCOPES, true))
        synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.scope', 'issue' => 'invalid']]);
    if (!in_array($payload['reason'] ?? null, SYNTH_CONVERSATION_CANCEL_REASONS, true))
        synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.reason', 'issue' => 'invalid']]);
    if ($scope === 'conversation') {
        if (!synth_conversation_id_valid($payload['conversation_id'] ?? null) || array_key_exists('cancel_request_id', $payload))
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.conversation_id', 'issue' => 'invalid']]);
    } elseif (!synth_protocol_valid_id($payload['cancel_request_id'] ?? null) || array_key_exists('conversation_id', $payload)) {
        synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.cancel_request_id', 'issue' => 'invalid']]);
    }
}

// Capability rules that need the negotiated set. Called once per validated event.
function synth_conversation_validate_event(array $event, array $capabilities): void
{
    $granted = isset($capabilities[SYNTH_CONVERSATION_CAPABILITY]);
    if ($granted && (($event['protocol_version'] ?? 1) !== 2 || !isset($capabilities['dialogue.turn_ownership'])))
        synth_protocol_fail('invalid_payload', 422, [['field' => 'capabilities', 'issue' => 'conversation_capability_requires_turn_ownership']]);
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $type = $event['type'] ?? '';
    $uses = (in_array($type, SYNTH_CONVERSATION_CARRIERS, true) && array_key_exists('conversation_id', $payload))
        || ($type === 'cancel' && (array_key_exists('scope', $payload) || array_key_exists('reason', $payload)))
        || ($type === 'speech_receipt' && ($payload['outcome'] ?? '') === 'skipped');
    if ($uses && !$granted)
        synth_protocol_fail('invalid_payload', 422, [['field' => 'capabilities', 'issue' => 'conversation_capability_required']]);
    if (in_array($type, SYNTH_CONVERSATION_CARRIERS, true) && array_key_exists('conversation_id', $payload)
        && !synth_conversation_id_valid($payload['conversation_id']))
        synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.conversation_id', 'issue' => 'invalid']]);
}

// The id a request carries, only when its own event negotiated the capability.
function synth_conversation_id_of(?array $event): ?string
{
    if (!is_array($event) || ($event['protocol_version'] ?? 1) !== 2
        || !in_array(SYNTH_CONVERSATION_CAPABILITY, $event['capabilities'] ?? [], true)
        || !in_array($event['type'] ?? '', SYNTH_CONVERSATION_CARRIERS, true)) return null;
    $id = $event['payload']['conversation_id'] ?? null;
    return synth_conversation_id_valid($id) ? $id : null;
}

// The init acknowledgement: granted to the init that asked for the capability with its prerequisite.
function synth_conversation_init_granted(array $event): bool
{
    return ($event['type'] ?? '') === 'init' && ($event['protocol_version'] ?? 1) === 2
        && in_array($event['runtime_variant'] ?? '', ['flat', 'vr'], true)
        && count(array_diff(['dialogue.turn_ownership', SYNTH_CONVERSATION_CAPABILITY], $event['capabilities'] ?? [])) === 0;
}

// Explicit player cancel of a whole conversation, run inside the cancel event's claim transaction. Every in-flight
// or completed-undelivered request of the conversation in this session generation is cancelled exactly as a
// single-request cancel: completed player input stays as historical learning, everything else is revoked. A
// running provider or speech stream then fails its next liveness check, so nothing is delivered late. The reason
// is recorded in the stored cancel event itself. Returns the cancelled request ids.
function synth_conversation_cancel($db, array $event, string $session, int $generation): array
{
    $conversation = $db->escapeLiteral($event['payload']['conversation_id']);
    $own = $db->escapeLiteral($event['request_id']);
    $members = "SELECT request_id FROM synth_meta.protocol_events WHERE session_id={$session}
        AND generation={$generation} AND request_id<>{$own}
        AND event_type IN ('input_text','input_audio','trigger')
        AND canonical_event->'capabilities' ? '".SYNTH_CONVERSATION_CAPABILITY."'
        AND canonical_event#>>'{payload,conversation_id}'={$conversation}";
    $cancelled = $db->fetchAll("UPDATE synth_meta.request_executions SET
            state=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete' ELSE 'cancelled' END,
            response_body=NULL,error_code='request_cancelled',
            completed_at=CASE WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN completed_at ELSE NOW() END
        WHERE session_id={$session} AND generation={$generation} AND state IN ('claimed','streaming','complete')
            AND request_id IN ({$members}) RETURNING request_id,turn_id");
    $cancelled = is_array($cancelled) ? $cancelled : [];
    $db->execQuery("UPDATE synth_meta.worker_jobs SET state='cancelled', updated_at=NOW()
        WHERE session_id={$session} AND generation={$generation} AND state IN ('pending','running')
            AND request_id IN ({$members})");
    foreach (array_unique(array_column($cancelled, 'turn_id')) as $turn) {
        $db->execQuery("UPDATE synth_meta.runtime_sessions SET active_turn_id=NULL, updated_at=NOW()
            WHERE session_id={$session} AND active_generation={$generation} AND active_turn_id=".$db->escapeLiteral($turn));
    }
    return array_column($cancelled, 'request_id');
}

// A fixed, bounded preference for history rows of the request's own conversation: SQL for a boolean over the
// native_history column history_event_id, or null when the request carries no conversation. It only chooses
// which rows fill the existing row limit; the prompt order stays chronological.
function synth_conversation_rank_sql($db, ?array $event): ?string
{
    $id = synth_conversation_id_of($event);
    if ($id === null) return null;
    $session = $db->escapeLiteral($event['session_id']);
    return "EXISTS (SELECT 1 FROM synth_meta.history_events conv_h
        JOIN synth_meta.protocol_events conv_e ON conv_e.session_id=conv_h.session_id AND conv_e.request_id=conv_h.request_id
            AND conv_e.generation=conv_h.generation
        WHERE conv_h.id=history_event_id AND conv_h.session_id={$session}
            AND conv_e.canonical_event#>>'{payload,conversation_id}'=".$db->escapeLiteral($id).')';
}
