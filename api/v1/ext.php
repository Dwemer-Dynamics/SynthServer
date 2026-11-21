<?php

declare(strict_types=1);

// /api/v1/ext/<id>/<path>: routes an enabled extension declared (provides.routes) and registered. Adapter over
// ExtensionManager::handleRoute.
//   - The id must be an enabled extension in the registry (conf/extensions.json); anything else answers 404 before any
//     extension code is loaded. Ids and path segments are plain names, never paths.
//   - Same browser-origin and Host defence as the dashboard routes (an extension route may change server state).
//   - The JSON body is bounded by api/index.php (64 KiB) and must be an object; the query string is passed as strings.
//   - Answers carry the handler's body, or a generic refusal; exception text never reaches the client.

require_once __DIR__ . '/../../lib/autoload.php';
require_once dirname(__DIR__, 2) . '/managers/ExtensionManager/ExtensionManager.php';
require_once dirname(__DIR__, 2) . '/lib/management_request_guard.php';

use Synth\Managers\ExtensionManager\ExtensionManager;

(static function () use ($route, $method): void {
    if (PHP_SAPI !== 'cli' && synthManagementRequestRefusal($_SERVER, 'browser') !== null) {
        synthApiRefuse(403, 'Request not allowed', 'forbidden');
        return;
    }
    $parts = explode('/', $route, 3);
    $id = $parts[1] ?? '';
    $path = $parts[2] ?? '';
    if (preg_match(ExtensionManager::ID_PATTERN, $id) !== 1 || strlen($path) > 200 || preg_match('#^[A-Za-z0-9_./-]*$#D', $path) !== 1
        || str_contains($path, '..') || str_contains($path, '//')) {
        synthApiRefuse(404, 'Route not found');
        return;
    }
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        header('Allow: GET, HEAD, POST, PUT, PATCH, DELETE');
        synthApiRefuse(405, 'Method not allowed');
        return;
    }

    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $raw = \Synth\Lib\RequestBody::read();
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true, 16);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                synthApiRefuse(400, 'The body must be a JSON object', 'invalid_body');
                return;
            }
            $body = $decoded;
        }
    }
    $query = [];
    foreach ($_GET as $name => $value) {
        if (is_string($name) && is_string($value) && strlen($name) <= 64 && strlen($value) <= 1000 && count($query) < 20) {
            $query[$name] = $value;
        }
    }

    try {
        $manager = new ExtensionManager(dirname(__DIR__, 2));
        $answer = $manager->handleRoute($id, $method === 'HEAD' ? 'GET' : $method, $path, $query, $body);
        if (isset($answer['allow'])) {
            header('Allow: ' . implode(', ', $answer['allow']));
        }
        synthApiSend($answer['status'], $answer['body']);
    } catch (\Throwable $error) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'extensions', 'route failed: ' . get_class($error));
        synthApiRefuse(500, 'Extension request failed', 'extension_request_failed');
    }
})();
