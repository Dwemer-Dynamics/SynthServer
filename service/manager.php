<?php 

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
@ob_end_clean();

$GLOBALS["ENGINE_ROOT"] = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR;

error_reporting(E_ALL);
echo "[MANAGER] START".PHP_EOL;


require_once("{$GLOBALS["ENGINE_ROOT"]}/service/lib/core_utils.php");
require_once("{$GLOBALS["ENGINE_ROOT"]}/lib/runtime_bootstrap.php");
synthRuntimeBootstrap($GLOBALS["ENGINE_ROOT"], [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
require_once("{$GLOBALS["ENGINE_ROOT"]}/lib/logger.php");

if (isset($argv) && is_array($argv)) {
    $GLOBALS["argv"] = $argv;
}

Logger::setCustomLog($GLOBALS["ENGINE_ROOT"]."log/manager.log");
Logger::deleteLogIfTooLarge($GLOBALS["ENGINE_ROOT"]."log/manager.log");

Logger::info("[SERVICE MANAGER] Run started / ".date("Y-m-d H:i:s"));

requireFilesRecursivelyByPattern($GLOBALS["ENGINE_ROOT"]."/service/processors/", '/^entrypoint\.php$/');

require_once __DIR__.'/lib/task_runner.php';

$managerFailed = false;
if (isset($argv[1])) {
    $taskname=$argv[1];
    Logger::debug("Attempting to run task: $taskname");
    if (isset($GLOBALS["TASKS"][$taskname])) {
        $task=$GLOBALS["TASKS"][$taskname];
        echo "Running task $taskname asynchronously".PHP_EOL;
        Logger::info("Starting async task execution: $taskname");
        $pid = executeTaskAsync($taskname, $task);
        if ($pid > 0) {
            Logger::info("Task forked: $taskname");
            echo "Task forked $taskname ".PHP_EOL;
        } elseif ($pid === 0) {
            Logger::info("Task completed inline: $taskname");
            echo "Task completed inline $taskname ".PHP_EOL;
        } else {
            $managerFailed = true;
            Logger::error("Task failed to execute: $taskname");
        }
    } else {
        $managerFailed = true;
        Logger::error("Task not found: $taskname");
        echo "Task not found $taskname ".PHP_EOL;
    }

} else {
    Logger::debug("No specific task requested, running all tasks asynchronously");
    $child_pids = [];
    foreach ($GLOBALS["TASKS"] as $taskname=>$task)  {
        if (!empty($task["explicit_only"])) {
            Logger::debug("Skipping explicit-only task during scheduled run: $taskname");
            continue;
        }
        echo "[MANAGER] Forking task $taskname ".PHP_EOL;
        Logger::info("Forking task: $taskname");
        $pid = executeTaskAsync($taskname, $task);
        if ($pid > 0) {
            $child_pids[$taskname] = $pid;
        } elseif ($pid < 0) {
            $managerFailed = true;
        }
    }
    
    // Wait for all child processes to complete
    echo "[MANAGER] Waiting for all child processes to complete...".PHP_EOL;
    Logger::info("Waiting for " . count($child_pids) . " child processes");
    foreach ($child_pids as $taskname => $pid) {
        do {
            $waited = pcntl_waitpid($pid, $status);
        } while ($waited === -1 && pcntl_get_last_error() === PCNTL_EINTR);
        if ($waited === $pid && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            Logger::info("Child process $taskname (PID: $pid) exited with status: " . pcntl_wexitstatus($status));
            echo "[MANAGER] Task $taskname completed".PHP_EOL;
        } else {
            $managerFailed = true;
            Logger::error("Child task failed or could not be reaped: $taskname");
            echo "[MANAGER] Task $taskname failed".PHP_EOL;
        }
    }

}
echo "[MANAGER] END".PHP_EOL;
exit($managerFailed ? 1 : 0);
?>
