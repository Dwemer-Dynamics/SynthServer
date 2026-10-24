<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'synth_command_payload.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'npc_tts_status.php');
require_once __DIR__.'/synth_protocol_state.php';
require_once __DIR__.'/synth_combat_target.php';

function synth_protocol_response_context(): array
{
    $event = is_array($GLOBALS['SYNTH_REQUEST_EVENT'] ?? null) ? $GLOBALS['SYNTH_REQUEST_EVENT'] : [];
    return [
        'session_id' => strval($event['json']['session_id'] ?? $event['session_id'] ?? ''),
        'request_id' => strval($event['request_id'] ?? $GLOBALS['SYNTH_REQUEST_ID'] ?? ''),
        'turn_id' => strval($event['turn_id'] ?? $GLOBALS['SYNTH_TURN_ID'] ?? ''),
        'generation' => intval($event['generation'] ?? $GLOBALS['SYNTH_GENERATION'] ?? 0),
        'runtime_variant' => strval($event['runtime_variant'] ?? $GLOBALS['SYNTH_RUNTIME_VARIANT'] ?? 'flat'),
        'protocol_version' => intval($event['protocol_version'] ?? 1),
        'context_sequence' => intval($event['context_sequence'] ?? 0),
    ];
}

function synth_protocol_encode_response_line(string $type, array $payload, string $suffix): string
{
    $context = synth_protocol_response_context();
    $line = [
        'schema' => 'synth.response.line.v' . $context['protocol_version'],
        'protocol_version' => $context['protocol_version'],
        'type' => $type,
        'line_id' => ($context['request_id'] !== '' ? $context['request_id'] : 'response') . ':' . $suffix,
        'request_id' => $context['request_id'],
        'turn_id' => $context['turn_id'],
        'generation' => $context['generation'],
        'game' => 'fo4',
        'runtime_variant' => $context['runtime_variant'],
        'payload' => $payload,
    ];
    if ($context['protocol_version'] === 2) $line['context_sequence'] = $context['context_sequence'];
    return json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}

function synth_protocol_write_response_line(string $type, array $payload, string $suffix, array $routeMetadata = []): void
{
    $wire = synth_protocol_encode_response_line($type, $payload, $suffix);
    $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    $ownedRechat=synth_protocol_owns_rechat($event);
    if ($ownedRechat && in_array($type,['dialogue','action'],true)) {
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) throw new LogicException('rechat_emission_claim_required');
        $line=json_decode($wire,true,flags:JSON_THROW_ON_ERROR);
        foreach (['request_id','turn_id','generation','context_sequence','runtime_variant','protocol_version'] as $field) {
            if (($line[$field] ?? null)!==($event[$field] ?? null)) throw new LogicException('rechat_emission_owner_mismatch');
        }
        if ($type==='action') {
            require_once __DIR__.'/synth_rechat_emission.php';
            synth_rechat_assert_action_emission($GLOBALS['db'],$event,$payload['action'] ?? [],true);
        }
    }
    if ($type === 'dialogue' && ($ownedRechat || (!empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
        && ($event['protocol_version'] ?? 1) === 2 && intval($event['context_sequence'] ?? 0) > 0
        && in_array('dialogue.turn_ownership', $event['capabilities'] ?? [], true)))) {
        require_once __DIR__.'/synth_rechat_routes.php';
        $wire=synth_rechat_record_emitted_route($GLOBALS['db'], $event, $wire, $routeMetadata);
    }
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] = ($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? '') . $wire;
    echo $wire;
    if (ob_get_level()) {
        @ob_flush();
    }
    @flush();
}

