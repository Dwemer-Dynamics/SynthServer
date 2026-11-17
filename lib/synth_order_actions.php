<?php

// Follow, Stop Following and Take a Seat are companion orders. The server only names the order and binds it
// to the exact NPC reply line it rides on; the game applies it through owned packages and reports a bounded
// physical receipt. The model never names a target, a place or a seat.
require_once __DIR__.'/synth_wait_actions.php';

const SYNTH_ORDER_CODES = ['FollowPlayer' => 'follow_player', 'StopFollowing' => 'stop_following', 'TakeASeat' => 'take_a_seat'];

function synth_order_action_codes(): array
{
    return array_keys(SYNTH_ORDER_CODES);
}

// Owned flat player text/audio input only; autonomous, narrator and VR turns never issue an order.
function synth_order_action_supported(array $event, string $code): bool
{
    $name = SYNTH_ORDER_CODES[$code] ?? null;
    return $name !== null && ($event['protocol_version'] ?? 1) === 2
        && ($event['runtime_variant'] ?? '') === 'flat'
        && in_array($event['type'] ?? '', ['input_text', 'input_audio'], true)
        && in_array($event['payload']['control']['mode'] ?? 'STANDARD', ['STANDARD', 'WHISPER', 'CLOSE', 'SHOUT'], true)
        && count(array_diff(['dialogue.turn_ownership', 'action.order_verified', 'action.' . $name], $event['capabilities'] ?? [])) === 0;
}

function synth_order_action_is_intent(array $action): bool
{
    return in_array($action['name'] ?? '', SYNTH_ORDER_CODES, true)
        || in_array($action['server_code'] ?? '', synth_order_action_codes(), true)
        || in_array(substr(strval($action['capability'] ?? ''), 7), SYNTH_ORDER_CODES, true);
}

// Records each NPC dialogue line as it is encoded, so an order can name the exact reply it belongs to.
function synth_order_note_reply_line(array $line): void
{
    $speaker = $line['payload']['speaker'] ?? null;
    $key = is_array($speaker) ? synth_wait_actor_key($speaker) : null;
    if ($key === null || !is_string($line['line_id'] ?? null) || !is_string($line['request_id'] ?? null)) return;
    $notes = $GLOBALS['SYNTH_ORDER_REPLY_LINES'] ?? [];
    $notes[] = ['request_id' => $line['request_id'], 'line_id' => $line['line_id'], 'actor' => $key];
    $GLOBALS['SYNTH_ORDER_REPLY_LINES'] = array_slice($notes, -64);
}

// The first reply line of this actor in this request; one order per request, so a second order is refused.
function synth_order_claim_reply_line(string $requestId, array $actor): ?string
{
    $key = synth_wait_actor_key($actor);
    if ($key === null || !empty($GLOBALS['SYNTH_ORDER_ISSUED'][$requestId])) return null;
    foreach ($GLOBALS['SYNTH_ORDER_REPLY_LINES'] ?? [] as $note) {
        if ($note['request_id'] === $requestId && $note['actor'] === $key) {
            $GLOBALS['SYNTH_ORDER_ISSUED'][$requestId] = true;
            return $note['line_id'];
        }
    }
    return null;
}

// The order addresses only the captured NPC the player spoke to: no target, no arguments, never the player.
function synth_order_action_validate($db, array $event, array $action): void
{
    $code = array_search($action['name'] ?? '', SYNTH_ORDER_CODES, true);
    if (!is_string($code) || !synth_order_action_supported($event, $code)
        || ($action['capability'] ?? '') !== 'action.' . $action['name']
        || (isset($action['server_code']) && $action['server_code'] !== $code)
        || array_key_exists('target', $action)
        || !($action['arguments'] ?? null) instanceof stdClass || get_object_vars($action['arguments']) !== []
        || !is_string($action['reply_line_id'] ?? null)
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $action['reply_line_id']) !== 1
        || !is_array($action['actor'] ?? null)) throw new DomainException('invalid_order_intent');
    $context = synth_protocol_current_context($db, $event);
    $actor = $context ? synth_protocol_response_actor_identity($db, $event, $context) : null;
    if (!is_array($actor) || strcasecmp($actor['form_id'] ?? '', '0x00000014') === 0
        || synth_protocol_canonical_json($action['actor']) !== synth_protocol_canonical_json($actor)) throw new DomainException('order_actor_not_owned');
}

