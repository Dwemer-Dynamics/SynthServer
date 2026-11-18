<?php

// Explicitly selected native Auto Chat (payload.control.mode AUTOCHAT): one validated generated player line per
// claimed input turn. The line replaces only the derived structured input text, is stored as that turn's player
// history row and published once as the player's own speech, then the ordinary targeted NPC route answers it.
// The canonical event and dialogue_origin_policies.input_text keep the raw intent exactly. Legacy `**` respeech
// and its player_rewrite.php subprocess are never used here.

require_once __DIR__.'/synth_protocol_state.php';

// Closed failure details for the terminal provider_failed status; anything else is reported as provider_failed.
const SYNTH_AUTOCHAT_FAILURES = ['provider_unsupported', 'provider_failed', 'provider_stream_incomplete',
    'provider_stream_malformed', 'provider_output_malformed', 'provider_output_incomplete', 'provider_output_oversized',
    'provider_output_empty', 'rewrite_input_invalid', 'rewrite_input_oversized', 'rewrite_target_mismatch',
    'rewrite_output_malformed', 'rewrite_output_oversized', 'rewrite_output_empty', 'rewrite_output_unsafe'];

function synth_autochat_selected(array $event): bool
{
    return ($event['protocol_version'] ?? null) === 2
        && in_array($event['type'] ?? '', ['input_text', 'input_audio'], true)
        && ($event['payload']['control']['mode'] ?? null) === 'AUTOCHAT';
}

// Deterministic per claimed request, so a repeated stage of the same owner finds its own row and route.
function synth_autochat_utterance_id(array $event): string
{
    return 'autochat.'.hash('sha256', json_encode([$event['session_id'] ?? null, $event['request_id'] ?? null,
        $event['generation'] ?? null, $event['turn_id'] ?? null, $event['context_sequence'] ?? null], JSON_THROW_ON_ERROR));
}

function synth_autochat_failure(Throwable $error): string
{
    return in_array($error->getMessage(), SYNTH_AUTOCHAT_FAILURES, true) ? $error->getMessage() : 'provider_failed';
}

// The accepted line of the current claimed request, or null for every other turn.
function synth_autochat_line(): ?array
{
    $state = $GLOBALS['SYNTH_AUTOCHAT'] ?? null;
    $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (!is_array($state) || empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || !synth_autochat_selected($event)
        || ($state['utterance_id'] ?? null) !== synth_autochat_utterance_id($event)) return null;
    return $state;
}

// The rewrite's pair context, oldest first, from the native scoped reader's rows (newest first). SynthPlayerRewrite
// labels every user entry as the player, so only two kinds of row are kept: the target's own heard replies (`own`,
// the reader's insertion-time speaker binding) and player input (the reader returns inputtext only when its bound
// speaker is the player) whose stored talking-to addressee is exactly this target. Witnessed lines of other NPCs,
// observed vanilla dialogue and player input addressed elsewhere or to nobody are omitted, never relabelled; they
// keep their witness scope in every other reader.
function synth_autochat_pair_history(array $rows, string $targetName): array
{
    require_once dirname(__DIR__).'/managers/ContextManager.php';
    $messages = [];
    foreach (array_reverse($rows) as $row) {
        $text = strval($row['data'] ?? '');
        $addressed = preg_match('/\s+\((?:talking|Talking) to ([^()]*)\)$/u', $text, $match, PREG_OFFSET_CAPTURE) === 1;
        $own = ($row['type'] ?? null) === 'chat' && intval($row['own'] ?? 0) === 1;
        if (!$own && !(($row['type'] ?? null) === 'inputtext' && $addressed && $match[1][0] === $targetName)) continue;
        if ($addressed) $text = substr($text, 0, $match[0][1]);
        $colon = strpos($text, ': ');
        $text = trim($colon === false ? $text : substr($text, $colon + 2));
        if ($text !== '') $messages[] = ['role' => $own ? 'assistant' : 'user',
            'content' => mb_substr($text, 0, Synth\Managers\ContextManager::LINE_CHARS)];
    }
    return $messages;
}