function synth_protocol_ensure_response_started(): void
{
    if (!empty($GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED'])) {
        return;
    }
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED'] = true;
    synth_protocol_write_response_line('response_start', [
        'server_version' => strval($GLOBALS['SYNTH_SERVER_VERSION'] ?? 'source'),
    ], 'start');
}

function synth_protocol_next_line_suffix(string $kind): string
{
    $next = intval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_SEQUENCE'] ?? 0) + 1;
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_SEQUENCE'] = $next;
    return $kind . ':' . $next;
}

function synth_protocol_response_speaker(string $speaker, ?string $speechRole = null): ?array
{
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (function_exists('synth_protocol_owns_rechat') && synth_protocol_owns_rechat($event)) {
        $db=$GLOBALS['db'] ?? null;
        $identity=$db ? synth_protocol_resolve_speaker_identity($db,$speaker) : null;
        $requestedRole=$speechRole ?? $GLOBALS['SYNTH_SPEECH_ROLE'] ?? null;
        // A temporary lane cannot impersonate the selected rechat responder, even with the same label.
        if ($identity!==null && $requestedRole!==null
            && $requestedRole!==(isset($identity['kind']) ? 'narrator' : 'npc')) return null;
        return $identity;
    }
    $speechRole = $speechRole ?? synth_protocol_current_speech_role($speaker);
    if (!in_array($speechRole,['npc','player','narrator'],true)) return null;
    if (($event['protocol_version'] ?? 1)===2) {
        $db=$GLOBALS['db'] ?? null;
        $context=$db ? synth_protocol_current_context($db,$event) : null;
        if (!$context) return null;
        if ($speechRole==='player') return $context['player'];
        if ($speechRole==='npc') {
            $actor=synth_protocol_response_actor_identity($db,$event,$context);
            return $actor!==null && strcasecmp(trim($actor['display_name'] ?? ''),trim($speaker))===0 ? $actor : null;
        }
    }
    if ($speechRole==='narrator') {
        return [
            'kind' => 'narrator',
            'id' => 'narrator',
            'display_name' => function_exists('synthGetNarratorRoleplayName')
                ? synthGetNarratorRoleplayName()
                : strval($GLOBALS['NARRATOR_ROLEPLAY_NAME'] ?? 'The Narrator'),
        ];
    }
    $db = $GLOBALS['db'] ?? null;
    return $db && function_exists('synth_protocol_resolve_speaker_identity')
        ? synth_protocol_resolve_speaker_identity($db, $speaker)
        : null;
}

function synth_protocol_action_from_legacy_line(array $line): ?array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !function_exists('synth_protocol_record_action_intent')) {
        return null;
    }
    $serverCode = trim(strval($line['command_name'] ?? $line['text'] ?? ''));
    if ($serverCode === '') {
        return null;
    }
    if (function_exists('getFunctionCodeName')) {
        $resolved = getFunctionCodeName($serverCode);
        if (is_string($resolved) && $resolved !== '') {
            $serverCode = $resolved;
        }
    }
    $capability = function_exists('synthActionCapabilityForCode')
        ? synthActionCapabilityForCode($serverCode)
        : 'action.' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $serverCode));
    $capabilities = is_array($GLOBALS['SYNTH_CLIENT_CAPABILITIES'] ?? null)
        ? $GLOBALS['SYNTH_CLIENT_CAPABILITIES']
        : [];
    if (!in_array($capability, $capabilities, true)) {
        return null;
    }
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if ($serverCode==='ReleaseWait' || ($serverCode==='WaitHere' && ($event['protocol_version'] ?? 1)===2)) {
        require_once __DIR__.'/synth_wait_actions.php';
        if (!synth_wait_action_supported($event,$serverCode) || !synth_wait_action_legacy_empty($line)) return null;
    }
    if ($capability==='action.read_quests' && !synth_protocol_player_journal_action_allowed()) return null;
    if (($event['protocol_version'] ?? 1)===2 && ($event['type'] ?? '')==='trigger'
        && ($event['payload']['kind'] ?? '')==='rechat' && !synth_rechat_action_code_allowed($serverCode)) return null;
    if ($capability==='action.consume' && ($event['protocol_version'] ?? 1)===2
        && !synth_protocol_supports_consumption($event)) return null;
    if ($capability==='action.give_item_to' && !synth_protocol_supports_transfer($event)) return null;
    if ($capability==='action.give_caps_to' && ($event['protocol_version'] ?? 1)===2
        && (!synth_protocol_supports_caps_transfer($event) || synth_protocol_owns_rechat($event))) return null;
    if ($capability==='action.take_caps_from_player' && ($event['protocol_version'] ?? 1)===2
        && (!synth_protocol_supports_player_caps($event) || synth_protocol_owns_rechat($event))) return null;
    $actor = synth_protocol_response_speaker(strval($line['speaker'] ?? ''));
    if (!$actor || isset($actor['kind'])) {
        return null;
    }
    $targetName = trim(strval($line['target'] ?? $line['metadata']['target'] ?? ''));
    $nativeTarget = ($event['protocol_version'] ?? 1)===2;
    $consumeItem = null;
    if ($nativeTarget && $capability === 'action.consume') {
        // Consume's legacy target is the item, never another actor. Reject conflicting model selectors.
        $consumeItem = trim(strval($line['item'] ?? $line['metadata']['item'] ?? ''));
        if ($consumeItem !== '' && $targetName !== '' && $consumeItem !== $targetName) return null;
        if ($consumeItem === '') $consumeItem = $targetName;
        if ($consumeItem === '' || strlen($consumeItem) > 255) return null;
        $targetName = '';
    }
    $target = $targetName === '' || $nativeTarget
        ? null : synth_protocol_resolve_speaker_identity($db, $targetName);
    // CheckInventory's legacy target slot is an item filter, not an actor to resolve.
    if ($targetName!=='' && $nativeTarget && $capability!=='action.check_inventory') {
        // An action target is not another response speaker; resolve only an unambiguous captured actor.
        require_once __DIR__.'/synth_rechat_routes.php';
        $captured=synth_protocol_current_context($db,$event);
        $matches=[];
        foreach (array_merge([$captured['player'] ?? null,$captured['target'] ?? null],$captured['payload']['audience'] ?? []) as $candidate) {
            if (is_array($candidate) && strcasecmp(trim($candidate['display_name'] ?? ''),$targetName)===0) {
                $matches[synth_rechat_actor_key($candidate)]=$candidate;
            }
        }
        if (in_array(strtolower($targetName),['player','sole survivor','the sole survivor'],true) && is_array($captured['player'] ?? null)) {
            $matches[synth_rechat_actor_key($captured['player'])]=$captured['player'];
        }
        $target=count($matches)===1 ? reset($matches) : null;
        if ($target===null) return null;
    }
    if ($capability === 'action.inspect_actor' && $target === null) {
        return null;
    }
    $arguments = [];
    foreach (['item', 'location', 'message', 'instruction', 'speed'] as $key) {
        $value = trim(strval($line[$key] ?? $line['metadata'][$key] ?? ''));
        if ($value !== '') {
            $arguments[$key] = substr($value, 0, in_array($key, ['message', 'instruction'], true) ? 1024 : 255);
        }
    }
    $amount = $line['amount'] ?? $line['metadata']['amount'] ?? null;
    if ($consumeItem !== null && $amount !== null && trim(strval($amount)) !== '') return null;
    if (is_numeric($amount) && intval($amount) > 0) {
        $arguments['amount'] = min(1000000, intval($amount));
    }
    if ($targetName !== '') {
        $arguments['target_name'] = substr($targetName, 0, 255);
    }
    if ($consumeItem !== null) {
        // This native contract consumes exactly one item; do not silently discard amount or unrelated arguments.
        if (array_diff(array_keys($arguments), ['item']) !== []) return null;
        $arguments = ['item' => $consumeItem];
    }
    if ($capability === 'action.give_item_to' || ($nativeTarget && in_array($capability,['action.give_caps_to','action.take_caps_from_player'],true))) {
        // Preserve the canonical target separately; never round, truncate or discard conflicting transfer arguments.
        $item = $line['item'] ?? $line['metadata']['item'] ?? null;
        $taking = $capability === 'action.take_caps_from_player';
        $caps = $taking || $capability === 'action.give_caps_to';
        $metadata = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
        foreach (['item','amount','target'] as $key)
            if (array_key_exists($key,$line) && array_key_exists($key,$metadata) && $line[$key]!==$metadata[$key]) return null;
        $hasAmount = array_key_exists('amount', $line) || array_key_exists('amount', $metadata);
        if (!$target || ($caps ? (!$hasAmount || array_key_exists('item',$line) || array_key_exists('item',$metadata))
            : (!is_string($item) || trim($item)==='' || strlen(trim($item))>255))
            || array_diff(array_keys($arguments), $caps ? ['amount','target_name'] : ['item','amount','target_name']) !== []) return null;
        // The legacy command buffer represents an omitted third argument as an empty string.
        $quantity = !$caps && (!$hasAmount || $amount === '') ? 1 : $amount;
        // Legacy command fields are text; accept only a canonical positive decimal integer before producing JSON integer data.
        if (is_string($quantity) && preg_match('/^[1-9][0-9]{0,6}$/D', $quantity) === 1) $quantity = intval($quantity);
        if (!is_int($quantity) || $quantity<1 || $quantity>1000000) return null;
        if (isset($metadata['command_args'])) {
            $ordered=$metadata['command_args'];
            if (!is_array($ordered) || !array_is_list($ordered) || count($ordered)<2 || count($ordered)>($caps?2:3)
                || ($ordered[0] ?? '')!==$targetName || ($ordered[1] ?? '')!==($caps?$amount:trim($item))
                || (!$caps && array_key_exists(2,$ordered) && $ordered[2]!==(!$hasAmount ? '' : $amount))) return null;
        }
        $arguments = $caps ? ['amount'=>$quantity] : ['item'=>trim($item),'amount'=>$quantity];
        if (!synth_protocol_valid_transfer_intent(['name'=>substr($capability,7),'capability'=>$capability,
            'actor'=>$actor,'target'=>$target,'arguments'=>$arguments])) return null;
        if ($caps && !synth_protocol_caps_inventory_allows($captured ?? [],$taking?$target:$actor,$quantity)) return null;
    }
    $context = synth_protocol_response_context();
    $ordinal = intval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_SEQUENCE'] ?? 0) + 1;
    $seed = $context['session_id'] . '|' . $context['generation'] . '|' . $context['request_id']
        . '|' . $context['turn_id'] . '|' . $serverCode . '|' . $ordinal;
    $action = [
        'schema' => 'synth.action.v1',
        'action_id' => 'action:' . substr(hash('sha256', $seed), 0, 32),
        'idempotency_key' => 'idem:' . substr(hash('sha256', $seed . '|v1'), 0, 32),
        'name' => substr($capability, 7),
        'capability' => $capability,
        'actor' => $actor,
        'arguments' => (object)$arguments,
        'deadline_ms' => 30000,
        'server_code' => $serverCode,
    ];
    if ($target !== null) {
        $action['target'] = $target;
    }
    if ($serverCode==='ReleaseWait' || ($serverCode==='WaitHere' && ($event['protocol_version'] ?? 1)===2)) {
        try { synth_wait_action_validate($db,$event,$action); }
        catch (DomainException) { return null; }
    }
    synth_protocol_record_action_intent($db, $action);
    unset($action['server_code']);
    return $action;
}

