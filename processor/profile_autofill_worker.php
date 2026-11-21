<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$jobPath = strval($argv[1] ?? '');
if ($jobPath === '' || !is_file($jobPath)) {
    exit(1);
}

$job = json_decode((string)@file_get_contents($jobPath), true);
if (!is_array($job)) {
    exit(1);
}

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
$lockPath = trim(strval($job['lock_path'] ?? ''));
$npcName = trim(strval($job['name'] ?? ''));

if ($npcName === '') {
    if ($lockPath !== '') {
        @unlink($lockPath);
    }
    exit(0);
}

\Synth\Bootstrap::set('ENGINE_PATH', $path);
\Synth\Bootstrap::set('SYNTH_RESPONSE_FORMAT', 'json');
\Synth\Bootstrap::set('SYNTH_RESPONSE_STREAMING', false);
\Synth\Bootstrap::set('SYNTH_GAME_ID', 'fo4');
\Synth\Bootstrap::set('SYNTH_WORLD_NAME', 'Commonwealth');
\Synth\Bootstrap::set('SYNTH_NAME', $npcName);
\Synth\Bootstrap::set('AUDIT_RUNID_REQUEST', 'profile_autofill_worker');
\Synth\Bootstrap::set('gameRequest', [
    'profile_autofill_worker',
    strval(time()),
    strval(time()),
    $npcName,
]);

require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'logger.php');

try {
    if (class_exists('Logger')) {
        $requestId = trim(strval($job['request_id'] ?? ''));
        if ($requestId !== '') {
            Logger::setRequestId($requestId);
        } else {
            Logger::bootstrapRequestId('profile_autofill_worker');
        }
    }

    synthRuntimeBootstrap($path, [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_player_name' => true,
        'load_narrator' => false,
        'run_db_updates' => false,
    ]);

    require_once($path . 'ui' . DIRECTORY_SEPARATOR . 'cmd' . DIRECTORY_SEPARATOR . 'ai_profile_generation_service.php');

    $selectedEventsProvided = array_key_exists('selected_events', $job);
    $selectedEvents = $selectedEventsProvided ? ($job['selected_events'] ?? []) : [];
    if ($selectedEventsProvided && !is_array($selectedEvents)) {
        $selectedEvents = [];
    }

    Logger::phaseStart('profile_autofill_worker', [
        'npc' => $npcName,
        'events' => is_array($selectedEvents) ? count($selectedEvents) : 0,
        'selected_events_provided' => $selectedEventsProvided ? 1 : 0,
    ]);

    $generateOptions = [
        'db' => \Synth\Bootstrap::db(),
        'name' => $npcName,
        'event_limit' => intval($job['event_limit'] ?? 20),
        'source' => 'auto',
    ];
    if ($selectedEventsProvided) {
        $generateOptions['selected_events'] = $selectedEvents;
        $generateOptions['selected_events_provided'] = true;
    }

    $result = aiProfileGenerate($generateOptions);

    Logger::phaseEnd('profile_autofill_worker', [
        'npc' => $npcName,
        'status' => !empty($result['done']) ? 'ok' : 'failed',
        'fields' => count($result['profile_fields'] ?? []),
        'error' => $result['error'] ?? '',
    ], !empty($result['done']) ? 'info' : 'warn');
} catch (Throwable $e) {
    if (class_exists('Logger')) {
        Logger::error('[PROFILE_AUTOFILL] Worker failed: ' . $e->getMessage());
    }
} finally {
    if ($lockPath !== '') {
        @unlink($lockPath);
    }
}

?>
