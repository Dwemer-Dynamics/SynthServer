<?php

declare(strict_types=1);

// Read-only readiness answer. It loads configuration, connects and inspects. The connect timeout is capped at
// HEALTH_CONNECT_TIMEOUT seconds unless the environment sets SYNTH_DB_CONNECT_TIMEOUT. It never migrates, takes the migration lock, creates schema objects, loads settings or sends a provider request (the llm check only opens a bare TCP connection), and it
// writes no SYNTH log file (warnings go to the web server error log). 200 only when the database, its encoding, the
// runtime schema gate and the migration ledger are all ready; otherwise 503 with one fixed, actionable error. The
// background processor is advisory and never changes the status code.

$root = __DIR__;
$response = [
    'service' => 'SYNTH Server',
    'status' => 'unhealthy',
    'version' => trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . '.version_number.txt')),
    'build' => trim((string)@file_get_contents($root . DIRECTORY_SEPARATOR . '.version.txt')),
    'database' => false,
    'database_encoding' => '',
    'database_encoding_supported' => false,
    'schema' => false,
    // current | missing | pending | unknown | unavailable (see SchemaMigrations::status()).
    'schema_migrations' => 'unavailable',
    'background_processor' => false,
    'background_port' => 12348,
    'timestamp' => gmdate('c'),
];
$answered = false;
const HEALTH_CONNECT_TIMEOUT = 5;

// Configuration output, PHP warnings and a legacy plaintext exit stay in this request's own buffer. A fatal error or
// an exit before the answer still gets the sanitized JSON 503 (shutdown functions run before buffers are flushed).
$bufferLevel = ob_get_level();
ob_start();