// Separate encoding allows admission to persist its acknowledgment atomically with durable work.
function synth_protocol_encode_ack(string $status, array $detail = []): string
{
    // Explicit opt-in support, bound to the admitted init; older clients keep their original acknowledgement.
    $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    $combatTargets = $status === 'init_accepted' && ($event['type'] ?? '') === 'init'
        && ($event['protocol_version'] ?? 1) === 2
        && in_array($event['runtime_variant'] ?? '', ['flat', 'vr'], true)
        && in_array('context.combat_targets', $event['capabilities'] ?? [], true);
    $rechatScene = $status === 'init_accepted' && ($event['type'] ?? '') === 'init'
        && ($event['protocol_version'] ?? 1) === 2
        && in_array($event['runtime_variant'] ?? '', ['flat','vr'], true)
        && count(array_diff(['dialogue.turn_ownership','dialogue.rechat.ownership','dialogue.rechat.scene'],
            $event['capabilities'] ?? [])) === 0;
    $ambientNpc=$rechatScene && count(array_diff(['dialogue.ambient_npc','dialogue.listener_identity'],
        $event['capabilities'] ?? []))===0;
    if ($status === 'init_accepted' && ($event['type'] ?? '') === 'init'
        && ($event['protocol_version'] ?? 1) === 2 && ($event['runtime_variant'] ?? '') === 'flat') {
        $quests = in_array('context.quest_events', $event['capabilities'] ?? [], true);
        $tracking = in_array('context.quest_tracking', $event['capabilities'] ?? [], true);
        if (synth_protocol_supports_player_caps($event))
            $status = 'init_player_caps_inventory_accepted';
        elseif (synth_protocol_supports_caps_transfer($event))
            $status = 'init_caps_inventory_accepted';
        elseif (synth_protocol_supports_transfer($event))
            $status = 'init_transfer_inventory_accepted';
        elseif (synth_protocol_supports_consumption($event))
            $status = 'init_consume_inventory_accepted';
        elseif (in_array('action.inventory_observation', $event['capabilities'] ?? [], true)
            && in_array('dialogue.turn_ownership', $event['capabilities'] ?? [], true))
            $status = 'init_action_inventory_accepted';
        elseif (in_array('context.inventory_512', $event['capabilities'] ?? [], true))
            $status = 'init_inventory_512_accepted';
        elseif (in_array('context.actor_events', $event['capabilities'] ?? [], true))
            $status = 'init_actor_events_accepted';
        elseif (in_array('context.player_events', $event['capabilities'] ?? [], true)
            && in_array('dialogue.player_reactions', $event['capabilities'] ?? [], true))
            $status = 'init_player_reactions_accepted';
        elseif (in_array('context.player_events', $event['capabilities'] ?? [], true))
            $status = 'init_player_events_accepted';
        elseif ($tracking && in_array('dialogue.quest_reactions', $event['capabilities'] ?? [], true))
            $status = 'init_quest_reactions_accepted';
        elseif ($quests && $tracking) $status = 'init_quest_events_tracking_accepted';
        elseif ($quests) $status = 'init_quest_events_accepted';
        elseif ($tracking) $status = 'init_quest_tracking_accepted';
    }
    $body = synth_protocol_encode_response_line('response_start', [
        'server_version' => strval($GLOBALS['SYNTH_SERVER_VERSION'] ?? 'source'),
    ], 'start');
    $statusPayload = ['code' => $status];
    if ($rechatScene) $statusPayload['rechat_scene'] = true;
    if ($ambientNpc) $statusPayload['ambient_npc'] = true;
    if ($combatTargets) $statusPayload['combat_targets'] = true;
    if ($combatTargets && synth_combat_contacts_supported($event)) $statusPayload['combat_contacts'] = true;
    if ($combatTargets && synth_enemy_awareness_supported($event)) $statusPayload['enemy_awareness'] = true;
    if (isset($detail['detail']) && trim(strval($detail['detail'])) !== '') {
        $statusPayload['detail'] = substr(trim(strval($detail['detail'])), 0, 1024);
    }
    $body .= synth_protocol_encode_response_line('status', $statusPayload, 'status');
    $body .= synth_protocol_encode_response_line('response_end', ['status' => 'complete'], 'end');
    return $body;
}

