<?php

declare(strict_types=1);

// POST /api/v1/tts/studio: speaks the fixed Studio test line with the player's configured TTS connector and answers
// the same JSON as ui/api/player_tts_preview.php ({"status": "success", "text", "url"} or {"status": "error", ...}).
// A management route: speech costs provider calls, so the management guard (Host allowlist, same-origin evidence) and
// the dashboard session token (body or X-CSRF-Token) are required before the legacy preview runs. An unconfigured or
// failing voice answers the preview's own error JSON; it never reaches the game.

$synthTtsEngine = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
require_once $synthTtsEngine . 'lib' . DIRECTORY_SEPARATOR . 'management_request_guard.php';

/** Sends a fixed-text JSON refusal and ends the request. */
function synthTtsStudioRefuse(int $status, string $code, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($status === 403) {
        header('Vary: Host, Origin, Referer, Sec-Fetch-Site');
    }
    echo json_encode(['status' => 'error', 'code' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$synthTtsRefusal = synthManagementRequestRefusal($_SERVER, 'browser');
if ($synthTtsRefusal !== null) {
    synthTtsStudioRefuse(403, 'forbidden', $synthTtsRefusal);
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    synthTtsStudioRefuse(405, 'method_not_allowed', 'Use POST.');
}
$synthTtsRaw = file_get_contents('php://input', false, null, 0, 1025);
$synthTtsBody = is_string($synthTtsRaw) && $synthTtsRaw !== '' ? json_decode($synthTtsRaw, true, 4) : [];
if (strlen((string) $synthTtsRaw) > 1024 || !is_array($synthTtsBody)) {
    synthTtsStudioRefuse(400, 'invalid_input', 'Send a small JSON object or nothing.');
}
if (!synthManagementCsrfValid($synthTtsBody['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    synthTtsStudioRefuse(403, 'csrf', 'The page expired. Reload it and try again.');
}
session_write_close();

require $synthTtsEngine . 'ui' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'player_tts_preview.php';
