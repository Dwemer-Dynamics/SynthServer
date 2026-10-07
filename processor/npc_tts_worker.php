<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

$jobPath = strval($argv[1] ?? "");
if ($jobPath === "" || !is_file($jobPath)) {
    exit(1);
}

$job = json_decode((string)@file_get_contents($jobPath), true);
if (!is_array($job)) {
    exit(1);
}

$root = dirname(__DIR__);
$path = $root . DIRECTORY_SEPARATOR;
require_once $path . 'lib/synth_protocol_state.php';
// Native five-provider jobs must be admitted and leased in PostgreSQL. Never
// resurrect an old file envelope after cutover or delete its recovery evidence.
$owner = $job['protocol_event'] ?? [];
$driver = $job['tts_plan']['inputs']['TTSFUNCTION'] ?? $job['tts_function'] ?? '';
if (is_array($owner) && synth_protocol_owns_dialogue_turn($owner)
    && in_array($driver, ['xtts-fastapi', 'chatterbox', 'pockettts', 'cartesia', 'inworld'], true)) {
    error_log('[SYNTH TTS] Legacy native speech job retained; durable admission required.');
    exit(0);
}
// Only attempt envelopes in this server's job directory are disposable. Old job
// files are retained for explicit recovery; never unlink a path from their payload.
$ownedAttempt = realpath(dirname($jobPath)) === realpath($root . '/data/tmp/npc_tts_jobs')
    && !is_link($jobPath)
    && preg_match('/^[a-f0-9]{32}(?:-[a-f0-9]{24})?-attempt-[a-f0-9]{32}\.json$/D', basename($jobPath)) === 1;

$speaker = trim(strval($job["speaker"] ?? ""));
$text = trim(strval($job["text"] ?? ""));
$mood = trim(strval($job["mood"] ?? "default"));
$cacheSeed = trim(strval($job["cache_seed"] ?? ($job["cache_key"] ?? "")));
$cacheKey = trim(strval($job["cache_key"] ?? ""));

if ($speaker === "" || $text === "" || $cacheSeed === "") {
    if ($ownedAttempt) @unlink($jobPath);
    exit(0);
}

$GLOBALS["ENGINE_PATH"] = $path;
$GLOBALS["SYNTH_RESPONSE_FORMAT"] = "json";
$GLOBALS["SYNTH_RESPONSE_STREAMING"] = false;
$GLOBALS["SYNTH_GAME_ID"] = "fo4";
$GLOBALS["SYNTH_WORLD_NAME"] = "Commonwealth";
$GLOBALS["SYNTH_NAME"] = $speaker;
$GLOBALS["TTS_FFMPEG_FILTERS"] = [];
$GLOBALS["AUDIT_RUNID_REQUEST"] = "npc_tts_worker";
$GLOBALS["gameRequest"] = [
    "npc_tts_worker",
    strval(time()),
    strval(time()),
    $speaker . ": " . $text,
];

require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "response.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "synth_protocol_state.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "synth_tts.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "npc_tts_status.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_master.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "llm_connector.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "core_profiles.class.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "synth_tts_plan.php");

if (synth_npc_tts_status_key($cacheKey) === "") {
    $cacheKey = md5($cacheSeed);
}