function synth_protocol_emit_ack(string $status, array $detail = []): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $body=synth_protocol_encode_ack($status,$detail);
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] = $body;
    echo $body;
    $db = $GLOBALS['db'] ?? null;
    if ($db && function_exists('synth_protocol_complete_request')) {
        synth_protocol_complete_request($db, 'complete', 'application/x-ndjson', $body);
    }
}

function synth_protocol_emit_replay(array $claim): void
{
    $body = strval($claim['body'] ?? '');
    if ($body === '') {
        synth_reject_json_request(409, 'Completed request has no replayable response', [
            'code' => strval($claim['error_code'] ?? 'replay_unavailable'),
            'retryable' => false,
        ]);
    }
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: ' . strval($claim['content_type'] ?? 'application/x-ndjson'));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $body;
}

function synth_protocol_emit_terminal_error(string $code, string $detail = ''): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $payload = ['code' => $code];
    if ($detail !== '') {
        $payload['detail'] = substr($detail, 0, 1024);
    }
    $body = synth_protocol_encode_response_line('response_start', [
        'server_version' => strval($GLOBALS['SYNTH_SERVER_VERSION'] ?? 'source'),
    ], 'start');
    $body .= synth_protocol_encode_response_line('status', $payload, 'status');
    $body .= synth_protocol_encode_response_line('response_end', ['status' => 'error'], 'end');
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] = $body;
    echo $body;
    $db = $GLOBALS['db'] ?? null;
    if ($db && function_exists('synth_protocol_complete_request')) {
        synth_protocol_complete_request($db, 'failed', 'application/x-ndjson', $body, $code);
    }
}

