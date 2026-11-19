<?php

declare(strict_types=1);

// POST /api/v1/tts/voice-sample: the samples of ONE voice. Body: {"csrf_token": "...", "voice_id": "<id>" | "npc_id": N,
// "include_hash": true?}. It is the voice-samples handler with one voice required: a body that names neither voice_id
// nor npc_id is refused, so this route never lists the whole folder. Everything else (management guard, dashboard
// token, validation, optional SHA-256) is the voice-samples handler's.

$synthTtsEngine = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
require_once $synthTtsEngine . 'lib' . DIRECTORY_SEPARATOR . 'management_request_guard.php';

$synthTtsRefusal = synthManagementRequestRefusal($_SERVER, 'browser');
if ($synthTtsRefusal !== null) {
    // The shared handler answers the guard refusal in its own envelope.
    require dirname(__DIR__) . '/voice/samples.php';
    return;
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $synthTtsRaw = file_get_contents('php://input', false, null, 0, 4097);
    $synthTtsBody = is_string($synthTtsRaw) ? json_decode($synthTtsRaw, true, 4) : null;
    if (is_array($synthTtsBody) && !array_is_list($synthTtsBody)
        && !array_key_exists('voice_id', $synthTtsBody) && !array_key_exists('npc_id', $synthTtsBody)
        && synthManagementCsrfValid($synthTtsBody['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'Send voice_id or npc_id.'], JSON_UNESCAPED_SLASHES);
        return;
    }
}
require dirname(__DIR__) . '/voice/samples.php';
