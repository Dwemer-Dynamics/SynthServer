<?php

/**
 * The front controller: every web request that `.htaccess` (or `router.php`) does not allow lands here.
 *
 * The bare root sends the browser to the dashboard; any other path answers 404 so an unlisted script,
 * directory or data file cannot be probed. `/api/v1` is answered by `api/index.php` when a server without
 * the rewrite rules hands it over.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

$synthRequestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$synthRequestPath = is_string($synthRequestPath) ? rawurldecode($synthRequestPath) : '/';

if (preg_match('#/api/v1(?:/|$)#', $synthRequestPath) === 1) {
    require __DIR__ . '/api/index.php';
    return;
}

// The application may live below a folder such as /Synthserver/, so its base is the folder of this script
// (the development router names it through $synthBasePath instead).
$synthBasePath = (string) ($synthBasePath
    ?? rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/'));
$synthBasePath = rtrim($synthBasePath, '/');

if (in_array($synthRequestPath, [$synthBasePath, $synthBasePath . '/', $synthBasePath . '/index.php'], true)
    || ($synthBasePath === '' && $synthRequestPath === '/')) {
    header('Cache-Control: no-store');
    header('Location: ' . $synthBasePath . '/ui/', true, 302);
    return;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'Not found.';