function synth_json_response_enabled(): bool
{
    return ($GLOBALS["SYNTH_RESPONSE_FORMAT"] ?? "json") === "json";
}

function synth_response_stream_requested(): bool
{
    return !empty($GLOBALS["SYNTH_RESPONSE_STREAMING"]);
}

function synth_json_streaming_enabled(): bool
{
    return synth_json_response_enabled() && synth_response_stream_requested();
}

function synth_should_generate_npc_tts_before_emit(): bool
{
    return !synth_response_stream_requested();
}

// Native player speech may share the durable queue; legacy and process-local plans stay synchronous.
function synth_should_defer_speech_tts(?array $plan): bool
{
    if (!synth_json_response_enabled()) return false;
    if (synth_protocol_current_speech_role() !== 'player') return true;
    $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    return !empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
        && synth_protocol_owns_dialogue_turn($event)
        && !empty($plan['portable'])
        && in_array($plan['inputs']['TTSFUNCTION'] ?? '',
            ['xtts-fastapi', 'chatterbox', 'pockettts', 'cartesia', 'inworld'], true);
}

function synth_emit_json_response_envelope(array $lines, bool $close = false): void
{
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    synth_protocol_ensure_response_started();
    foreach ($lines as $line) {
        if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
        $action = strtolower(trim(strval($line['action'] ?? '')));
        if ($action === 'say') {
            $speaker = synth_protocol_response_speaker(strval($line['speaker'] ?? ''),$line['speech_role'] ?? null);
            if ($speaker === null) {
                synth_protocol_write_response_line('status', [
                    'code' => 'speaker_identity_unresolved',
                    'detail' => substr('No canonical identity is available for ' . strval($line['speaker'] ?? ''), 0, 1024),
                ], synth_protocol_next_line_suffix('status'));
                continue;
            }
            $text = trim(strval($line['subtitle'] ?? $line['text'] ?? ''));
            if ($text !== '') {
                $payload = [
                    'speaker' => $speaker,
                    'text' => substr($text, 0, 4096),
                ];
                $textOnly = !empty($line['text_only']) || !empty($line['metadata']['text_only']);
                $cacheKey = $textOnly ? '' : synth_npc_tts_status_key(strval($line['tts_cache_key'] ?? ''));
                if ($cacheKey !== '') {
                    $ttsStatus = synth_read_npc_tts_status($cacheKey, dirname(__DIR__));
                    if (isset($ttsStatus['_job_id'])) {
                        require_once __DIR__.'/synth_tts_worker.php';
                        $ttsStatus=synth_tts_resolve_job_status($GLOBALS['db'],$ttsStatus);
                    }
                    if (($ttsStatus['status'] ?? '')==='ready' && is_array($ttsStatus['media'] ?? null)) {
                        try { synth_media_require_ready($GLOBALS['db'] ?? null,$ttsStatus['media']); }
                        catch (SynthMediaException) {
                            $ttsStatus['status']='failed';
                            unset($ttsStatus['media']);
                        }
                    }
                    $state = strval($ttsStatus['status'] ?? 'unknown');
                    if (!in_array($state, ['ready', 'failed'], true)) {
                        $state = 'pending';
                    }
                    $utteranceId = trim(strval($line['utterance_id'] ?? $line['metadata']['utterance_id'] ?? ''));
                    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $utteranceId) !== 1) {
                        $utteranceId = 'utterance:' . substr(hash('sha256', $cacheKey . '|' . $text), 0, 32);
                    }
                    $payload['speech'] = [
                        'schema' => 'synth.speech.v1',
                        'utterance_id' => $utteranceId,
                        'cache_key' => $cacheKey,
                        'status' => $state,
                        'deadline_ms' => 30000,
                    ];
                    if ($state === 'ready' && is_array($ttsStatus['media'] ?? null)) {
                        $payload['speech']['media'] = $ttsStatus['media'];
                    }
                }
                synth_protocol_write_response_line('dialogue', $payload, synth_protocol_next_line_suffix('dialogue'), $line);
            }
            continue;
        }
        if ($action === 'rolecommand') {
            $typedAction = synth_protocol_action_from_legacy_line($line);
            if ($typedAction !== null) {
                synth_protocol_write_response_line('action', ['action' => $typedAction], synth_protocol_next_line_suffix('action'));
            } else {
                synth_protocol_write_response_line('status', [
                    'code' => 'action_capability_unavailable',
                    'detail' => substr('Action was not emitted because its exact actor, target, or negotiated capability was unavailable: ' . strval($line['command_name'] ?? $line['text'] ?? ''), 0, 1024),
                ], synth_protocol_next_line_suffix('status'));
            }
        }
    }
}

function synth_buffer_response_close(): void
{
    $GLOBALS["SYNTH_JSON_RESPONSE_CLOSE"] = true;
}

function synth_abort_json_response(): void
{
    synth_buffer_response_close();
    if (function_exists("terminate")) {
        terminate();
    }
    synth_emit_buffered_json_response();
    die();
}

