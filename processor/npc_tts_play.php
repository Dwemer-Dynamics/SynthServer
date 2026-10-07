<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $path;
$GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
$GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
$GLOBALS['SYNTH_GAME_ID'] = 'fo4';
$GLOBALS['SYNTH_WORLD_NAME'] = 'Commonwealth';
$GLOBALS['TTS_FFMPEG_FILTERS'] = [];
$GLOBALS['AUDIT_RUNID_REQUEST'] = 'external_npc_tts';

require_once $path . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
require_once $path . 'lib' . DIRECTORY_SEPARATOR . 'request.php';
require_once $path . 'lib' . DIRECTORY_SEPARATOR . 'response.php';
require_once $path . 'lib' . DIRECTORY_SEPARATOR . 'synth_protocol_state.php';
require_once $path . 'lib' . DIRECTORY_SEPARATOR . 'synth_tts.php';
require_once $path . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';

synthRuntimeBootstrap($path, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => true,
    'load_player_name' => true,
    'load_narrator' => false,
    'run_db_updates' => false,
]);

$event = synth_decode_event_from_request();
if (($event['type'] ?? '') !== 'trigger' || ($event['json']['payload']['kind'] ?? '') !== 'external_tts') {
    synth_reject_json_request(422, 'Expected a synth.event.v1 external_tts trigger');
}

$wireEvent = $event['json'];
$gameRequest = synth_event_to_game_request($event);
try {
    if (!synth_protocol_bind_trigger_context($GLOBALS['db'], $wireEvent, $gameRequest)) {
        synth_reject_json_request(409, 'The requested actor is not eligible in the current context');
    }
} catch (Throwable $error) {
    Logger::warn('[external NPC TTS] Context binding rejected: ' . $error->getMessage());
    synth_reject_json_request(409, 'The requested actor does not match the active context');
}

$requestId = trim(strval($wireEvent['request_id'] ?? ''));
if ($requestId !== '') {
    Logger::setRequestId($requestId);
}
$speaker = trim(strval($GLOBALS['SYNTH_INPUT_TARGET'] ?? ''));
$speakerFormId = trim(strval($GLOBALS['SYNTH_RESPONSE_SPEAKER_FORMID'] ?? ''));
$text = trim(strval($wireEvent['payload']['text'] ?? ''));
if ($speaker === '' || $speakerFormId === '' || $text === '') {
    synth_reject_json_request(422, 'External NPC TTS requires an actor identity and text');
}

if (!synth_tts_generate_for_response($root, $speaker, $text)) {
    synth_reject_json_request(502, 'NPC TTS generation failed');
}

synth_start_json_response_buffer();
$utteranceId = 'external_' . substr(hash('sha256', $requestId . '|' . $speakerFormId . '|' . $text), 0, 20);
synth_buffer_response_line($speaker, 'say', $text, [
    'speaker_formid' => $speakerFormId,
    'speaker_refid' => $speakerFormId,
    'utterance_id' => $utteranceId,
    'tts_cache_key' => synth_tts_cache_key($root, $speaker, $text),
    'request_type' => 'external_npc_tts',
    'action_source' => 'synth_native_event',
]);
synth_emit_buffered_json_response();

?>