$answer = static function (array $response, bool $ready, ?int $code = null) use (&$answered, $bufferLevel): void {
    $answered = true;
    // Only the buffers this script opened (and any opened inside them); stop at one that cannot be removed.
    while (ob_get_level() > $bufferLevel) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (!headers_sent()) {
        http_response_code($code ?? ($ready ? 200 : 503));
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
};

register_shutdown_function(static function () use (&$response, &$answered, $answer): void {
    if ($answered) {
        return;
    }
    $response['error'] = 'The health check stopped before it finished; see the web server error log.';
    $answer($response, false);
});

// Granular checks: health.php?check=live|db|api-keys|npcs|llm|all. Without ?check= the full readiness answer below
// is unchanged. "live" answers before any configuration, database or provider is touched.
if (isset($_GET['check'])) {
    require_once $root . DIRECTORY_SEPARATOR . 'managers' . DIRECTORY_SEPARATOR . 'HealthCheck' . DIRECTORY_SEPARATOR . 'HealthCheckManager.php';
    $check = is_string($_GET['check']) ? $_GET['check'] : '';
    if (!in_array($check, \Synth\Managers\HealthCheck\HealthCheckManager::checkNames(), true)) {
        $answer(['status' => 'error', 'error' => 'Unknown check. Use one of: ' . implode(', ', \Synth\Managers\HealthCheck\HealthCheckManager::checkNames()) . '.'],
            false, 400);
        exit;
    }
    if ($check === 'live') {
        [$code, $body] = \Synth\Managers\HealthCheck\HealthCheckManager::live($response['service'], $response['version']);
        $answer($body, true, $code);
        exit;
    }
    $failure = 'SYNTH could not load its runtime files; redeploy the server.';
    $db = null;
    try {
        require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
        Logger::suspendFileWrites();
        $failure = 'The PHP PostgreSQL extension (pgsql) is not loaded; install it for the PHP that serves SYNTH and restart that PHP.';
        if (!function_exists('pg_connect')) {
            throw new RuntimeException('pgsql');
        }
        $failure = 'SYNTH cannot connect to PostgreSQL. Check that PostgreSQL 17 is running and that the SYNTH_DB_* '
            . 'values in conf/conf.php are correct.';
        $db = synthRuntimeConnectDatabase($root, HEALTH_CONNECT_TIMEOUT);
        $failure = null;
        if (!is_file($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php') && $check !== 'db') {
            $failure = 'SYNTH production dependencies are missing; run composer install --no-dev in the server root.';
            $db = null;
        }
    } catch (Throwable $e) {
        $db = null;
    }
    // api-keys, npcs and llm need the database and the Composer autoloader; the db check needs only the connection.
    if ($check === 'all') {
        [$code, $body] = \Synth\Managers\HealthCheck\HealthCheckManager::all($db, $failure);
    } elseif ($check === 'db') {
        [$code, $body] = \Synth\Managers\HealthCheck\HealthCheckManager::database($db, $failure);
    } elseif ($db === null) {
        $code = 503;
        $body = ['check' => $check, 'status' => 'error', 'error' => $failure ?? 'SYNTH cannot connect to PostgreSQL.'];
    } elseif ($check === 'api-keys') {
        [$code, $body] = \Synth\Managers\HealthCheck\HealthCheckManager::apiKeys();
    } elseif ($check === 'npcs') {
        [$code, $body] = \Synth\Managers\HealthCheck\HealthCheckManager::npcs($db);
    } else {
        [$code, $body] = \Synth\Managers\HealthCheck\HealthCheckManager::llm();
    }
    $answer($body, $code === 200, $code);
    exit;
}

$failure = 'SYNTH could not load its runtime files; redeploy the server.';
try {
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
    Logger::suspendFileWrites();
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'background_processor.php';

    $response['background_port'] = synthBackgroundProcessorPort();
    $response['background_processor'] = synthBackgroundProcessorIsRunning(0.2);

    $failure = 'The PHP PostgreSQL extension (pgsql) is not loaded; install it for the PHP that serves SYNTH and restart that PHP.';
    if (!function_exists('pg_connect')) {
        throw new RuntimeException('pgsql');
    }

    $failure = 'SYNTH cannot connect to PostgreSQL. Check that PostgreSQL 17 is running and that SYNTH_DB_HOST, '
        . 'SYNTH_DB_PORT, SYNTH_DB_NAME, SYNTH_DB_USER and SYNTH_DB_PASSWORD in conf/conf.php are correct.';
    $db = synthRuntimeConnectDatabase($root, HEALTH_CONNECT_TIMEOUT);
    $response['database'] = (bool)$db->query('SELECT 1');
    if (!$response['database']) {
        throw new RuntimeException('database');
    }
    $response['status'] = 'degraded';

    $failure = 'SYNTH could not inspect its database; see the web server error log.';
    $encoding = synthRuntimeDatabaseEncoding();
    $response['database_encoding'] = $encoding;
    $response['database_encoding_supported'] = synthRuntimeDatabaseEncodingIsSupported($encoding);
    if (!$response['database_encoding_supported']) {
        $response['error'] = synthRuntimeDatabaseEncodingError($encoding);
        $answer($response, false);
        exit;
    }

    $runtimeGateMet = !synthRuntimeNeedsDbUpdates();

    $failure = 'SYNTH production dependencies are missing; run composer install --no-dev in the server root.';
    if (!is_file($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
        throw new RuntimeException('vendor');
    }
    $failure = 'SYNTH could not read its schema migration ledger; see the web server error log.';
    $response['schema_migrations'] = strval(synthRuntimeSchemaMigrationStatus($root)['state'] ?? 'unavailable');

    $response['schema'] = $runtimeGateMet && $response['schema_migrations'] === 'current';
    if ($response['schema']) {
        $response['status'] = 'ok';
    } elseif ($response['schema_migrations'] === 'unknown') {
        $response['error'] = 'The database records schema migrations this SYNTH version does not contain. Deploy the '
            . 'SYNTH server version that last migrated this database and check health again before changing it.';
    } elseif ($response['schema_migrations'] === 'current') {
        $response['error'] = 'The SYNTH database records every schema migration but its schema is incomplete. Check '
            . 'that SYNTH_DB_NAME in conf/conf.php names the SYNTH database and that this is the SYNTH version that '
            . 'migrated it; see docs/SCHEMA-MIGRATIONS.md.';
    } else {
        $response['error'] = 'The SYNTH database schema is not current. Run php tools/bootstrap-database.php in the '
            . 'server root (or tools/create-synth-db-wsl.ps1) to apply the migrations.';
    }
} catch (Throwable $e) {
    // Driver and configuration messages can carry connection details; the client gets the fixed message only.
    $response['error'] = $failure;
}

$answer($response, $response['schema']);
