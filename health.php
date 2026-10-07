<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
    'background_processor' => false,
    'background_port' => 12348,
    'timestamp' => gmdate('c'),
];

try {
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'synth_runtime.php';
    require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'background_processor.php';

    synthRuntimeBootstrap($root, [
        'run_db_updates' => false,
        'load_general_settings' => false,
        'load_stt_connector' => false,
        'load_tts_connector' => false,
        'load_player_name' => false,
        'load_narrator' => false,
    ]);

    $db = $GLOBALS['db'] ?? null;
    $response['database'] = is_object($db) && (bool)$db->query('SELECT 1');
    $response['database_encoding'] = $response['database'] ? synthRuntimeDatabaseEncoding() : '';
    $response['database_encoding_supported'] = $response['database'] && synthRuntimeDatabaseEncodingIsSupported();
    $response['schema'] = $response['database']
        && $response['database_encoding_supported']
        && !synthRuntimeNeedsDbUpdates();
    $response['background_port'] = synthBackgroundProcessorPort();
    $response['background_processor'] = synthBackgroundProcessorIsRunning(0.2);
    $response['status'] = $response['schema'] ? 'ok' : 'degraded';
    if ($response['database'] && !$response['database_encoding_supported']) {
        $response['error'] = synthRuntimeDatabaseEncodingError();
    }
} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

$healthy = $response['database'] && $response['schema'];
http_response_code($healthy ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