$cacheLock = null;
try {
    if (class_exists("Logger")) {
        $requestId = trim(strval($job["request_id"] ?? ""));
        if ($requestId !== "") {
            Logger::setRequestId($requestId);
        } else {
            Logger::bootstrapRequestId("npc_tts_worker");
        }
    }

    synthRuntimeBootstrap($path, [
        "load_general_settings" => true,
        "load_stt_connector" => false,
        "load_tts_connector" => true,
        "load_player_name" => true,
        "load_narrator" => false,
        "run_db_updates" => false,
    ]);
    if (is_array($job['protocol_event'] ?? null)) {
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $job['protocol_event']];
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
        synth_protocol_assert_request_active();
    }

    $ttsPlan = $job['tts_plan'] ?? null;
    if ($ttsPlan !== null) {
        if (!is_array($ttsPlan) || empty($ttsPlan['portable'])) throw new RuntimeException('Nonportable queued speech plan');
        synth_validate_tts_plan($ttsPlan, $cacheSeed, $speaker, $root);
        if (!hash_equals(md5($cacheSeed), $cacheKey)) throw new RuntimeException('Queued speech cache identity mismatch');
        if (($ttsPlan['text'] ?? null) !== $text || ($ttsPlan['mood'] ?? null) !== $mood) {
            throw new RuntimeException('Queued speech content does not match its plan');
        }
    }
    if ($ttsPlan === null && !empty($job["connector_data"]) && is_array($job["connector_data"])) {
        $connector = new LLMConnector();
        $connector->setOldGlobals($job["connector_data"]);
    }
    if ($ttsPlan === null && !empty($job["profile_data"]) && is_array($job["profile_data"])) {
        $profile = new CoreProfile();
        $profile->setOldGlobals($job["profile_data"]);
    }
    if ($ttsPlan === null && !empty($job["npc_data"]) && is_array($job["npc_data"])) {
        $npcMaster = new NpcMaster();
        $GLOBALS["SYNTH_CORE_CURRENT_NPC_DATA"] = $job["npc_data"];
        $GLOBALS["SYNTH_NAME"] = trim(strval($job["npc_data"]["npc_name"] ?? $speaker));
        if ($GLOBALS["SYNTH_NAME"] === "") {
            $GLOBALS["SYNTH_NAME"] = $speaker;
        }
        $npcMaster->setOldGlobalsFromCurrentNpcData($job["npc_data"], true);
    }
    if (trim(strval($job["tts_function"] ?? "")) !== "") {
        $GLOBALS["TTSFUNCTION"] = trim(strval($job["tts_function"]));
    }

    $correlation = [
        'request_id' => $job['request_id'] ?? '',
        'turn_id' => $job['turn_id'] ?? '',
        'utterance_id' => $job['utterance_id'] ?? '',
        'generation' => $job['generation'] ?? null,
        'speaker_identity_id' => $job['speaker_identity_id'] ?? null,
    ];
    $statusMetadata = array_merge($correlation, [
        'speaker_identity' => $job['speaker_identity'] ?? null,
        'speaker' => $GLOBALS['SYNTH_NAME'] ?? $speaker,
    ]);

    $cachePath = $path . "soundcache" . DIRECTORY_SEPARATOR . $cacheKey . ".wav";
    // Per-turn jobs can share media: serialize generation/publication without holding a DB lock.
    $cacheLock = @fopen($path . 'data/tmp/npc_tts_jobs/' . $cacheKey . '.media-lock', PHP_OS_FAMILY==='Windows'?'c':'ce');
    if ($cacheLock === false) throw new RuntimeException('Unable to lock shared speech cache');
    $lockDeadline = hrtime(true) + 60_000_000_000;
    while (!flock($cacheLock, LOCK_EX | LOCK_NB)) {
        synth_protocol_assert_request_active();
        if (hrtime(true) >= $lockDeadline) throw new RuntimeException('Shared speech cache lock timed out');
        usleep(50000);
    }
    synth_protocol_assert_request_active();
    if (is_file($cachePath) && filesize($cachePath) > 44) {
        if (synth_register_npc_tts_media($cacheKey, $GLOBALS['db'] ?? null, $correlation, $root, $statusMetadata) !== null) {
            Logger::debug("[TTS] NPC deferred worker found existing cache" . Logger::formatContext([
                "speaker" => $GLOBALS["SYNTH_NAME"] ?? $speaker,
                "cache_key" => $cacheKey,
            ]));
        } else {
            synth_write_npc_tts_status($cacheKey, "failed", array_merge($statusMetadata, [
                'reason' => 'media_publication_failed',
            ]), $root);
        }
    } else {
        $phaseName = "npc_tts_worker:" . substr(md5($speaker . "|" . $cacheKey), 0, 8);
        Logger::phaseStart($phaseName, [
            "speaker" => $GLOBALS["SYNTH_NAME"] ?? $speaker,
            "connector" => $GLOBALS["TTSFUNCTION"] ?? "",
            "cache_key" => $cacheKey,
            "chars" => strlen($text),
        ]);

        $ttsOutput = synth_call_planned_npc_tts($ttsPlan, $text, $ttsPlan !== null ? $mood : ($mood !== '' ? $mood : 'default'), $cacheSeed);

        synth_protocol_assert_request_active();
        $cacheReady = is_file($cachePath) && @filesize($cachePath) > 44;
        $published = $ttsOutput && $cacheReady
            ? synth_register_npc_tts_media($cacheKey, $GLOBALS['db'] ?? null, $correlation, $root, array_merge($statusMetadata,['output'=>$ttsOutput]))
            : null;
        if ($published !== null) {
            Logger::phaseEnd($phaseName, [
                "status" => "ok",
                "speaker" => $GLOBALS["SYNTH_NAME"] ?? $speaker,
                "output" => $ttsOutput,
            ], "info");
        } else {
            synth_write_npc_tts_status($cacheKey, "failed", array_merge($statusMetadata, [
                "reason" => $ttsOutput
                    ? ($cacheReady ? "media_publication_failed" : "expected_wav_missing")
                    : "generation_failed",
            ]), $root);
            Logger::phaseEnd($phaseName, [
                "status" => "failed",
                "speaker" => $GLOBALS["SYNTH_NAME"] ?? $speaker,
                "cache_key" => $cacheKey,
            ], "warn");
        }
    }
} catch (SYNTHProtocolCancelled $e) {
    // The media cache may be shared by a newer request; do not replace its status with failure.
    Logger::info('[TTS] Discarded deferred work for a cancelled or superseded turn');
} catch (Throwable $e) {
    if (function_exists("synth_write_npc_tts_status") && $cacheKey !== "") {
        synth_write_npc_tts_status($cacheKey, "failed", [
            "request_id" => $job['request_id'] ?? '',
            "turn_id" => $job['turn_id'] ?? '',
            "utterance_id" => $job['utterance_id'] ?? '',
            "generation" => $job['generation'] ?? null,
            "speaker_identity_id" => $job['speaker_identity_id'] ?? null,
            "speaker_identity" => $job['speaker_identity'] ?? null,
            "speaker" => $speaker,
            "reason" => "worker_exception",
            "error" => $e->getMessage(),
        ], $root);
    }
    if (class_exists("Logger")) {
        Logger::error("[TTS] NPC deferred worker failed: " . $e->getMessage());
    }
} finally {
    if (is_resource($cacheLock)) {
        flock($cacheLock, LOCK_UN);
        fclose($cacheLock);
    }
    if ($ownedAttempt) @unlink($jobPath);
}

?>