function synth_buffer_response_line(string $speaker, string $action, string $text, array $metadata = [], ?string $speechRole = null): void
{
    $speaker = trim($speaker);
    $action = trim($action);
    $text = trim($text);
    // Final delivery boundary also covers direct acknowledgements which bypass
    // returnLines. Never retain an audio key for rejected or replaced speech.
    if (strcasecmp($action,'say')===0
        && ($speechRole ?? synth_protocol_current_speech_role($speaker))==='npc') {
        require_once __DIR__.'/synth_character_forms.php';
        $policy=synth_character_current_speech_policy();
        if ($policy!=='') {
            $emitted=(bool)($GLOBALS['SYNTH_CANONICAL_SPEECH_BUFFERED'] ?? false);
            $constrained=synth_character_constrain_lines([$text],$policy,$emitted);
            $GLOBALS['SYNTH_CANONICAL_SPEECH_BUFFERED']=$emitted;
            if ($constrained===[]) return;
            if ($text!==$constrained[0]) unset($metadata['tts_cache_key']);
            $text=$constrained[0];
        }
    }
    if ($speaker === "" && $action === "" && $text === "") {
        return;
    }
    if (strcasecmp($action, "rolecommand") === 0 && $text === "" &&
        trim(strval($metadata["command_name"] ?? $metadata["command"] ?? "")) === "") {
        if (class_exists('Logger')) {
            Logger::warn('[plugin-response] Dropped empty rolecommand response line');
        }
        return;
    }

    $line = [
        "schema" => "synth.response.line.v1",
        "speaker" => $speaker,
        "action" => $action,
        "text" => $text,
    ];

    if (strtolower($action)==='say') {
        // Freeze the producer lane now; buffered emission may happen after its voice/profile is restored.
        // Listener/model metadata must not grant a different speaker role.
        $line['speech_role']=$speechRole ?? synth_protocol_current_speech_role($speaker);
        if (!in_array($line['speech_role'],['npc','player','narrator'],true)) throw new LogicException('invalid_speech_role');
    }
    if (($line['speech_role'] ?? null)==='narrator') {
        $line["display_name"] = function_exists('synthGetNarratorRoleplayName')
            ? synthGetNarratorRoleplayName()
            : 'The Narrator';
    }

    if (strtolower($action) === "say") {
        $listener = strtolower(trim(strval($metadata["listener"] ?? "")));
        // Player producers select their own connector or explicitly emit text-only output.
        // Do not infer that lane from a display name: an NPC can also be named Player.
        $playerSpeech = in_array($listener, ['__player_tts', '__player_menu_tts'], true);
        $textOnly = $listener === '__player_text_only' || !empty($metadata['text_only']) ||
            (!$playerSpeech && strtolower(trim(strval($GLOBALS['TTSFUNCTION'] ?? ''))) === 'none');
        $line["action"] = "say";
        $line["text"] = $text;
        $line["subtitle"] = $text;
        // Freeze the selected profile's decision before another profile can replace the globals.
        $line['text_only'] = $textOnly;
        if ($textOnly) {
            $metadata['text_only'] = true;
            unset($metadata['tts_cache_key']);
        }
        if (!$textOnly) {
            $line["tts_text"] = $text;
            if (function_exists("synth_tts_cache_key")) {
                $line["tts_cache_key"] = synth_tts_cache_key(dirname(__DIR__), $speaker, $text);
            }
            $explicitTtsCacheKey = trim(strval($metadata["tts_cache_key"] ?? ""));
            if ($explicitTtsCacheKey !== "") {
                $line["tts_cache_key"] = $explicitTtsCacheKey;
            }
        }
    }

    $requestId = class_exists('Logger') ? Logger::getRequestId() : ($GLOBALS["SYNTH_REQUEST_ID"] ?? "");
    if ($requestId !== "") {
        $line["request_id"] = $requestId;
        $metadata["request_id"] = $requestId;
    }

    $metadata = array_filter($metadata, static function ($value) {
        return $value !== null && $value !== "";
    });
    if (!empty($metadata)) {
        $topLevelMetadataKeys = [
            "listener",
            "listener_formid",
            "rechat_target",
            "rechat_target_formid",
            "utterance_id",
            "message",
            "target",
            "item",
            "amount",
            "location",
            "npc",
            "speaker_refid",
            "speaker_formid",
            "target_refid",
            "target_formid",
            "item_refid",
            "item_baseid",
            "item_plugin",
            "item_stable_key",
            "location_refid",
            "baseid",
            "command",
            "command_name",
            "payload",
            "model",
            "setting",
            "value",
            "refid",
            "name",
            "character",
            "instruction",
            "task_id",
            "speech",
            "request_type",
            "action_source",
            "authority",
            "display_name",
        ];
        foreach ($topLevelMetadataKeys as $metadataKey) {
            if (isset($metadata[$metadataKey]) && trim(strval($metadata[$metadataKey])) !== "") {
                $line[$metadataKey] = trim(strval($metadata[$metadataKey]));
            }
        }
        if (isset($metadata["command_args"]) && is_array($metadata["command_args"])) {
            $line["command_args"] = array_values(array_map("strval", $metadata["command_args"]));
        }
        $line["metadata"] = $metadata;
    }

    $GLOBALS["SYNTH_JSON_RESPONSE_LINES"][] = $line;

    if (synth_json_streaming_enabled()) {
        if (class_exists('Logger')) {
            $elapsedMs = null;
            if (isset($GLOBALS["SYNTH_TURN_START_TIME"]) && is_numeric($GLOBALS["SYNTH_TURN_START_TIME"])) {
                $elapsedMs = round((microtime(true) - (float)$GLOBALS["SYNTH_TURN_START_TIME"]) * 1000, 2);
            }
            Logger::info("[plugin-response] streamed JSON envelope" . Logger::formatContext([
                "elapsed_ms" => $elapsedMs,
                "speaker" => $line["speaker"] ?? "",
                "action" => $line["action"] ?? "",
                "utterance_id" => $line["utterance_id"] ?? ($line["metadata"]["utterance_id"] ?? ""),
                "tts_cache_key" => $line["tts_cache_key"] ?? "",
                "chars" => strlen((string)($line["text"] ?? "")),
            ]));
        }
        synth_emit_json_response_envelope([$line], false);
    }
}

