<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

$jobPath = strval($argv[1] ?? "");
if ($jobPath === "" || !is_file($jobPath)) {
    exit(1);
}

$jobJson = (string)@file_get_contents($jobPath);
$jobJson = preg_replace('/^\xEF\xBB\xBF/', '', $jobJson);
$job = json_decode(is_string($jobJson) ? $jobJson : '', true);
if (!is_array($job)) {
    exit(1);
}

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
$line = trim(strval($job["line"] ?? ""));
$cachePath = trim(strval($job["cache_path"] ?? ""));
$lockPath = trim(strval($job["lock_path"] ?? ""));

if ($line === "") {
    if ($lockPath !== "") {
        @unlink($lockPath);
    }
    exit(0);
}

\Synth\Bootstrap::set('ENGINE_PATH', $path);
\Synth\Bootstrap::set('SYNTH_RESPONSE_FORMAT', "json");
\Synth\Bootstrap::set('SYNTH_RESPONSE_STREAMING', false);
\Synth\Bootstrap::set('SYNTH_GAME_ID', "fo4");
\Synth\Bootstrap::set('SYNTH_WORLD_NAME', "Commonwealth");
\Synth\Bootstrap::set('SYNTH_NAME', "Player");
\Synth\Bootstrap::set('TTS_FFMPEG_FILTERS', []);
\Synth\Bootstrap::set('AUDIT_RUNID_REQUEST', "player_menu_tts_worker");

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "response.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "synth_tts.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");

try {
    synthRuntimeBootstrap($path, [
        "load_general_settings" => true,
        "load_stt_connector" => false,
        "load_tts_connector" => true,
        "load_player_name" => true,
        "load_narrator" => false,
        "run_db_updates" => false,
    ]);

    $playerPrefix = trim((string)($job["player_name"] ?? (\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player")));
    if ($playerPrefix === "") {
        $playerPrefix = "Player";
    }

    $gameRequest = [
        "player_menu_tts_play",
        strval(time()),
        strval(time()),
        $playerPrefix . ": " . $line,
    ];
    \Synth\Bootstrap::bind('gameRequest', $gameRequest);
    \Synth\Bootstrap::set('PLAYER_TTS_WRITE_OUTPUT', false);
    \Synth\Bootstrap::set('AVOID_TTS_CACHE', false);
    if ($cachePath !== "") {
        \Synth\Bootstrap::set('PLAYER_TTS_EXPECTED_CACHE_PATH', $cachePath);
    }

    require($path . "processor" . DIRECTORY_SEPARATOR . "player_tts.php");
} catch (Throwable $e) {
    if (class_exists("Logger")) {
        Logger::error("[Player TTS] worker failed: " . $e->getMessage());
    }
} finally {
    if ($lockPath !== "") {
        @unlink($lockPath);
    }
}

?>
