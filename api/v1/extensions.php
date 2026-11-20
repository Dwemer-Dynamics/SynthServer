<?php

declare(strict_types=1);

// /api/v1/extensions: list the installed extensions and switch one on or off. Adapter over ExtensionManager.
//   GET  /api/v1/extensions                 -> {"ok": true, "extensions": [...]}
//   POST /api/v1/extensions/{id}/enable     -> {"ok": true, "extension": {...}}
//   POST /api/v1/extensions/{id}/disable    -> {"ok": true, "extension": {...}}
// The id is taken from the path and must be one the manager knows (an installed folder or an id still listed as
// enabled); anything else answers 404 before state is touched. Answers carry status codes, never exception text.

require_once __DIR__ . '/../../lib/classes/Logger.php';
require_once dirname(__DIR__, 2) . '/managers/ExtensionManager/ExtensionManager.php';
require_once dirname(__DIR__, 2) . '/lib/management_request_guard.php';

use Synth\Managers\ExtensionManager\ExtensionManager;

(static function () use ($route, $method): void {
    // Same browser-origin defence as the dashboard: this route changes server configuration.
    if (PHP_SAPI !== 'cli' && synthManagementRequestRefusal($_SERVER, 'browser') !== null) {
        synthApiRefuse(403, 'Request not allowed', 'forbidden');
        return;
    }

    $parts = explode('/', $route);
    $isList = $route === 'extensions';
    $action = $parts[2] ?? '';
    $isAction = count($parts) === 3 && in_array($action, ['enable', 'disable'], true);
    if (!$isList && !$isAction) {
        synthApiRefuse(404, 'Route not found');
        return;
    }
    $allowed = $isList ? ['GET', 'HEAD'] : ['POST'];
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        synthApiRefuse(405, 'Method not allowed');
        return;
    }

    try {
        $manager = new ExtensionManager(dirname(__DIR__, 2));
        if ($isList) {
            synthApiSend(200, ['ok' => true, 'extensions' => $manager->summary()]);
            return;
        }

        $id = $parts[1];
        if (!in_array($id, $manager->knownIds(), true)) {
            synthApiRefuse(404, 'Extension not found', 'extension_not_found');
            return;
        }
        if ($action === 'enable') {
            try {
                $manager->enable($id);
            } catch (\InvalidArgumentException) {
                synthApiRefuse(422, 'Extension cannot be enabled', 'extension_not_enableable');
                return;
            }
        } else {
            $manager->disable($id);
        }
        $row = array_values(array_filter($manager->summary(), static fn(array $item): bool => $item['id'] === $id))[0]
            ?? ['id' => $id, 'enabled' => false, 'status' => 'disabled'];
        synthApiSend(200, ['ok' => true, 'extension' => $row]);
    } catch (\Throwable $error) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'extensions', 'api failed: ' . get_class($error));
        synthApiRefuse(500, 'Extension request failed', 'extension_request_failed');
    }
})();