function synth_buffer_speech_response_line(
    string $speaker,
    string $subtitle,
    string $expression = "",
    string $listener = "",
    string $animation = "",
    string $phonetic = "",
    $volume = null,
    string $rechatTarget = "",
    string $utteranceId = "",
    string $ttsCacheKey = "",
    ?string $speechRole = null
): void {
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    $metadata = [
        "expression" => trim($expression),
        "listener" => trim($listener),
        "animation" => trim($animation),
        "phonetic" => trim($phonetic),
        "volume" => $volume,
        "rechat_target" => trim($rechatTarget),
        "utterance_id" => trim($utteranceId),
        "tts_cache_key" => trim($ttsCacheKey),
    ];
    $speakerFormId = trim((string)($GLOBALS["SYNTH_RESPONSE_SPEAKER_FORMID"] ?? ""));
    $activeSpeaker = trim((string)($GLOBALS["SYNTH_NAME"] ?? ""));
    if ($speakerFormId !== "" && $activeSpeaker !== "" && strcasecmp($speaker, $activeSpeaker) === 0) {
        $metadata["speaker_formid"] = $speakerFormId;
        $metadata["speaker_refid"] = $speakerFormId;
    }
    if (($GLOBALS["gameRequest"][0] ?? "") === "rechat") {
        $previousSpeakerFormId = trim((string)($GLOBALS["RECHAT_REQUEST_PAYLOAD"]["speaker_formid"] ?? ""));
        if ($previousSpeakerFormId !== "") {
            $metadata["listener_formid"] = $previousSpeakerFormId;
            $metadata["rechat_target_formid"] = $previousSpeakerFormId;
        }
    }
    synth_buffer_response_line($speaker, "say", $subtitle, $metadata, $speechRole);
}

function synth_buffer_command_response_line(string $speaker, string $command, array $args = []): void
{
    $decodedCommand = synthDecodeCommandAction($command, $args);
    $metadata = $decodedCommand["metadata"];
    $commandPayload = $decodedCommand["command_payload"];
    $commandName = $decodedCommand["command_name"];
    $commandArgs = $decodedCommand["command_args"];
    if (trim($commandName) === "") {
        if (class_exists('Logger')) {
            Logger::warn('[plugin-response] Dropped command response without a command name');
        }
        return;
    }
    if ($commandPayload !== "") {
        $metadata["command"] = $commandPayload;
    }
    if ($commandName !== "") {
        $metadata["command_name"] = $commandName;
    }
    if (!empty($commandArgs)) {
        $metadata["command_args"] = $commandArgs;
    }

    $normalizedCommand = strtolower($commandName);
    if (!empty($commandArgs)) {
        if (in_array($normalizedCommand, ["attack", "follow", "moveto", "inspect", "travelto"], true)) {
            $metadata["target"] = $metadata["target"] ?? $commandArgs[0];
            if ($normalizedCommand === "travelto") {
                $metadata["location"] = $metadata["location"] ?? $commandArgs[0];
            }
        } elseif ($normalizedCommand === "givecapsto") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[1] ?? "");
        } elseif ($normalizedCommand === "takecapsfromplayer") {
            if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) === 2) {
                $metadata['target'] = $metadata['target'] ?? ($commandArgs[0] ?? '');
                $metadata['amount'] = $metadata['amount'] ?? ($commandArgs[1] ?? '');
            } else $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "giveitemto") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[1] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[2] ?? "");
        } elseif (in_array($normalizedCommand, ["pickupitem", "consume", "equipitem", "unequipitem"], true)) {
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "spawncaps") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[1] ?? "");
        } elseif ($normalizedCommand === "spawnitem") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[1] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[2] ?? "");
        } elseif ($normalizedCommand === "teleportactor") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["location"] = $metadata["location"] ?? ($commandArgs[1] ?? "");
        } elseif ($normalizedCommand === "killtarget") {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "debugnotification") {
            $metadata["message"] = $metadata["message"] ?? ($commandArgs[0] ?? "");
        } elseif ($normalizedCommand === "refreshnpcvoice") {
            $metadata["npc"] = $metadata["npc"] ?? ($commandArgs[0] ?? "");
        } else {
            $metadata["target"] = $metadata["target"] ?? ($commandArgs[0] ?? "");
            $metadata["item"] = $metadata["item"] ?? ($commandArgs[1] ?? "");
            $metadata["amount"] = $metadata["amount"] ?? ($commandArgs[2] ?? "");
        }
    }

    foreach ([
        "message",
        "target",
        "item",
        "amount",
        "location",
        "npc",
        "speaker_refid",
        "speaker_formid",
        "target_refid",
        "target_formid",
        "item_refid",
        "item_baseid",
        "item_plugin",
        "item_stable_key",
        "location_refid",
        "baseid",
        "request_type",
        "action_source",
        "authority",
    ] as $key) {
        if (array_key_exists($key, $args) && is_scalar($args[$key]) && trim(strval($args[$key])) !== "") {
            $metadata[$key] = trim(strval($args[$key]));
        }
    }
    synth_buffer_response_line($speaker, "rolecommand", $commandName, $metadata);
}

