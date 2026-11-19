<?php

declare(strict_types=1);

/**
 * Serves /api/v1/edit: the generic edit API, in the shape of chevo's `api/v1/edit` (one self-describing surface a UI
 * or an MCP client builds on). This first slice edits config settings; every later entity is one more row of
 * `$synthEditEntities`.
 *
 * Routes (`$route` is `edit` or `edit/...`):
 * - GET   /edit                         describes every entity.
 * - GET   /edit/{entity}/_schema        describes one entity.
 * - GET   /edit/{entity}                lists records; `?scope=general` picks the storage scope.
 * - GET   /edit/{entity}/{key}          reads one record.
 * - PATCH /edit/{entity}/{key}          changes the allowed fields, given as a JSON object.
 *
 * Entity, scope, field and key are all checked against server-side allowlists before anything is read or written: the
 * entity and scope against `$synthEditEntities`, the fields against that entity's writable list, and the key against
 * the declared settings in ConfigManager. The route changes server configuration, so it keeps the dashboard's
 * management guards (allowed host, same-origin). Answers carry a generic message, never exception text.
 */

require_once dirname(__DIR__, 3) . '/lib/management_request_guard.php';
require_once dirname(__DIR__, 3) . '/lib/RequestBody.php';
require_once dirname(__DIR__, 3) . '/lib/runtime_bootstrap.php';
require_once dirname(__DIR__, 3) . '/managers/ConfigManager/ConfigManager.php';

use Synth\Managers\ConfigManager\ConfigManager;

(static function () use ($route, $method): void {
    /**
     * Every entity the API exposes: its scopes (with what each allows), the fields a PATCH may set, and its key.
     * Anything not listed answers 404.
     */
    $synthEditEntities = [
        'config' => [
            'label' => 'Configuration',
            'description' => 'Declared settings. Each has a fixed key; only its value can change.',
            'key' => 'key',
            'scopes' => ['general' => ['list', 'read', 'update']],
            'default_scope' => 'general',
            'writable_fields' => ['value'],
            'readable_fields' => ['key', 'value', 'default', 'type', 'description', 'readonly', 'global_only', 'source'],
        ],
    ];

    // Same browser-origin defence as the dashboard; a read has no effect, so it is judged by host and origin evidence only.
    if (PHP_SAPI !== 'cli' && synthManagementRequestRefusal($_SERVER, 'browser') !== null) {
        synthApiRefuse(403, 'Request not allowed', 'forbidden');
        return;
    }

    $parts = array_map('rawurldecode', array_slice(explode('/', $route), 1));
    $entityName = $parts[0] ?? null;
    $key = $parts[1] ?? null;
    if (count($parts) > 2 || $entityName === '' || $key === '') {
        synthApiRefuse(404, 'Route not found', 'not_found');
        return;
    }

    $allowMethod = static function (array $allowed) use ($method): bool {
        if (in_array($method, $allowed, true)) {
            return true;
        }
        header('Allow: ' . implode(', ', $allowed));
        synthApiRefuse(405, 'Method not allowed');

        return false;
    };

    $describe = static function (string $name) use ($synthEditEntities): array {
        $entity = $synthEditEntities[$name];

        return [
            'name' => $name,
            'label' => $entity['label'],
            'description' => $entity['description'],
            'key' => [$entity['key']],
            'scopes' => $entity['scopes'],
            'default_scope' => $entity['default_scope'],
            'readable_fields' => $entity['readable_fields'],
            'writable_fields' => $entity['writable_fields'],
        ];
    };

    try {
        if ($entityName === null) {
            if (!$allowMethod(['GET', 'HEAD'])) {
                return;
            }
            synthApiSend(200, ['ok' => true, 'entities' => array_map($describe, array_keys($synthEditEntities))]);
            return;
        }
        if (!isset($synthEditEntities[$entityName])) {
            synthApiRefuse(404, 'Unknown entity', 'unknown_entity');
            return;
        }
        $entity = $synthEditEntities[$entityName];

        synthRuntimeBootstrapIfNeeded(dirname(__DIR__, 3), ['run_db_updates' => false]);
        $manager = ConfigManager::shared();

        if ($key === '_schema') {
            if (!$allowMethod(['GET', 'HEAD'])) {
                return;
            }
            synthApiSend(200, ['ok' => true, 'entity' => $describe($entityName) + ['settings' => array_values($manager->definitions())]]);
            return;
        }

        // The scope is an allowlisted query value; any other storage scope is refused, never passed on.
        $scope = $_GET['scope'] ?? $entity['default_scope'];
        if (!is_string($scope) || !isset($entity['scopes'][$scope])) {
            synthApiRefuse(400, 'Unknown scope', 'unknown_scope');
            return;
        }

        $item = static function (string $settingKey) use ($manager): array {
            $definition = $manager->definition($settingKey) ?? [];
            $explained = $manager->explain($settingKey);

            return [
                'key' => $settingKey,
                'value' => $explained['value'],
                'default' => $definition['default'] ?? null,
                'type' => $definition['type'] ?? 'string',
                'description' => $definition['description'] ?? '',
                'readonly' => (bool) ($definition['readonly'] ?? false),
                'global_only' => (bool) ($definition['global_only'] ?? false),
                'source' => $explained['source'],
            ];
        };

        if ($key === null) {
            if (!$allowMethod(['GET', 'HEAD'])) {
                return;
            }
            $items = array_map($item, array_keys($manager->definitions()));
            synthApiSend(200, ['ok' => true, 'entity' => $entityName, 'scope' => $scope, 'items' => $items, 'total' => count($items)]);
            return;
        }

        if ($manager->definition($key) === null) {
            synthApiRefuse(404, 'Unknown setting', 'unknown_key');
            return;
        }

        if (!$allowMethod(['GET', 'HEAD', 'PATCH'])) {
            return;
        }
        if ($method === 'PATCH') {
            if (!in_array('update', $entity['scopes'][$scope], true)) {
                synthApiRefuse(405, 'This scope is read-only', 'read_only');
                return;
            }
            \Synth\Lib\RequestBody::limit(1048576);
            $raw = \Synth\Lib\RequestBody::read();
            if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576 || !str_starts_with(ltrim($raw), '{')) {
                synthApiRefuse(400, 'The request body must be a JSON object of at most 1 MB', 'bad_body');
                return;
            }
            try {
                $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                synthApiRefuse(400, 'The request body must be valid JSON', 'bad_body');
                return;
            }
            if (!is_array($body) || !array_key_exists('value', $body) || array_diff(array_keys($body), $entity['writable_fields']) !== []) {
                synthApiRefuse(400, 'A setting is changed with a JSON object holding only "value"', 'bad_field');
                return;
            }
            try {
                $manager->setEditable($key, $body['value']);
            } catch (\InvalidArgumentException $rejected) {
                // The messages of setEditable() are written for the client: they name the rule, never the data.
                synthApiRefuse(422, $rejected->getMessage(), 'invalid_value');
                return;
            }
        }

        synthApiSend(200, ['ok' => true, 'item' => $item($key)]);
    } catch (\Throwable $error) {
        error_log('[edit] api failed: ' . get_class($error));
        synthApiRefuse(500, 'The edit could not be completed', 'edit_failed');
    }
})();
