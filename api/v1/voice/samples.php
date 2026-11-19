<?php

declare(strict_types=1);

// POST /api/v1/voice/samples.php: read-only list of the voice samples stored under data/voices.
// Body: {"csrf_token": "...", "voice_id": "<id>"?, "npc_id": N?, "include_hash": true?}. Metadata only (no file
// paths, provider settings or secrets) but it includes sample transcripts, so it is a management route like the
// others: the management guard (Host allowlist, same-origin evidence) and the dashboard session token are both
// required. It is POST so that a cross-site page cannot read it, and it writes nothing. The SHA-256 is computed only
// when asked for and only for one voice (voice_id or npc_id), never for the whole folder.

use Synth\Managers\VoiceSample\VoicePreviewManager;
use Synth\Managers\VoiceSample\VoiceSampleStore;

require_once __DIR__ . '/../../../lib/classes/Logger.php';
ob_start();

/**
 * Sends a JSON reply and ends the request.
 *
 * @param array<string,mixed> $payload
 */
function synthVoiceSamplesRespond(array $payload, int $status = 200): never
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
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$enginePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
$managerPath = $enginePath . 'managers' . DIRECTORY_SEPARATOR . 'VoiceSample' . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'management_request_guard.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'RequestBody.php';
require_once $managerPath . 'VoiceSampleStore.php';

$refusal = synthManagementRequestRefusal($_SERVER, 'browser');
if ($refusal !== null) {
    header('Vary: Host, Origin, Referer, Sec-Fetch-Site');
    synthVoiceSamplesRespond(['ok' => false, 'error' => $refusal], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Use POST.'], 405);
}
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Send a JSON request.'], 415);
}
$length = $_SERVER['CONTENT_LENGTH'] ?? null;
if (!is_string($length) || !ctype_digit($length) || (int) $length > 4096) {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Send a JSON body of at most 4096 bytes with a Content-Length.'], is_string($length) && ctype_digit($length) ? 413 : 411);
}
$raw = \Synth\Lib\RequestBody::read();
$request = is_string($raw) ? json_decode($raw, true, 4) : null;
if (!is_array($request) || array_is_list($request) || array_diff(array_keys($request), ['csrf_token', 'voice_id', 'npc_id', 'include_hash'])) {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Invalid request.'], 400);
}
// The dashboard session token, in the body or an X-CSRF-Token header.
if (!synthManagementCsrfValid($request['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'The page expired. Reload it and try again.'], 403);
}
session_write_close();

$voice = $request['voice_id'] ?? null;
$npcId = $request['npc_id'] ?? null;
$withHash = $request['include_hash'] ?? false;
if (($voice !== null && !VoiceSampleStore::isValidId($voice))
    || ($npcId !== null && (!is_int($npcId) || $npcId < 1))
    || ($voice !== null && $npcId !== null)
    || !is_bool($withHash)
    || ($withHash && $voice === null && $npcId === null)) {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Invalid voice_id, npc_id or include_hash (a hash needs one voice).'], 400);
}

$npc = null;
if ($npcId !== null) {
    try {
        require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
        require_once $managerPath . 'VoicePreviewException.php';
        require_once $managerPath . 'VoicePreviewManager.php';
        synthRuntimeBootstrap($enginePath, [
            'rotate_logs' => false, 'load_general_settings' => false, 'load_stt_connector' => false, 'load_tts_connector' => false,
        ]);
        $row = (new VoicePreviewManager($enginePath))->findNpc($npcId);
    } catch (Throwable $e) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'SYNTH voice samples', 'NPC lookup failed: ' . get_class($e));
        synthVoiceSamplesRespond(['ok' => false, 'error' => 'The NPC database is unavailable.'], 503);
    }
    if ($row === null) {
        synthVoiceSamplesRespond(['ok' => false, 'error' => 'Unknown NPC.'], 404);
    }
    $savedVoice = trim((string) ($row['voiceid'] ?? ''));
    $npc = [
        'id' => (int) $row['id'], 'name' => (string) ($row['npc_name'] ?? ''),
        'voice_id' => $savedVoice !== '' ? $savedVoice : null,
    ];
    $voice = VoiceSampleStore::isValidId($savedVoice) ? $savedVoice : '';
}

try {
    $samples = $voice === '' ? [] : VoiceSampleStore::listSamples($enginePath . 'data' . DIRECTORY_SEPARATOR . 'voices', $voice, $withHash);
} catch (Throwable $e) {
    \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'SYNTH voice samples', 'List failed: ' . get_class($e));
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Voice samples are unavailable.'], 503);
}
$payload = ['ok' => true, 'voice_samples' => $samples, 'count' => count($samples)];
if ($npc !== null) {
    $npc['has_sample'] = $samples !== [];
    $payload['npc'] = $npc;
}
synthVoiceSamplesRespond($payload);
