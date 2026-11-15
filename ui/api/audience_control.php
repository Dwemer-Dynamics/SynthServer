<?php

// Dashboard audience preference (normal/everyone) for one explicitly chosen game session generation.
// It saves the server-side preference only; the game applies it on its next SYNTH Control refresh. Mode
// changes are owned by the global mode writers, not this endpoint.

ob_start();

function synthAudienceRespond(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// The root .htaccess sends Access-Control-Allow-Origin: *, so refuse cross-origin browser requests before any
// session, CSRF token or database access. This is CSRF/read-leak defence, not authentication: requests without
// Origin or Sec-Fetch-Site (direct operator HTTP clients) keep the existing dashboard network trust.
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowed = $fetchSite === null || in_array($fetchSite, ['same-origin', 'none'], true);
if ($allowed && $origin !== null) {
    // Exact scheme, host and port match against this request; Host only, no forwarded headers.
    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? null;
    $authority = '(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?';
    $port = static fn(array $m, int $i): int => ($m[$i] ?? '') !== '' ? (int) $m[$i] : ($scheme === 'https' ? 443 : 80);
    $allowed = is_string($origin) && is_string($host)
        && preg_match("~^(https?)://$authority$~iD", $origin, $o) === 1
        && preg_match("~^$authority$~iD", $host, $h) === 1
        && strtolower($o[1]) === $scheme && strtolower($o[2]) === strtolower($h[1])
        && ($originPort = $port($o, 3)) === $port($h, 2) && $originPort >= 1 && $originPort <= 65535;
}
if (!$allowed) {
    synthAudienceRespond(['ok' => false, 'error' => 'Cross-origin requests are not allowed.'], 403);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method =$_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    synthAudienceRespond(['ok' => false, 'error' => 'Use GET or POST.'], 405);
}
if ($method === 'POST' && strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    synthAudienceRespond(['ok' => false, 'error' => 'Send a JSON request.'], 415);
}
if (empty($_SESSION['audience_control_csrf'])) {
    $_SESSION['audience_control_csrf'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['audience_control_csrf'];
$request = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $request = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true, 2) : null;
    // Closed payload: exactly these four fields in a JSON object; any other or missing field is refused.
    $fields = is_array($request) && !array_is_list($request) ? array_map('strval', array_keys($request)) : [];
    sort($fields, SORT_STRING);
    if ($fields !== ['csrf_token', 'generation', 'intent', 'session_id']) {
        synthAudienceRespond(['ok' => false, 'error' => 'Invalid or oversized audience request.'], 400);
    }
    if (!is_string($request['csrf_token']) || !hash_equals($token, $request['csrf_token'])) {
        synthAudienceRespond(['ok' => false, 'error' => 'Reload the page and try again.'], 403);
    }
    // Session-local write cap, independent of the browser's disabled controls.
    $recent = array_filter($_SESSION['audience_control_requests'] ?? [], static fn($time) => $time > time() - 60);
    if (count($recent) >= 30) {
        header('Retry-After: 60');
        synthAudienceRespond(['ok' => false, 'error' => 'Too many requests. Try again in a minute.'], 429);
    }
    $recent[] = time();
    $_SESSION['audience_control_requests'] = array_values($recent);
}
session_write_close();

$sessionId = $request['session_id'] ?? null;
$generation = $request['generation'] ?? null;
$intent = $request['intent'] ?? null;
if ($method === 'POST' && (!is_string($sessionId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $sessionId) !== 1
    || !is_int($generation) || $generation < 1 || !in_array($intent, ['normal', 'everyone'], true))) {
    synthAudienceRespond(['ok' => false, 'error' => 'Invalid audience request.'], 422);
}

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'bootstrap.php';
require_once $enginePath . 'lib/synth_audience_control.php';
try {
    // Explicit configuration, so a connection failure throws into the JSON handler below instead of exiting with
    // plaintext. No schema updates or settings loads; a missing migration is reported below.
    $db = \Synth\Bootstrap::boot(rtrim($enginePath, DIRECTORY_SEPARATOR))->db;
    if ($method === 'GET') {
        // Context for an explicit choice only: no session is preselected, and POST re-checks the exact generation.
        [$mode, $rows] = $db->withManagementTransaction(static function($connection) {
            synth_audience_lock_gate($connection, false);
            $mode = pg_fetch_assoc(synth_audience_query($connection,
                "SELECT value FROM synth.conf_opts WHERE id='synth_mode'", []));
            $rows = pg_fetch_all(synth_audience_query($connection,
                "SELECT session_id, active_generation, runtime_variant, playthrough_id, client_version,
                        audience_intent, audience_reset_epoch
                   FROM synth_meta.runtime_sessions
                  WHERE state='active' AND capabilities ? 'dialogue.everyone'
                  ORDER BY session_id LIMIT 17", []));
            return [$mode ? $mode['value'] : 'STANDARD', $rows];
        });
        $sessions = array_map(static fn(array $row) => [
            'session_id' => $row['session_id'], 'active_generation' => intval($row['active_generation']),
            'runtime_variant' => $row['runtime_variant'], 'playthrough_id' => $row['playthrough_id'],
            'client_version' => $row['client_version'], 'audience_intent' => $row['audience_intent'],
            'audience_reset_epoch' => intval($row['audience_reset_epoch']),
        ], array_slice($rows, 0, 16));
        synthAudienceRespond(['ok' => true, 'csrf_token' => $token,
            'mode' => synth_control_valid_change('mode', $mode) ? $mode : null,
            'everyone_allowed' => in_array($mode, SYNTH_AUDIENCE_MODES, true),
            'sessions' => $sessions, 'truncated' => count($rows) > 16]);
    }
    // Lock order: shared audience gate, then the session advisory lock, then the module's row lock.
    $saved = $db->withManagementTransaction(static function($connection) use ($sessionId, $generation, $intent) {
        synth_audience_lock_gate($connection, false);
        synth_audience_query($connection, 'SELECT pg_advisory_xact_lock(hashtext($1))', [$sessionId]);
        return synth_audience_set($connection, $sessionId, $generation, $intent);
    });
    synthAudienceRespond(['ok' => true, 'server_saved' => true, 'session_id' => $sessionId,
        'generation' => $generation, 'audience' => $saved,
        'message' => 'Saved on the server. Press SYNTH Control in game to refresh.']);
} catch (DomainException $error) {
    [$status, $message] = match ($error->getMessage()) {
        'runtime_not_initialized' => [409, 'That game session is no longer active. Reload the session list.'],
        'stale_generation' => [409, 'That game session has restarted. Reload the session list.'],
        'audience_mode_incompatible' => [409, 'The current mode does not allow Everyone. Switch to Standard or Shout first.'],
        'audience_capability_required' => [422, 'This game session does not support Everyone.'],
        default => [422, 'Invalid audience request.'],
    };
    Logger::info('Audience control refused' . Logger::formatContext(['reason' => $error->getMessage(),
        'session_id' => $sessionId, 'generation' => $generation, 'intent' => $intent]));
    synthAudienceRespond(['ok' => false, 'error' => $message], $status);
} catch (Throwable $error) {
    // Module codes and wrapper messages carry no SQL, parameters or credentials.
    $missing = $error->getMessage() === 'audience_migration_missing';
    Logger::error('Audience control failed' . Logger::formatContext(['method' => $method,
        'error' => get_class($error), 'reason' => $error->getMessage(), 'session_id' => $sessionId]));
    synthAudienceRespond(['ok' => false, 'error' => $missing
        ? 'Audience control is not available until the server database is updated.'
        : 'Could not update the audience. Check the server log and try again.'], $missing ? 503 : 500);
}
