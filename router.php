<?php

/**
 * Router of the PHP development server (`php -S ... router.php`; see start-dev.ps1).
 *
 * It applies the same boundary as Apache: the allowed paths and the version one API rewrite are read from
 * `.htaccess`, so the two cannot disagree. Returning false lets the built-in server serve the file (run a
 * script, send a static file); every other path ends in `index.php`, which answers 404 or sends the root
 * to the dashboard.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

$synthRoutePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$synthRoutePath = is_string($synthRoutePath) && $synthRoutePath !== '' ? $synthRoutePath : '/';
$synthDecodedPath = rawurldecode($synthRoutePath);

// Protect private voice snapshots even when the built-in server would otherwise serve static files.
if (preg_match('~/data/tmp/(?:voice_uploads|npc_tts_jobs)(?:/|$)~i', str_replace('\\', '/', $synthDecodedPath)) === 1) {
    http_response_code(403);
    return true;
}

// The application may be served below its folder name (the document root is then the folder's parent).
$synthBasePath = '';
foreach (array_unique([basename(__DIR__), 'Synthserver']) as $synthFolder) {
    $synthPrefix = '/' . $synthFolder;
    if (strcasecmp(substr($synthDecodedPath, 0, strlen($synthPrefix)), $synthPrefix) === 0
        && ($synthDecodedPath === $synthPrefix || $synthDecodedPath[strlen($synthPrefix)] === '/')) {
        $synthBasePath = substr($synthRoutePath, 0, strlen($synthPrefix));
        $synthDecodedPath = substr($synthDecodedPath, strlen($synthPrefix));
        break;
    }
}
$synthRelativePath = ltrim($synthDecodedPath, '/');

/**
 * Returns what the first matching `.htaccess` rule does with a path: '-' (serve it), a script to run, or
 * 'index.php' when no rule allows it.
 */
$synthMatchRule = static function (string $relativePath): string {
    $rules = @file(__DIR__ . '/.htaccess', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (is_array($rules) ? $rules : [] as $line) {
        if (preg_match('~^\s*RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?\s*$~', $line, $rule) !== 1) {
            continue;
        }
        $modifiers = str_contains(',' . strtolower($rule[3] ?? '') . ',', ',nc,') ? 'iD' : 'D';
        if (preg_match('~' . str_replace('~', '\~', $rule[1]) . '~' . $modifiers, $relativePath) === 1) {
            return $rule[2];
        }
    }

    return 'index.php';
};

$synthAction = $synthMatchRule($synthRelativePath);

// The dashboard and the data it shows answer a browser on this machine only; game and API paths are open.
$synthDashboardPath = preg_match('~^(?:ui(?:/|$)|soundcache/|data/)~i', $synthRelativePath) === 1;
if ($synthAction === '-' && $synthDashboardPath && PHP_SAPI === 'cli-server') {
    require_once __DIR__ . '/lib/LocalAccess.php';
    if (!\Synth\Lib\LocalAccess::allowsDevelopmentRouter($_SERVER)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The development server answers the dashboard on this machine only.';
        return true;
    }
}

if ($synthAction === '-') {
    return false;
}
if ($synthAction === 'api/index.php') {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
