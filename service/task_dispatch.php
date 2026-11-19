<?php

// Runs one legacy manager task once, in a fresh process: the entry point of the middleterm,
// dynamicprofile and player2health workers (workers/Legacy/LegacyTaskWorker.php). The task
// body is the one service/manager.php runs; only the fork is replaced by the process boundary.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const SYNTH_TASK_DISPATCH_TASKS = ['dynamicprofile', 'middleterm', 'player2health'];
if ($argc !== 2 || !in_array($argv[1], SYNTH_TASK_DISPATCH_TASKS, true)) {
    fwrite(STDERR, "Usage: php service/task_dispatch.php <" . implode('|', SYNTH_TASK_DISPATCH_TASKS) . ">\n");
    exit(2);
}
$taskname = $argv[1];
@ob_end_clean();
$GLOBALS['ENGINE_ROOT'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
error_reporting(E_ALL);

require_once __DIR__ . '/lib/core_utils.php';
require_once $GLOBALS['ENGINE_ROOT'] . '/lib/runtime_bootstrap.php';
try {
    synthRuntimeBootstrap($GLOBALS['ENGINE_ROOT'], [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_player_name' => true,
        'load_narrator' => true,
    ]);
    $GLOBALS['argv'] = $argv;
    Logger::setCustomLog($GLOBALS['ENGINE_ROOT'] . 'log/manager.log');
    Logger::deleteLogIfTooLarge($GLOBALS['ENGINE_ROOT'] . 'log/manager.log');

    require_once __DIR__ . '/processors/' . $taskname . '/entrypoint.php';
    Logger::info("Starting task execution: $taskname");
    $GLOBALS['TASKS'][$taskname]['fn']();
    Logger::info("Completed task execution: $taskname");
    echo "Legacy task completed.\n";
} catch (Throwable $error) {
    // Provider bodies and credentials must not be echoed by the process supervisor.
    if (class_exists('Logger')) {
        Logger::error("Task failed: $taskname");
    }
    fwrite(STDERR, "Legacy task failed.\n");
    exit(1);
}