// One player rewrite call for the claimed owner's exact bound player and target. Every read is scoped: the claimed
// snapshot, that snapshot's saved-ancestry pair history with this target, the configured CORE_CONNECTOR_PLAYER row
// and the server's own player profile; never a latest-name row. Throws on any failure; nothing is retried.
function synth_autochat_rewrite($db, array $event, ?string $transcript): array
{
    require_once __DIR__.'/synth_dialogue_policy.php';
    require_once __DIR__.'/synth_player_rewrite.php';
    require_once __DIR__.'/core/llm_connector.class.php';
    require_once dirname(__DIR__).'/drivers/llm/LlmWrapper.php';
    require_once dirname(__DIR__).'/drivers/llm/PlayerRewriteDriver.php';
    require_once dirname(__DIR__).'/managers/ContextManager.php';
    if (!synth_autochat_selected($event) || empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
        || $event !== ($GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null)) throw new LogicException('autochat_owner_required');
    // One owner guard for the component and its driver: same claimed event, then the canonical liveness check.
    $assertActive = static function () use ($event): void {
        if ($event !== ($GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null)) throw new LogicException('autochat_owner_changed');
        synth_protocol_assert_request_active();
    };
    $assertActive();
    $intent = synth_dialogue_origin_input($event, $transcript);
    $saves = new Synth\Managers\SaveState(new Synth\Core\SaveRepository($db));
    $context = $saves->boundContext($event);
    $target = is_array($context['target'] ?? null) ? $context['target'] : null;
    $key = $target === null ? null : Synth\Core\CharacterKey::of($target);
    $responder = $GLOBALS['SYNTH_INPUT_TARGET_IDENTITY'] ?? null;
    // The addressed target must also be the responder this request was bound to; Auto Chat never redirects.
    if ($key === null || !is_array($responder) || Synth\Core\CharacterKey::of($responder) !== $key) {
        throw new DomainException('rewrite_target_mismatch');
    }
    $request = new Synth\Core\TurnRequest($event, $context);
    $placed = new Synth\Core\PlacedCharacter($key, $target, 0, [], [], []);
    $history = synth_autochat_pair_history($saves->conversationRows($event, $placed->displayName(),
        Synth\Managers\ContextManager::HISTORY_ROWS), $placed->displayName());
    $player = [];
    foreach ($db->fetchAll("SELECT config_key AS id, config_value AS value FROM public.core_config_setting WHERE scope = 'player' AND config_key IN ('bio','speech_style')") ?: [] as $row) {
        $player[strval($row['id'])] = strval($row['value'] ?? '');
    }
    $connectors = new LLMConnector();
    $connectorId = intval($GLOBALS['CORE_CONNECTOR_PLAYER'] ?? 0);
    $connector = $connectorId > 0 ? $connectors->getById($connectorId) : null;
    if (!is_array($connector)) throw new DomainException('provider_unsupported');
    // The existing HTTP_TIMEOUT reaches the driver only as this call's transient config; no new setting.
    $timeout = $GLOBALS['HTTP_TIMEOUT'] ?? Synth\Drivers\Llm\PlayerRewriteDriver::DEFAULT_HTTP_TIMEOUT;
    $driver = new Synth\Drivers\Llm\LlmWrapper([new Synth\Drivers\Llm\PlayerRewriteDriver($assertActive,
        static fn(array $row): array => $connectors->dialogueProviderConfig($row) + ['http_timeout' => $timeout])]);
    $line = (new SynthPlayerRewrite($driver, $assertActive))->rewrite($request, $placed, $intent, $connector, $player, $history);
    // The ordinary input adapter collapses whitespace and pipes; such a line would differ in history and on the wire.
    require_once __DIR__.'/request.php';
    if (synth_sanitize_pipeline_input_fragment($line) !== $line) throw new UnexpectedValueException('rewrite_output_unsafe');
    return ['text' => $line, 'target' => $target, 'player' => trim(strval($context['player']['display_name'] ?? ''))];
}

