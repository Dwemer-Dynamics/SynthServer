<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
\Synth\Bootstrap::set('ENGINE_PATH', $path);
\Synth\Bootstrap::set('SYNTH_RESPONSE_FORMAT', "json");
\Synth\Bootstrap::set('SYNTH_RESPONSE_STREAMING', false);
\Synth\Bootstrap::set('SYNTH_GAME_ID', "fo4");
\Synth\Bootstrap::set('SYNTH_WORLD_NAME', "Commonwealth");
\Synth\Bootstrap::set('SYNTH_NAME', "Player");
\Synth\Bootstrap::set('TTS_FFMPEG_FILTERS', []);
\Synth\Bootstrap::set('AUDIT_RUNID_REQUEST', "player_menu_tts_play");

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "request.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "response.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "synth_tts.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");

function synthGeneratePlayerTtsCacheNow(string $line, string $cachePath): void
{
    if ($line === "" || (is_file($cachePath) && filesize($cachePath) > 44)) {
        return;
    }

    $oldGameRequest = \Synth\Bootstrap::gameRequest() ?? null;
    $hadGameRequest = array_key_exists("gameRequest", $GLOBALS);
    $oldWriteOutput = \Synth\Bootstrap::config("PLAYER_TTS_WRITE_OUTPUT") ?? null;
    $hadWriteOutput = array_key_exists("PLAYER_TTS_WRITE_OUTPUT", $GLOBALS);
    $oldAvoidCache = \Synth\Bootstrap::config("AVOID_TTS_CACHE") ?? null;
    $hadAvoidCache = array_key_exists("AVOID_TTS_CACHE", $GLOBALS);
    $oldExpectedCachePath = \Synth\Bootstrap::config("PLAYER_TTS_EXPECTED_CACHE_PATH") ?? null;
    $hadExpectedCachePath = array_key_exists("PLAYER_TTS_EXPECTED_CACHE_PATH", $GLOBALS);

    $playerPrefix = trim((string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player"));
    if ($playerPrefix === "") {
        $playerPrefix = "Player";
    }

    $playerTtsRequest = [
        "player_menu_tts_play",
        strval(time()),
        strval(time()),
        $playerPrefix . ": " . $line,
    ];

    try {
        $gameRequest = &$playerTtsRequest;
        \Synth\Bootstrap::bind('gameRequest', $playerTtsRequest);
        \Synth\Bootstrap::set('PLAYER_TTS_WRITE_OUTPUT', false);
        \Synth\Bootstrap::set('AVOID_TTS_CACHE', false);
        \Synth\Bootstrap::set('PLAYER_TTS_EXPECTED_CACHE_PATH', $cachePath);
        require(\Synth\Bootstrap::enginePath() . "processor" . DIRECTORY_SEPARATOR . "player_tts.php");

        if (!is_file($cachePath) || filesize($cachePath) <= 44) {
            Logger::warn("[Player TTS] Inline generation did not create expected cache" . Logger::formatContext([
                "cache_path" => $cachePath,
            ]));
        }
    } finally {
        if ($hadGameRequest) {
            \Synth\Bootstrap::set('gameRequest', $oldGameRequest);
        } else {
            \Synth\Bootstrap::forget('gameRequest');
        }
        if ($hadWriteOutput) {
            \Synth\Bootstrap::set('PLAYER_TTS_WRITE_OUTPUT', $oldWriteOutput);
        } else {
            \Synth\Bootstrap::forget('PLAYER_TTS_WRITE_OUTPUT');
        }
        if ($hadAvoidCache) {
            \Synth\Bootstrap::set('AVOID_TTS_CACHE', $oldAvoidCache);
        } else {
            \Synth\Bootstrap::forget('AVOID_TTS_CACHE');
        }
        if ($hadExpectedCachePath) {
            \Synth\Bootstrap::set('PLAYER_TTS_EXPECTED_CACHE_PATH', $oldExpectedCachePath);
        } else {
            \Synth\Bootstrap::forget('PLAYER_TTS_EXPECTED_CACHE_PATH');
        }
    }
}

function synthSpawnPlayerTtsWorker(string $line, string $cachePath): void
{
    $jobsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "tmp" . DIRECTORY_SEPARATOR . "player_tts_jobs";
    if (!is_dir($jobsDir)) {
        @mkdir($jobsDir, 0775, true);
    }

    $jobId = md5($cachePath);
    $jobPath = $jobsDir . DIRECTORY_SEPARATOR . $jobId . ".json";
    $lockPath = $jobsDir . DIRECTORY_SEPARATOR . $jobId . ".lock";

    if (file_exists($lockPath) && (time() - filemtime($lockPath)) < 30) {
        return;
    }

    if (@file_put_contents($lockPath, strval(time()), LOCK_EX) === false) {
        Logger::warn("[Player TTS] Could not write worker lock" . Logger::formatContext([
            "lock_path" => $lockPath,
        ]));
        return;
    }
    if (@file_put_contents($jobPath, json_encode([
        "line" => $line,
        "player_name" => \Synth\Bootstrap::config("PLAYER_NAME") ?? "Player",
        "cache_path" => $cachePath,
        "lock_path" => $lockPath,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        @unlink($lockPath);
        Logger::warn("[Player TTS] Could not write worker job" . Logger::formatContext([
            "job_path" => $jobPath,
        ]));
        return;
    }

    $php = PHP_BINARY;
    $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . "processor" . DIRECTORY_SEPARATOR . "player_tts_worker.php";
    if (!is_file($worker)) {
        @unlink($lockPath);
        return;
    }

    $command = 'cmd /C start "" /B "' . str_replace('"', '\"', $php) . '" "' . str_replace('"', '\"', $worker) . '" "' . str_replace('"', '\"', $jobPath) . '" >NUL 2>NUL';
    @pclose(@popen($command, "r"));
}

synthRuntimeBootstrap($path, [
    "load_general_settings" => true,
    "load_stt_connector" => false,
    "load_tts_connector" => true,
    "load_player_name" => true,
    "load_narrator" => false,
    "run_db_updates" => false,
]);

$event = synth_decode_event_from_request();
$payload = strval($event["payload"] ?? "");

\Synth\Bootstrap::set('SYNTH_REQUEST_EVENT', $event);
$gameRequest = [
    "player_menu_tts_play",
    strval($event["ts"] ?? time()),
    strval($event["gamets"] ?? time()),
    $payload,
];
\Synth\Bootstrap::bind('gameRequest', $gameRequest);

synth_start_json_response_buffer();

try {
    synthMaybeSyncPlayerNameFromGamePayload($payload);

    $playerMenuLine = extractPlayerMenuDialogueLine($payload);
    if ($playerMenuLine !== "") {
        $cachePath = playerMenuTtsCachePath($playerMenuLine);
        $cacheExists = file_exists($cachePath) && filesize($cachePath) > 44;

        if (!$cacheExists) {
            synthGeneratePlayerTtsCacheNow($playerMenuLine, $cachePath);
            $cacheExists = file_exists($cachePath) && filesize($cachePath) > 44;
        }

        if ($cacheExists) {
            emitPlayerMenuSpeechLine($playerMenuLine);
        } else {
            Logger::info("[Player TTS] No playable WAV available; returning text-only player subtitle.");
            emitPlayerMenuTextOnlyLine($playerMenuLine);
        }
    }
} catch (Throwable $e) {
    Logger::error("[Player TTS] direct player_tts_play failed: " . $e->getMessage());
}

synth_emit_buffered_json_response();

?>