function synth_capture_response_output(string $chunk): string
{
    $GLOBALS["SYNTH_JSON_RESPONSE_RAW"] = ($GLOBALS["SYNTH_JSON_RESPONSE_RAW"] ?? "") . $chunk;
    return "";
}

function synth_start_json_response_buffer(): void
{
    $GLOBALS['SYNTH_CANONICAL_SPEECH_EMITTED']=false;
    $GLOBALS['SYNTH_CANONICAL_SPEECH_BUFFERED']=false;
    if (!synth_json_response_enabled()) {
        return;
    }

    $GLOBALS["SYNTH_JSON_RESPONSE_LINES"] = [];
    $GLOBALS["SYNTH_JSON_RESPONSE_RAW"] = "";
    $GLOBALS["SYNTH_JSON_RESPONSE_CLOSE"] = false;
    $GLOBALS["SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED"] = false;
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED'] = false;
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_SEQUENCE'] = 0;
    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] = '';

    $streamingEnabled = synth_json_streaming_enabled();
    if ($streamingEnabled) {
        @ini_set("display_errors", "0");
        @ini_set("output_buffering", "0");
        @ini_set("zlib.output_compression", "0");
    }

    if (PHP_SAPI !== "cli" && !headers_sent()) {
        if ($streamingEnabled) {
            header("Content-Type: application/x-ndjson; charset=utf-8");
            header("Cache-Control: no-cache");
            header("X-Accel-Buffering: no");
        } else {
            header("Content-Type: application/x-ndjson; charset=utf-8");
            header("Cache-Control: no-store");
        }
    }

    if ($streamingEnabled) {
        return;
    }

    ob_start("synth_capture_response_output");
    $GLOBALS["SYNTH_JSON_RESPONSE_BUFFER_LEVEL"] = ob_get_level();
}

function synth_emit_buffered_json_response(): void
{
    if (!synth_json_response_enabled()) {
        return;
    }
    if (!empty($GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'])) return;
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();

    if (synth_json_streaming_enabled()) {
        if (!empty($GLOBALS["SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED"])) {
            return;
        }

        $GLOBALS["SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED"] = true;
        synth_protocol_ensure_response_started();
        synth_protocol_write_response_line('response_end', ['status' => 'complete'], 'end');
        $db = $GLOBALS['db'] ?? null;
        if ($db && function_exists('synth_protocol_complete_request')) {
            synth_protocol_complete_request(
                $db,
                'complete',
                'application/x-ndjson',
                strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? '')
            );
            require_once __DIR__.'/relationship_response.php';
            synth_relationship_complete_inline_response($db);
        }
        return;
    }

    $bufferLevel = intval($GLOBALS["SYNTH_JSON_RESPONSE_BUFFER_LEVEL"] ?? 0);
    if ($bufferLevel > 0) {
        while (ob_get_level() >= $bufferLevel) {
            @ob_end_clean();
        }
    }

    synth_emit_json_response_envelope($GLOBALS["SYNTH_JSON_RESPONSE_LINES"] ?? []);
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    synth_protocol_write_response_line('response_end', ['status' => 'complete'], 'end');
    $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'] = true;
    $db = $GLOBALS['db'] ?? null;
    if ($db && function_exists('synth_protocol_complete_request')) {
        synth_protocol_complete_request(
            $db,
            'complete',
            'application/x-ndjson',
            strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? '')
        );
        require_once __DIR__.'/relationship_response.php';
        synth_relationship_complete_inline_response($db);
    }
}

// Discard any captured legacy output and close an interrupted response exactly once.
function synth_emit_cancelled_json_response(): void
{
    if (!empty($GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'])) return;
    $bufferLevel = intval($GLOBALS['SYNTH_JSON_RESPONSE_BUFFER_LEVEL'] ?? 0);
    if ($bufferLevel > 0) {
        while (ob_get_level() >= $bufferLevel) @ob_end_clean();
    }
    synth_protocol_ensure_response_started();
    synth_protocol_write_response_line('response_end', ['status' => 'cancelled'], 'end');
    $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'] = true;
}

?>
