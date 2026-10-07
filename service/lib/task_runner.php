<?php

// Bootstrap-free process boundary so worker isolation and failure behavior can be exercised without live tasks.
function executeTaskAsync($taskname, $task): int
{
    if (!function_exists('pcntl_fork')) {
        echo "[SYNC-$taskname] pcntl_fork unavailable; running task inline".PHP_EOL;
        unset($GLOBALS['db']);
        try {
            $task['fn']();
        } catch (Throwable $error) {
            Logger::error("Task failed inline: $taskname");
            echo "[SYNC-$taskname] Task failed".PHP_EOL;
            return -1;
        }
        Logger::info("Completed inline task execution: $taskname");
        echo "[SYNC-$taskname] Task completed".PHP_EOL;
        return 0;
    }

    try {
        // sql stores a static connection: unsetting the wrapper in the child does not isolate its socket.
        sql::disconnectBeforeFork();
    } catch (Throwable $error) {
        Logger::error("Database not safe for task fork: $taskname");
        return -1;
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        Logger::error("Failed to fork process for task: $taskname");
        return -1;
    }
    if ($pid === 0) {
        unset($GLOBALS['db']);
        echo "[CHILD-$taskname] Starting task execution".PHP_EOL;
        try {
            $task['fn']();
        } catch (Throwable $error) {
            // Provider bodies and credentials must not be echoed by the process supervisor.
            Logger::error("Task failed in child process: $taskname");
            echo "[CHILD-$taskname] Task failed".PHP_EOL;
            exit(1);
        }
        Logger::info("Completed task execution in child process: $taskname");
        echo "[CHILD-$taskname] Task completed".PHP_EOL;
        exit(0);
    }
    Logger::info("Forked task $taskname with PID: $pid");
    echo "[PARENT] Forked task $taskname with PID: $pid".PHP_EOL;
    return $pid;
}
