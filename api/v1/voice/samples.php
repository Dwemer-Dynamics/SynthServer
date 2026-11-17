<?php

declare(strict_types=1);

// GET /api/v1/voice/samples.php: read-only list of the voice samples stored under data/voices.
// Metadata only (no file paths, provider settings or secrets). Optional filters: ?voice_id=<id> for one voice,
// ?npc_id=<n> for an NPC's saved voice. A dashboard route: the management guard (Host allowlist, same-origin
// evidence) is applied here because the guard only covers ui/ scripts on its own.

use Synth\Managers\VoiceSample\VoicePreviewManager;
use Synth\Managers\VoiceSample\VoiceSampleStore;

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
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$enginePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
$managerPath = $enginePath . 'managers' . DIRECTORY_SEPARATOR . 'VoiceSample' . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'management_request_guard.php';
require_once $managerPath . 'VoiceSampleStore.php';

$refusal = synthManagementRequestRefusal($_SERVER, 'browser');
if ($refusal !== null) {
    header('Vary: Host, Origin, Referer, Sec-Fetch-Site');
    synthVoiceSamplesRespond(['ok' => false, 'error' => $refusal], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Use GET.'], 405);
}

$voice = $_GET['voice_id'] ?? null;
$npcId = $_GET['npc_id'] ?? null;
if (($voice !== null && !VoiceSampleStore::isValidId($voice))
    || ($npcId !== null && (!is_string($npcId) || preg_match('/^[1-9][0-9]{0,9}$/D', $npcId) !== 1))
    || ($voice !== null && $npcId !== null)) {
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Invalid voice_id or npc_id.'], 400);
}

$npc = null;
if ($npcId !== null) {
    try {
        require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
        require_once $managerPath . 'VoicePreviewException.php';
        require_once $managerPath . 'VoicePreviewManager.php';
        // A GET never rotates logs or seeds settings; only the database connection is needed.
        synthRuntimeBootstrap($enginePath, [
            'load_general_settings' => false, 'load_stt_connector' => false, 'load_tts_connector' => false,
        ]);
        $row = (new VoicePreviewManager($enginePath))->findNpc((int) $npcId);
    } catch (Throwable $e) {
        error_log('[SYNTH voice samples] NPC lookup failed: ' . get_class($e));
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
    $samples = $voice === '' ? [] : VoiceSampleStore::listSamples($enginePath . 'data' . DIRECTORY_SEPARATOR . 'voices', $voice);
} catch (Throwable $e) {
    error_log('[SYNTH voice samples] List failed: ' . get_class($e));
    synthVoiceSamplesRespond(['ok' => false, 'error' => 'Voice samples are unavailable.'], 503);
}
$payload = ['ok' => true, 'voice_samples' => $samples, 'count' => count($samples)];
if ($npc !== null) {
    $npc['has_sample'] = $samples !== [];
    $payload['npc'] = $npc;
}
synthVoiceSamplesRespond($payload);