// A terminal status alone is not a physical observation. The detail must be the bounded owned receipt for
// this exact action, actor and reply line, and its flags must agree with the order.
function synth_order_result_observation(array $event, string $name, array $actor, ?string $replyLineId): ?array
{
    if (!in_array($name, SYNTH_ORDER_CODES, true)) throw new DomainException('invalid_order_observation');
    $result = $event['payload']['result'] ?? [];
    if (($result['status'] ?? '') !== 'succeeded' || !in_array('action.order_verified', $event['capabilities'] ?? [], true)) return null;
    $detail = $result['detail'] ?? null;
    try {
        $proof = is_string($detail) && strlen($detail) <= 1024 && !str_contains($detail, "\0")
            ? json_decode($detail, true, 8, JSON_THROW_ON_ERROR) : null;
    } catch (JsonException) {
        $proof = null;
    }
    $keys = ['schema', 'action_id', 'action', 'actor', 'reply_line_id', 'following', 'seated', 'released', 'owned_package', 'seat', 'reload_policy'];
    if (!is_array($proof) || array_is_list($proof) || array_diff(array_keys($proof), $keys) || count($proof) !== count($keys)
        || ($proof['schema'] ?? '') !== 'synth.action.order.result.v1'
        || ($proof['action_id'] ?? null) !== ($result['action_id'] ?? null) || ($proof['action'] ?? null) !== $name
        || synth_wait_actor_key($proof['actor'] ?? null) === null || synth_wait_actor_key($proof['actor']) !== synth_wait_actor_key($actor)
        || !is_string($proof['reply_line_id'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $proof['reply_line_id']) !== 1
        || ($replyLineId !== null && $proof['reply_line_id'] !== $replyLineId)
        || !is_bool($proof['following']) || !is_bool($proof['seated']) || !is_bool($proof['released'])
        || ($proof['reload_policy'] ?? null) !== 'release_owned_order') throw new DomainException('invalid_order_observation');
    $package = synth_order_form_value($proof['owned_package'] ?? null);
    $seat = synth_order_form_value($proof['seat'] ?? null);
    $held = $name !== 'stop_following';
    if ($package === null || $seat === null || ($held ? $package === 0 : $package !== 0)
        || ($name === 'follow_player' && (!$proof['following'] || $proof['seated'] || $proof['released'] || $seat !== 0))
        || ($name === 'take_a_seat' && ($proof['following'] || !$proof['seated'] || $proof['released'] || $seat === 0))
        || ($name === 'stop_following' && ($proof['following'] || $proof['seated'] || !$proof['released'] || $seat !== 0)))
        throw new DomainException('invalid_order_observation');
    return $proof;
}

// Any well-formed 0xXXXXXXXX form, including zero; ownership of the order package is the game's to prove.
function synth_order_form_value($value): ?int
{
    return is_string($value) && preg_match('/^0x[0-9a-f]{8}$/Di', $value) === 1 ? (int) hexdec(substr($value, 2)) : null;
}

function synth_order_result_message(string $name, string $display, string $status, bool $verified): string
{
    if ($status !== 'succeeded' || !$verified) {
        return $display . ' could not be confirmed on this order (' . $status . '); do not assume it took effect.';
    }
    return match ($name) {
        'follow_player' => $display . ' is following the player now.',
        'take_a_seat' => $display . ' sat down.',
        default => $display . ' stopped following the player. Any native follower role is unchanged.',
    };
}

// The result text the NPC and history see, from the recorded intent's actor. An unverifiable receipt is
// reported as unconfirmed rather than as success; it never raises into the result path.
function synth_order_bound_result_detail(array $event, array $row): string
{
    $name = in_array($row['action_name'], SYNTH_ORDER_CODES, true) ? $row['action_name'] : (SYNTH_ORDER_CODES[$row['action_name']] ?? '');
    $actor = ['form_id' => $row['actor_form_id'] ?? '', 'origin_plugin' => $row['actor_plugin'] ?? '', 'playthrough_id' => $row['actor_playthrough'] ?? ''];
    $display = trim(strval($row['actor_name'] ?? '')) !== '' ? trim($row['actor_name']) : 'The addressed NPC';
    $status = strval($event['payload']['result']['status'] ?? 'failed');
    try {
        $verified = $name !== '' && synth_order_result_observation($event, $name, $actor, null) !== null;
    } catch (DomainException) {
        $verified = false;
    }
    return synth_order_result_message($name, $display, $status, $verified);
}
