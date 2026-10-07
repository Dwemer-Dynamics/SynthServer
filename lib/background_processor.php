<?php

/**
 * Background processor helpers for Synthserver.
 *
 * Uses a small heartbeat/startup pattern for the SYNTH background service:
 * - Probe heartbeat TCP port.
 * - Start daemon via service/start.sh when needed.
 */

function synthBackgroundProcessorPort(): int
{
    // CHIM owns 12345 and Stobe owns 12346. Keep SYNTH isolated so
    // one server cannot mistake another server's worker for its own.
    // Dialectic owns 12347; SYNTH must not mistake its sibling's listener for its own manager.
    $configuredPort = (int)(getenv('SYNTH_BACKGROUND_PORT') ?: 12348);
    return ($configuredPort >= 1 && $configuredPort <= 65535) ? $configuredPort : 12348;
}

function synthBackgroundProcessorStartScriptPath(): string
{
    $configuredPath = trim((string)getenv('SYNTH_BACKGROUND_START_SCRIPT'));
    if ($configuredPath !== '') {
        return $configuredPath;
    }

    if (isset($GLOBALS['ENGINE_ROOT']) && is_string($GLOBALS['ENGINE_ROOT']) && $GLOBALS['ENGINE_ROOT'] !== '') {
        $engineRoot = rtrim($GLOBALS['ENGINE_ROOT'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    } elseif (defined('BASE_PATH')) {
        $engineRoot = rtrim((string)BASE_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    } else {
        $engineRoot = rtrim(dirname(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    return $engineRoot . 'service' . DIRECTORY_SEPARATOR . 'start.sh';
}

function synthBackgroundProcessorStateDirectory(): string
{
    $configuredPath = trim((string)getenv('SYNTH_BACKGROUND_STATE_DIR'));
    $stateDirectory = $configuredPath !== '' ? $configuredPath : sys_get_temp_dir();
    return rtrim($stateDirectory, DIRECTORY_SEPARATOR);
}

function synthBackgroundProcessorLog(string $level, string $message, array $context = []): void
{
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && $encoded !== '') {
            $message .= ' ' . $encoded;
        }
    }

    if (class_exists('Logger')) {
        $level = strtolower($level);
        if ($level === 'error') {
            Logger::error($message);
            return;
        }
        if ($level === 'warn' || $level === 'warning') {
            Logger::warn($message);
            return;
        }
        Logger::info($message);
        return;
    }

    error_log('[HELPER] ' . $message);
}

function synthBackgroundProcessorIsRunning(float $timeoutSeconds = 0.15): bool
{
    $port = synthBackgroundProcessorPort();
    if ($port < 1 || $port > 65535) {
        return false;
    }

    if ($timeoutSeconds < 0.1) {
        $timeoutSeconds = 0.1;
    } elseif ($timeoutSeconds > 2.0) {
        $timeoutSeconds = 2.0;
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeoutSeconds);
        if (is_resource($socket)) {
            @fclose($socket);
            return true;
        }
        if ($attempt < 2) {
            usleep(40000);
        }
    }

    return false;
}

function synthEnsureBackgroundProcessorRunning(bool $logFailures = true): bool
{
    if (synthBackgroundProcessorIsRunning()) {
        return true;
    }

    $startScript = synthBackgroundProcessorStartScriptPath();
    if (!is_file($startScript)) {
        if ($logFailures) {
            synthBackgroundProcessorLog('warn', 'Background processor start script missing', [
                'path' => $startScript,
            ]);
        }
        return false;
    }

    if (!function_exists('shell_exec')) {
        if ($logFailures) {
            synthBackgroundProcessorLog('warn', 'shell_exec unavailable; cannot auto-start background processor');
        }
        return false;
    }

    $stateDirectory = synthBackgroundProcessorStateDirectory();
    if (!is_dir($stateDirectory) && !@mkdir($stateDirectory, 0775, true) && !is_dir($stateDirectory)) {
        if ($logFailures) {
            synthBackgroundProcessorLog('warn', 'Background processor state directory is unavailable', [
                'path' => $stateDirectory,
            ]);
        }
        return false;
    }

    $lockPath = $stateDirectory . DIRECTORY_SEPARATOR . 'synth_background_processor_start.lock';
    $attemptPath = $stateDirectory . DIRECTORY_SEPARATOR . 'synth_background_processor_start.attempt';
    $lockHandle = @fopen($lockPath, 'c');
    if (!is_resource($lockHandle) || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            @fclose($lockHandle);
        }
        return false;
    }

    try {
        if (synthBackgroundProcessorIsRunning(0.1)) {
            return true;
        }

        $cooldownSeconds = max(5, (int)(getenv('SYNTH_BACKGROUND_START_COOLDOWN_SECONDS') ?: 30));
        $lastAttempt = is_file($attemptPath) ? (int)@filemtime($attemptPath) : 0;
        if ($lastAttempt > 0 && (time() - $lastAttempt) < $cooldownSeconds) {
            return false;
        }

        @touch($attemptPath);
        $command = 'nohup bash ' . escapeshellarg($startScript) . ' > /dev/null 2>&1 < /dev/null &';
        @shell_exec($command);

        // Give the detached service a short bounded window to bind its socket.
        $running = false;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            usleep(100000);
            if (synthBackgroundProcessorIsRunning(0.1)) {
                $running = true;
                break;
            }
        }
        synthBackgroundProcessorLog($running ? 'info' : 'warn', $running
            ? 'Background processor started'
            : 'Background processor start requested; service is not ready yet', [
            'port' => synthBackgroundProcessorPort(),
            'script' => $startScript,
            'cooldown_seconds' => $cooldownSeconds,
        ]);
        return $running;
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
