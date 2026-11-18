<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__);

if ($path === '/' || $path === '') {
    header('Location: ui/core/config_hub.php');
    return true;
}

// The version one API (api/index.php) answers every /api/v1 path that is not a real script, as the .htaccess rewrite does.
if (preg_match('#^/api/v1(?:/|$)#', $path) === 1) {
    $scriptFile = realpath(__DIR__ . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($path)));
    if ($scriptFile === false || !is_file($scriptFile)) {
        require __DIR__ . '/api/index.php';
        return true;
    }
}

$file = realpath($root . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($path)));
// Protect private voice snapshots even when the built-in server would otherwise serve static files.
$resolvedPath = str_replace('\\', '/', $file !== false ? $file : rawurldecode($path));
if (preg_match('~/data/tmp/(?:voice_uploads|npc_tts_jobs)(?:/|$)~i', $resolvedPath)) {
    http_response_code(403);
    return true;
}
if ($file !== false && str_starts_with($file, $root) && is_file($file)) {
    return false;
}

return false;