// Places the accepted line into the derived structured input only. The canonical event, the frozen origin policy and
// every other bound field, including the exact selected target, are untouched. The derived history text is frozen
// later, right after the ordinary input adapter (synth_autochat_freeze_input).
function synth_autochat_prepare($db, array $event, array &$gameRequest, ?string $transcript): void
{
    $structured = json_decode(strval($gameRequest[3] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    if (($gameRequest[0] ?? '') !== 'inputtext' || !is_array($structured) || ($structured['schema'] ?? '') !== 'synth.input.v1') {
        throw new LogicException('autochat_input_unbound');
    }
    $accepted = synth_autochat_rewrite($db, $event, $transcript);
    $structured['text'] = $accepted['text'];
    $gameRequest[3] = json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $GLOBALS['SYNTH_AUTOCHAT'] = ['text' => $accepted['text'], 'target' => $accepted['target'], 'player' => $accepted['player'],
        'utterance_id' => synth_autochat_utterance_id($event), 'published' => false, 'data' => null];
}

// Runs once, right after the ordinary input adapter. The adapter's fields must be the bound structured input with the
// accepted text, the canonical target and the bound player, and its derived text exactly
// `<player>: <line> (Talking to <target>)` as that adapter writes it (Everyone never carries Auto Chat). That exact
// derived text is frozen for the history row.
function synth_autochat_freeze_input(array $adapted, array $gameRequest): void
{
    require_once __DIR__.'/request.php';
    $line = synth_autochat_line();
    $fields = $GLOBALS['SYNTH_STRUCTURED_INPUT_FIELDS'] ?? null;
    $target = $line['target'] ?? null;
    if ($line === null || ($line['data'] ?? null) !== null || !is_array($fields) || !is_array($target) || empty($adapted['changed'])
        || ($fields['text'] ?? null) !== $line['text'] || ($fields['player'] ?? null) !== $line['player']
        || ($fields['npc'] ?? null) !== trim(strval($target['display_name'])) || ($fields['npc_id'] ?? null) !== $target['form_id']
        || ($fields['npc_plugin'] ?? null) !== $target['origin_plugin'] || ($fields['playthrough_id'] ?? null) !== $target['playthrough_id']) {
        throw new LogicException('autochat_derived_text_mismatch');
    }
    $player = synth_sanitize_pipeline_input_fragment($line['player']);
    $label = synth_sanitize_pipeline_input_fragment(str_replace(['(', ')'], ' ', $fields['npc']));
    $data = $player.': '.$line['text'].' (Talking to '.$label.')';
    if ($player === '' || $label === '' || ($adapted['text'] ?? null) !== $line['text'] || ($adapted['player'] ?? null) !== $player
        || ($adapted['target'] ?? null) !== $label || ($adapted['display'] ?? null) !== $data || ($gameRequest[3] ?? null) !== $data
        || ($GLOBALS['SYNTH_PLAYER_INPUT_TEXT'] ?? null) !== $line['text'] || ($GLOBALS['SYNTH_INPUT_TARGET'] ?? null) !== $label) {
        throw new LogicException('autochat_derived_text_mismatch');
    }
    $GLOBALS['SYNTH_AUTOCHAT']['data'] = $data;
}

// The turn's player history row under its deterministic utterance id. Its text must be exactly the frozen derived
// text. Its delivery_state is set to `received` before anything reaches the wire: the server accepted this generated
// line as the turn's player input. It is not the raw intent (kept by the canonical event and the origin policy) and
// is never `spoken` or `emitted`; publication is the response route written with the line, not a history change.
function synth_autochat_record_history($db, array $data): void
{
    $line = synth_autochat_line();
    if ($line === null || !is_string($line['data'] ?? null) || ($data['type'] ?? '') !== 'inputtext'
        || ($data['data'] ?? null) !== $line['data'] || array_key_exists('utterance_id', $data)
        || !in_array($data['delivery_state'] ?? null, [null, 'received'], true)) {
        throw new LogicException('autochat_history_mismatch');
    }
    $db->insertNativeHistoryOnce(['utterance_id' => $line['utterance_id'], 'delivery_state' => 'received'] + $data);
}

// Publishes the accepted line once per owner as the player's own speech, addressed to the exact target under the
// deterministic utterance id. It is voiced only through the player's configured TTS, generated before the line is
// buffered, otherwise text-only. Buffering is not delivery: the route is recorded when the line is written, and
// nothing here marks the line spoken, played or heard.
function synth_autochat_emit_player_line(bool $voiced): bool
{
    $line = synth_autochat_line();
    if ($line === null) return false;
    if ($line['published']) return true;
    synth_protocol_assert_request_active();
    $target = $line['target'];
    $metadata = ['listener' => $target['display_name'], 'listener_formid' => $target['form_id'],
        'listener_plugin' => $target['origin_plugin'], 'utterance_id' => $line['utterance_id'], 'volume' => 1.0];
    $seed = null;
    if ($voiced) {
        require_once __DIR__.'/synth_tts_plan.php';
        // A voice failure keeps the line text-visible (failed speech status, or text-only when no plan could be
        // captured); provider errors are not logged.
        try {
            $plan = synth_capture_tts_plan(dirname(__DIR__), 'Player', $line['text'], 'default', $line['text']);
            $seed = $plan['cache_seed'];
            $output = synth_call_planned_npc_tts($plan, $line['text'], 'default', $seed);
        } catch (SYNTHProtocolCancelled $error) { throw $error; }
        catch (Throwable) { $output = false; }
        if ($seed !== null) synth_finish_immediate_npc_tts($seed, $output, 'Player', $line['utterance_id']);
        synth_protocol_assert_request_active();
    }
    if ($seed !== null) $metadata['tts_cache_key'] = md5($seed);
    else $metadata['text_only'] = true;
    // Marked only once the line is buffered: a buffering failure leaves it unpublished and propagates, which ends
    // the root before any NPC provider call.
    synth_buffer_response_line('Player', 'say', $line['text'], $metadata, 'player');
    $GLOBALS['SYNTH_AUTOCHAT']['published'] = true;
    return true;
}

// An Auto Chat rechat root is the one generated line it published, never its raw intent: exactly one player line of
// the stored complete root response whose recorded route (hash and speaker already matched by the caller) is
// addressed to the root's original target under the root's deterministic utterance id. Anything else fails closed.
// The text is prompt context for the continuation only, not proof that anyone heard it.
function synth_autochat_root_line(array $response, array $root): string
{
    $target = is_array($response['target'] ?? null) ? synth_rechat_actor_key($response['target']) : null;
    $lines = $response['player_lines'] ?? [];
    if ($target === null || !synth_autochat_selected($root) || count($lines) !== 1) {
        throw new LogicException('rechat_history_autochat_unproven');
    }
    $route = $lines[0]['route'];
    $listener = $route['listener'] === null ? null : json_decode($route['listener'], true, flags: JSON_THROW_ON_ERROR);
    if (($route['listener_state'] ?? null) !== 'addressed' || !is_array($listener)
        || synth_rechat_actor_key($listener) !== $target
        || ($route['utterance_id'] ?? null) !== synth_autochat_utterance_id($root)) {
        throw new LogicException('rechat_history_autochat_unproven');
    }
    return $lines[0]['text'];
}
