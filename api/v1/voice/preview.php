<?php

declare(strict_types=1);

// POST /api/v1/voice/preview.php: speaks one short line with an NPC's saved voice and answers audio/wav.
// Body: {"npc_id": N, "text": "...", "csrf_token": "..."}. A management route: the management guard (Host
// allowlist, same-origin evidence) and the dashboard session token are both required, before any database or
// provider work. The logic lives in VoicePreviewManager; this entry point validates and answers.

use Synth\Managers\TtsStudio\VoicePreviewException;
use Synth\Managers\TtsStudio\VoicePreviewManager;

require_once __DIR__ . '/../../../lib/classes/Logger.php';
ob_start();

/**
 * Sends a fixed-text JSON error and ends the request.
 */
function synthVoicePreviewFail(int $status, string $code, string $message): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($status === 405) {
        header('Allow: POST');
    }
    echo json_encode(['ok' => false, 'code' => $code, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$enginePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
$managerPath = $enginePath . 'managers' . DIRECTORY_SEPARATOR . 'TtsStudio' . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'management_request_guard.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'RequestBody.php';

$refusal = synthManagementRequestRefusal($_SERVER, 'browser');
if ($refusal !== null) {
    header('Vary: Host, Origin, Referer, Sec-Fetch-Site');
    synthVoicePreviewFail(403, 'forbidden', $refusal);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    synthVoicePreviewFail(405, 'method_not_allowed', 'Use POST.');
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    synthVoicePreviewFail(415, 'unsupported_media_type', 'Send a JSON request.');
}
$length = $_SERVER['CONTENT_LENGTH'] ?? null;
if (!is_string($length) || !ctype_digit($length)) {
    synthVoicePreviewFail(411, 'length_required', 'A Content-Length is required.');
}
if ((int) $length > 4096) {
    synthVoicePreviewFail(413, 'too_large', 'The request is too large.');
}
$raw = \Synth\Lib\RequestBody::read();
$request = is_string($raw) ? json_decode($raw, true, 4) : null;
if (!is_array($request) || array_is_list($request) || array_diff(array_keys($request), ['npc_id', 'text', 'csrf_token'])) {
    synthVoicePreviewFail(400, 'invalid_input', 'Send a JSON object with npc_id and text.');
}
// The dashboard session token, in the body or an X-CSRF-Token header.
if (!synthManagementCsrfValid($request['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    synthVoicePreviewFail(403, 'csrf', 'The page expired. Reload it and try again.');
}
session_write_close();

$npcId = $request['npc_id'] ?? null;
$text = $request['text'] ?? VoicePreviewManager::DEFAULT_TEXT;
if (!is_int($npcId) || $npcId < 1 || !is_string($text)) {
    synthVoicePreviewFail(400, 'invalid_input', 'npc_id must be a positive integer and text a string.');
}

$GLOBALS['ENGINE_PATH'] = $enginePath;
try {
    require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
    // A preview is not a game turn: no log rotation, no settings seeding.
    try {
        synthRuntimeBootstrap($enginePath, [
            'rotate_logs' => false, 'load_general_settings' => true, 'load_stt_connector' => false,
            'load_tts_connector' => false, 'load_player_name' => true, 'seed_narrator' => false,
        ]);
    } catch (Throwable $e) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'SYNTH voice preview', 'Bootstrap failed: ' . get_class($e));
        throw new VoicePreviewException(503, 'unavailable', 'The SYNTH database is unavailable.');
    }
    $result = (new VoicePreviewManager($enginePath))->preview($npcId, $text);
} catch (VoicePreviewException $failure) {
    synthVoicePreviewFail($failure->status, $failure->reason, $failure->getMessage());
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
http_response_code(200);
header('Content-Type: audio/wav');
header('Cache-Control: no-store');
// Dialogue speaks an NPC without a saved voice with the connector's fallback voice; say when this preview did.
if ($result['usedFallback']) {
    header('X-Voice-Fallback: 1');
}
header('Content-Length: ' . strlen($result['audio']));
echo $result['audio'];
