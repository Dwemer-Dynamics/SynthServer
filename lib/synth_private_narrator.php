<?php

// Private narrator save hook for the shared context claim. A context publication records the lane's applied private
// narrator state by opaque ID only; a lane's first publication restores it from the exact loaded_context anchor
// that publication names, never from the newest global state. The SCTX co-save and its anchor stay unchanged.

const SYNTH_PRIVATE_NARRATOR_CAPABILITY = 'narrator.private.v1';

function synth_private_narrator_supported(array $event): bool
{
    return ($event['protocol_version'] ?? 1) === 2
        && count(array_diff([SYNTH_PRIVATE_NARRATOR_CAPABILITY, 'context.saved_anchor', 'dialogue.turn_ownership'],
            $event['capabilities'] ?? [])) === 0;
}

function synth_private_narrator_valid_definition_id($value): bool
{
    return is_string($value) && preg_match('/^nd-[0-9a-f]{32}$/D', $value) === 1;
}

function synth_private_narrator_valid_record_id($value, int $minimum = 1): bool
{
    return is_int($value) && $value >= $minimum && $value <= 9007199254740991;
}

// Trust-boundary shape of the three private narrator events (protocol/v2/schemas/narrator.schema.json).
function synth_private_narrator_validate_payload(string $type, array $payload): array
{
    $invalid = static fn(string $issue) => synth_protocol_fail('invalid_payload', 422, [['field' => 'payload', 'issue' => $issue]]);
    if ($type === 'narrator_command') {
        $select = ($payload['action'] ?? null) === 'select';
        synth_protocol_expect_exact_keys($payload, $select ? ['action', 'definition_id'] : ['action'],
            ($payload['action'] ?? '') === 'transcript' ? ['cursor'] : [], 'payload');
        if (!in_array($payload['action'], ['state', 'select', 'cancel', 'transcript'], true)
            || ($select && !synth_private_narrator_valid_definition_id($payload['definition_id']))) $invalid('narrator_command_invalid');
        if (array_key_exists('cursor', $payload) && (!is_string($payload['cursor']) || preg_match('/^[0-9a-f]{64}$/D', $payload['cursor']) !== 1))
            $invalid('narrator_cursor_invalid');
    } elseif ($type === 'narrator_turn') {
        $kind = $payload['kind'] ?? null;
        $extra = ['typed' => ['text'], 'voice' => ['media'], 'scene' => []][$kind] ?? null;
        if ($extra === null) $invalid('narrator_turn_invalid');
        synth_protocol_expect_exact_keys($payload, ['kind', 'definition_id', 'state_id', ...$extra], [], 'payload');
        if (!synth_private_narrator_valid_definition_id($payload['definition_id'])
            || !synth_private_narrator_valid_record_id($payload['state_id'], 0)
            || ($kind === 'typed' && (!is_string($payload['text']) || trim($payload['text']) === ''
                || strlen($payload['text']) > 4096 || str_contains($payload['text'], "\0")
                || preg_match('//u', $payload['text']) !== 1))) $invalid('narrator_turn_invalid');
        if ($kind === 'voice') $payload['media'] = synth_protocol_validate_media($payload['media'], 'payload.media');
    } else {
        synth_protocol_expect_exact_keys($payload, ['narrator_request_id', 'line_id', 'definition_id', 'revision_id',
            'parent_state_id', 'outcome'], [], 'payload');
        if (!synth_protocol_valid_id($payload['narrator_request_id']) || !is_string($payload['line_id'])
            || $payload['line_id'] !== $payload['narrator_request_id'].':narrator'
            || !synth_private_narrator_valid_definition_id($payload['definition_id'])
            || !synth_private_narrator_valid_record_id($payload['revision_id'])
            || !synth_private_narrator_valid_record_id($payload['parent_state_id'])
            || !in_array($payload['outcome'], ['audio_completed', 'interrupted', 'failed'], true)) $invalid('narrator_receipt_invalid');
    }
    return $payload;
}

// An install whose migration has not run yet keeps publishing contexts and never grants the capability.
function synth_private_narrator_schema_ready($db): bool
{
    $row = $db->fetchOne("SELECT to_regclass('synth_meta.narrator_context_states') IS NOT NULL
        AND to_regclass('synth_meta.narrator_pending_outputs') IS NOT NULL AS ready");
    return in_array($row['ready'] ?? false, [true, 't'], true);
}

// Runs inside synth_protocol_claim_request's transaction under the session advisory lock, after the context
// snapshot row exists. Private turns, selections and receipts take the same lock, so the captured head covers every
// private change committed before this publication and none committed after it.
function synth_private_narrator_capture_context($db, array $event, string $sessionId): void
{
    if (!synth_private_narrator_supported($event) || !synth_private_narrator_schema_ready($db)) return;
    $payload = $event['payload'];
    $generation = intval($event['generation']);
    $sequence = intval($payload['snapshot_sequence']);
    $playthrough = strval($payload['playthrough_id']);
    $head = $db->execQuery('SELECT state_id,playthrough_id FROM synth_meta.narrator_lane_heads
        WHERE session_id=$1 AND generation=$2 FOR UPDATE', [$sessionId, $generation]);
    if ($head === false) throw new RuntimeException('Unable to read private narrator lane');
    $head = pg_fetch_assoc($head);
    if ($head === false) {
        $state = null;
        $anchor = synth_protocol_loaded_context($db, $event);
        if ($anchor !== null) {
            $saved = $db->execQuery('SELECT n.state_id FROM synth_meta.narrator_context_states n
                LEFT JOIN synth_meta.narrator_states s ON s.state_id=n.state_id
                WHERE n.session_id=$1 AND n.generation=$2 AND n.snapshot_sequence=$3 AND n.playthrough_id=$4
                    AND (n.state_id IS NULL OR s.playthrough_id=n.playthrough_id)',
                [$anchor['session_id'], intval($anchor['generation']), intval($anchor['snapshot_sequence']), $playthrough]);
            if ($saved === false) throw new RuntimeException('Unable to read saved private narrator state');
            $saved = pg_fetch_assoc($saved);
            // A save made before this feature (or without it) restores the empty state.
            if ($saved !== false && $saved['state_id'] !== null) $state = intval($saved['state_id']);
        }
        if ($db->execQuery('INSERT INTO synth_meta.narrator_lane_heads(session_id,generation,playthrough_id,state_id)
            VALUES($1,$2,$3,$4)', [$sessionId, $generation, $playthrough, $state]) === false) {
            throw new RuntimeException('Unable to open private narrator lane');
        }
        // A load or same-world replacement starts a new lane; replies owned by any earlier lane of this playthrough
        // can no longer reach a transcript.
        if ($db->execQuery("UPDATE synth_meta.narrator_pending_outputs SET state='cancelled',updated_at=NOW()
            WHERE playthrough_id=$1 AND state IN ('pending','generated') AND NOT (session_id=$2 AND generation=$3)",
            [$playthrough, $sessionId, $generation]) === false) {
            throw new RuntimeException('Unable to retire superseded private narrator replies');
        }
        $head = ['state_id' => $state, 'playthrough_id' => $playthrough];
    } elseif ($head['playthrough_id'] !== $playthrough) {
        throw new DomainException('context_playthrough_mismatch');
    }
    if ($db->execQuery('INSERT INTO synth_meta.narrator_context_states(session_id,generation,snapshot_sequence,playthrough_id,state_id)
        VALUES($1,$2,$3,$4,$5)', [$sessionId, $generation, $sequence, $playthrough, $head['state_id']]) === false) {
        throw new RuntimeException('Unable to capture private narrator state');
    }
}
