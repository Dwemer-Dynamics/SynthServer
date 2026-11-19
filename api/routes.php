<?php

declare(strict_types=1);

/**
 * Picks the handler of a version one API route; included by `api/index.php`.
 *
 * Expects `$route` (the path after `/api/v1/`) and `$method`. Every row maps a route to the allowed methods
 * and a handler script under `api/v1/<area>/`. The handler validates and adapts; it never reimplements the
 * logic of the legacy entry point it calls. A known route with another method answers 405, an unknown one 404.
 * `edit` answers 501 until the generic edit API lands; `extensions` routes itself in `v1/extensions.php`.
 */

/** @var array<string, array{0: list<string>, 1: string}> $synthApiRoutes */
$synthApiRoutes = [
    'health' => [['GET', 'HEAD'], 'health.php'],
    'health/live' => [['GET', 'HEAD'], 'health.php'],
    'health/db' => [['GET', 'HEAD'], 'health.php'],
    'health/api-keys-configured' => [['GET', 'HEAD'], 'health.php'],
    'health/npcs-available' => [['GET', 'HEAD'], 'health.php'],
    'health/llm' => [['GET', 'HEAD'], 'health.php'],
    'context/event' => [['POST'], 'context/event.php'],
    'dialogue/request' => [['POST'], 'dialogue/request.php'],
    'dialogue/spoken' => [['POST'], 'dialogue/spoken.php'],
    'dialogue/cancel' => [['POST'], 'dialogue/cancel.php'],
    'user/input' => [['POST'], 'user/input.php'],
    'save/created' => [['POST'], 'save/created.php'],
    'save/loaded' => [['POST'], 'save/loaded.php'],
    'speech/transcribe' => [['POST'], 'speech/transcribe.php'],
    'vision/describe' => [['POST'], 'vision/describe.php'],
    'plugins/manifest' => [['POST'], 'plugins/manifest.php'],
    'npc/add' => [['POST'], 'npc/add.php'],
    'books/read' => [['POST'], 'books/read.php'],
    'locations/save' => [['POST'], 'locations/save.php'],
    'tts/studio' => [['POST'], 'tts/studio.php'],
    'tts/voice-preview' => [['POST'], 'tts/voice-preview.php'],
    'tts/voice-sample' => [['POST'], 'tts/voice-sample.php'],
    'tts/voice-samples' => [['POST'], 'tts/voice-samples.php'],
];

if (preg_match('#^messages/([^/]+)$#', $route, $synthApiMessage) === 1) {
    $messageIdText = $synthApiMessage[1];
    $synthApiRoutes[$route] = [['GET', 'HEAD'], 'messages.php'];
}

if ($route === 'edit' || str_starts_with($route, 'edit/')) {
    require __DIR__ . '/v1/editing/index.php';
    return;
}

if ($route === 'extensions' || str_starts_with($route, 'extensions/')) {
    require __DIR__ . '/v1/extensions.php';
    return;
}

// The dashboard's voice routes are scripts of their own that answer their own methods and guard (they are
// management routes, not game endpoints); they run behind the body limits set in `index.php`.
if ($route === 'voice/preview.php' || $route === 'voice/samples.php') {
    require __DIR__ . '/v1/' . $route;
    return;
}

if (!isset($synthApiRoutes[$route])) {
    synthApiRefuse(404, 'Route not found');
    return;
}

[$synthApiMethods, $synthApiHandler] = $synthApiRoutes[$route];
if (!in_array($method, $synthApiMethods, true)) {
    header('Allow: ' . implode(', ', $synthApiMethods));
    synthApiRefuse(405, 'Method not allowed');
    return;
}

require_once __DIR__ . '/v1/LegacyEntry.php';
require __DIR__ . '/v1/' . $synthApiHandler;
return;
