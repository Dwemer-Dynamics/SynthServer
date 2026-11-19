<?php

declare(strict_types=1);

/**
 * Entry point of the version one API (`/api/v1/<route>`), the chevo-shaped surface beside the legacy root
 * scripts. It resolves the route and method, then includes `routes.php`, which picks an `api/v1/<area>/`
 * handler. Handlers are thin adapters that call the existing implementation; the root entry points
 * (`main.php`, `gamedata.php`, `stt.php`, ...) keep working unchanged and answer the same way.
 *
 * Served by Apache through the rewrite in `.htaccess`, by `router.php` for the development server, or
 * directly as `/api/index.php/v1/<route>`. Everything here runs in global scope on purpose: the legacy
 * scripts the handlers include read runtime globals.
 */

/**
 * Sends a JSON answer in the same envelope the legacy entry points use for refusals.
 *
 * @param array<string, mixed> $body
 */
function synthApiSend(int $status, array $body): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

/** Sends a generic refusal; the text never carries internal detail. */
function synthApiRefuse(int $status, string $error, string $code = ''): void
{
    $body = ['schema' => 'synth.error.response.v1', 'ok' => false, 'error' => $error];
    if ($code !== '') {
        $body['code'] = $code;
    }
    synthApiSend($status, $body);
}

$synthApiPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$synthApiPath = is_string($synthApiPath) ? $synthApiPath : '/';
if (preg_match('#/api/(?:index\.php/)?v1(?:/(.*))?$#', $synthApiPath, $synthApiMatch) !== 1) {
    synthApiRefuse(404, 'Route not found');
    return;
}
$route = trim((string) ($synthApiMatch[1] ?? ''), '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
unset($synthApiPath, $synthApiMatch);

// A body is bounded before any handler reads it: a missing Content-Length answers 411 and one above the route's
// limit 413. A multipart recording is not readable from php://input, so its declared length is the guard; JSON
// routes also read through Synth\Lib\RequestBody, which never pulls more than the limit from the connection.
require_once dirname(__DIR__) . '/lib/RequestBody.php';
$synthApiBodyLimits = [
    'speech/transcribe' => 16777216 + 65536, // a 16 MiB recording plus multipart framing
    'vision/describe' => 1048576,            // a visual-context event; the image itself goes to media.php
    'voice/preview.php' => 4096,
    'voice/samples.php' => 4096,
];
if ($method === 'POST' && isset($synthApiBodyLimits[$route])) {
    $synthApiBodyStatus = \Synth\Lib\RequestBody::refusalStatus($_SERVER, $synthApiBodyLimits[$route]);
    if ($synthApiBodyStatus !== null) {
        synthApiRefuse(
            $synthApiBodyStatus,
            $synthApiBodyStatus === 411 ? 'Content-Length is required' : 'The request body is too large',
            $synthApiBodyStatus === 411 ? 'length_required' : 'too_large'
        );
        return;
    }
    \Synth\Lib\RequestBody::limit($synthApiBodyLimits[$route]);
}

require __DIR__ . '/routes.php';
return;
