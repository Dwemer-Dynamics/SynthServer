<?php

// Moved from lib/chat_helper_functions.php: configured TTS calls with fallback, voice settings save/restore and deferred TTS workers
// The original file requires this one, so every caller is unchanged.

function callConfiguredTts($textString, $mood, $stringforhash)
{
    $ttsFunction = strval(\Synth\Bootstrap::config("TTSFUNCTION") ?? '');
    if ($ttsFunction === '') {
        return false;
    }

    if (strcasecmp($ttsFunction, 'none') === 0) {
        return false;
    }

    $ttsFile = dirname(__DIR__, 3).'/lib' . "/../tts/tts-" . $ttsFunction . ".php";
    if (file_exists($ttsFile)) {
        require_once($ttsFile);
    }
    // A provider can also be registered before this call (the test fixture driver); otherwise nothing loaded it.
    // require_once does not reassign TTS_IN_USE when switching A -> B -> A in one process.
    $driver = \Synth\Bootstrap::config('SYNTH_TTS_DRIVERS')[$ttsFunction] ?? null;
    if (!is_callable($driver)) {
        return false;
    }
    \Synth\Bootstrap::set('TTS_IN_USE', $driver);
    return $driver($textString, $mood, $stringforhash);
}

function synthTtsCacheKeyForLine(string $speaker, string $text): string
{
    // Main-pipeline callers must not silently share a text-only key across different voices.
    require_once dirname(__DIR__, 3).'/lib'.'/synth_tts.php';
    return synth_tts_cache_seed(dirname(__DIR__, 3), $speaker, $text);
}

// Resolve the active NPC attributes used by scoped pronunciation rules.
function synthTtsPronunciationCurrentSpeakerScope(?array $npcData = null): array
{
    $emptyScope = ['knowledge_tags' => [], 'npc_name' => '', 'race' => ''];
    $npcData = $npcData ?? (\Synth\Bootstrap::config('SYNTH_CORE_CURRENT_NPC_DATA') ?? null);
    $speaker = trim(strval(\Synth\Bootstrap::config('SYNTH_NAME') ?? ''));
    $npcName = is_array($npcData) ? trim(strval($npcData['npc_name'] ?? '')) : '';
    if (!is_array($npcData) || $speaker === '' || $npcName === '' || strcasecmp($speaker, $npcName) !== 0
        || synth_protocol_current_speech_role($speaker)!=='npc') {
        return $emptyScope;
    }

    $knowledgeTags = preg_split(
        '/[,|\\s]+/u',
        strtolower(strval($npcData['worldknowledge_tags'] ?? '')),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    return [
        'knowledge_tags' => is_array($knowledgeTags) ? array_values(array_unique($knowledgeTags)) : [],
        'npc_name' => $npcName,
        'race' => trim(strval($npcData['race'] ?? '')),
    ];
}

function canRetryNpcTtsWithFallback(): bool
{
    $currentNpcData = \Synth\Bootstrap::config("SYNTH_CORE_CURRENT_NPC_DATA") ?? null;
    $currentName = trim(strval(\Synth\Bootstrap::config("SYNTH_NAME") ?? ''));
    $originalVoice = trim(strval(\Synth\Bootstrap::config("TTS_NPC_ORIGINAL_VOICE") ?? ''));
    $fallbackVoice = trim(strval(\Synth\Bootstrap::config("TTS_NPC_FALLBACK_VOICE") ?? ''));

    if (!is_array($currentNpcData) || $currentName === '' || $originalVoice === '' || $fallbackVoice === '') {
        return false;
    }
    if (synth_protocol_current_speech_role($currentName)!=='npc') {
        return false;
    }
    if (strcasecmp(trim(strval($currentNpcData['npc_name'] ?? '')), $currentName) !== 0) {
        return false;
    }

    return strcasecmp($originalVoice, $fallbackVoice) !== 0;
}

function ensureNpcTtsVoiceResolvedForCurrentSpeaker(): void
{
    if (\Synth\Bootstrap::has('SYNTH_ACTIVE_TTS_PLAN')) return;
    $currentNpcData = \Synth\Bootstrap::config("SYNTH_CORE_CURRENT_NPC_DATA") ?? null;
    $currentName = trim(strval(\Synth\Bootstrap::config("SYNTH_NAME") ?? ''));
    if (!is_array($currentNpcData) || $currentName === '' || synth_protocol_current_speech_role($currentName)!=='npc') {
        return;
    }
    if (strcasecmp(trim(strval($currentNpcData['npc_name'] ?? '')), $currentName) !== 0) {
        return;
    }

    $voiceKey = md5($currentName . '|' . trim(strval($currentNpcData['voiceid'] ?? '')) . '|' . trim(strval(\Synth\Bootstrap::config("TTSFUNCTION") ?? '')));
    if ((\Synth\Bootstrap::config('SYNTH_NPC_TTS_VOICE_RESOLVED_FOR') ?? '') === $voiceKey) {
        return;
    }

    if (!class_exists('NpcMaster')) {
        require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_master.class.php");
    }

    Logger::phaseStart("npc_tts_voice_lazy_resolve", [
        "npc" => $currentName,
        "voiceid" => $currentNpcData['voiceid'] ?? '',
        "connector" => \Synth\Bootstrap::config("TTSFUNCTION") ?? "",
    ]);

    $npcMaster = new NpcMaster();
    $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData, true);
    \Synth\Bootstrap::set('SYNTH_NPC_TTS_VOICE_RESOLVED_FOR', $voiceKey);

    Logger::phaseEnd("npc_tts_voice_lazy_resolve", [
        "npc" => $currentName,
        "voice" => \Synth\Bootstrap::config("TTS_NPC_RESOLVED_VOICE") ?? "",
        "sample" => !empty(\Synth\Bootstrap::config('TTS_NPC_RESOLVED_VOICE_SAMPLE') ?? '') ? "yes" : "no",
    ], "info");
}

function callNpcTtsWithFallback($textString, $mood, $stringforhash)
{
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    ensureNpcTtsVoiceResolvedForCurrentSpeaker();
    $ttsOutput = callConfiguredTts($textString, $mood, $stringforhash);
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    if ($ttsOutput) {
        return $ttsOutput;
    }

    if (!canRetryNpcTtsWithFallback()) {
        return $ttsOutput;
    }

    $fallbackVoice = trim(strval(\Synth\Bootstrap::config("TTS_NPC_FALLBACK_VOICE") ?? ''));
    $originalVoice = \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE") ?? null;
    if ($fallbackVoice === '') {
        return $ttsOutput;
    }

    Logger::warn(("[TTS FALLBACK] Retrying NPC TTS for " . \Synth\Bootstrap::config('SYNTH_NAME') . " with fallback voice '{$fallbackVoice}' after initial synthesis failure."));

    \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE', $fallbackVoice);
    $retryOutput = callConfiguredTts($textString, $mood, $stringforhash);
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();

    if ($retryOutput) {
        \Synth\Bootstrap::set('TTS_NPC_RESOLVED_VOICE', $fallbackVoice);
        return $retryOutput;
    }

    if ($originalVoice === null || trim(strval($originalVoice)) === '') {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE');
    } else {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE', $originalVoice);
    }

    return $ttsOutput;
}

// Keep configured fallback callbacks inside the same frozen settings as the primary attempt.
function synth_call_planned_npc_tts(?array $plan, string $text, string $mood, string $seed)
{
    $generate = static function () use ($text, $mood, $seed) {
        $output = callNpcTtsWithFallback($text, $mood, $seed);
        if (!$output && \Synth\Bootstrap::has('TTS_FALLBACK_FNCT')) {
            if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
            $output = \Synth\Bootstrap::config('TTS_FALLBACK_FNCT')($text, $mood, $seed);
        }
        return $output;
    };
    if ($plan === null) return $generate();
    if (($plan['text'] ?? null) !== trim($text) || ($plan['mood'] ?? null) !== $mood) {
        throw new RuntimeException('Queued speech content does not match its plan');
    }
    require_once dirname(__DIR__, 3).'/lib' . '/synth_tts_plan.php';
    return synth_with_tts_plan($plan, $seed, $plan['speaker'], dirname(__DIR__, 3), $generate);
}

/**
 * Save current TTS voice settings
 * @return array Current TTS settings
 */
function saveCurrentVoiceSettings() {
    return [
        'tts' => \Synth\Bootstrap::has('TTS') ? \Synth\Bootstrap::config('TTS') : [],
        'has_tts_function' => array_key_exists('TTSFUNCTION', $GLOBALS),
        'tts_function' => \Synth\Bootstrap::config('TTSFUNCTION') ?? null,
        'has_tts_function_alias' => array_key_exists('TTS_FUNCTION', $GLOBALS),
        'tts_function_alias' => \Synth\Bootstrap::config('TTS_FUNCTION') ?? null,
        'has_current_tts_connector_id' => array_key_exists('SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID', $GLOBALS),
        'current_tts_connector_id' => \Synth\Bootstrap::config('SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID') ?? null,
        'has_patch_override_voice' => array_key_exists('PATCH_OVERRIDE_VOICE', $GLOBALS),
        'patch_override_voice' => \Synth\Bootstrap::config('PATCH_OVERRIDE_VOICE') ?? null,
        'has_patch_override_voice_id' => array_key_exists('PATCH_OVERRIDE_VOICE_ID', $GLOBALS),
        'patch_override_voice_id' => \Synth\Bootstrap::config('PATCH_OVERRIDE_VOICE_ID') ?? null,
        'has_patch_override_tts_language' => array_key_exists('PATCH_OVERRIDE_TTS_LANGUAGE', $GLOBALS),
        'patch_override_tts_language' => \Synth\Bootstrap::config('PATCH_OVERRIDE_TTS_LANGUAGE') ?? null,
        'has_patch_override_tts_options' => array_key_exists('PATCH_OVERRIDE_TTS_OPTIONS', $GLOBALS),
        'patch_override_tts_options' => \Synth\Bootstrap::config('PATCH_OVERRIDE_TTS_OPTIONS') ?? null,
    ];
}

function applyVoiceIdToTtsGlobals(string $voiceid): void
{
    require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "voice_clone_resolver.php");

    $voiceReference = synth_resolve_tts_voice_reference($voiceid, [
        'actor_name' => \Synth\Bootstrap::config("SYNTH_NAME") ?? '',
    ]);
    \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE', $voiceReference);
    \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE_ID');

    \Synth\Bootstrap::setPath('TTS', ['XTTSFASTAPI', 'voiceid'], $voiceReference);
    \Synth\Bootstrap::setPath('TTS', ['CHATTERBOX', 'voiceid'], $voiceReference);
    \Synth\Bootstrap::setPath('TTS', ['POCKETTTS', 'voiceid'], $voiceReference);
    \Synth\Bootstrap::setPath('TTS', ['OMNIVOICE', 'voiceid'], $voiceReference);
    \Synth\Bootstrap::setPath('TTS', ['PIPERTTS', 'voiceid'], $voiceid);
    \Synth\Bootstrap::setPath('TTS', ['ELEVEN_LABS', 'voice_id'], $voiceid);
    \Synth\Bootstrap::setPath('TTS', ['KOKORO', 'voiceid'], $voiceid);
    \Synth\Bootstrap::setPath('TTS', ['CARTESIA', 'voiceid'], $voiceid);
    \Synth\Bootstrap::setPath('TTS', ['INWORLD', 'voiceid'], $voiceid);
}

/**
 * Load The Narrator's voice settings into GLOBALS
 */
function loadNarratorVoiceSettings() {
    require_once(dirname(__DIR__, 3).'/lib' . "/core/narrator.class.php");
    require_once(dirname(__DIR__, 3).'/lib' . "/core/core_profiles.class.php");
    require_once(dirname(__DIR__, 3).'/lib' . "/core/tts_connector.class.php");

    $narrator = new Narrator();
    $profileId = $narrator->getProfileId();
    if ($profileId) {
        $profileManager = new CoreProfile();
        $profileData = $profileManager->getById($profileId);
        if (is_array($profileData) && !empty($profileData)) {
            $ttsConnectorId = intval($profileData['tts_connector_id'] ?? 0);
            if ($ttsConnectorId > 0) {
                $ttsConnector = new TTSConnector();
                $connectorData = $ttsConnector->getById($ttsConnectorId);
                if (is_array($connectorData) && !empty($connectorData)) {
                    $ttsConnector->setOldGlobals($connectorData);
                }
            }
        }
    }

    \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_LANGUAGE');
    \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_OPTIONS');

    $voiceid = $narrator->get('voiceid');

    if (!$voiceid) {
        $voiceid = 'TheNarrator'; // Fallback default
    }

    applyVoiceIdToTtsGlobals($voiceid);
}

/**
 * Restore previously saved voice settings
 * @param array $savedSettings The settings to restore
 */
function restoreVoiceSettings($savedSettings) {
    if (!is_array($savedSettings)) {
        return;
    }

    \Synth\Bootstrap::set('TTS', $savedSettings['tts'] ?? []);

    if (!empty($savedSettings['has_tts_function'])) {
        \Synth\Bootstrap::set('TTSFUNCTION', $savedSettings['tts_function']);
    } else {
        \Synth\Bootstrap::forget('TTSFUNCTION');
    }

    if (!empty($savedSettings['has_tts_function_alias'])) {
        \Synth\Bootstrap::set('TTS_FUNCTION', $savedSettings['tts_function_alias']);
    } else {
        \Synth\Bootstrap::forget('TTS_FUNCTION');
    }

    if (!empty($savedSettings['has_current_tts_connector_id'])) {
        \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID', $savedSettings['current_tts_connector_id']);
    } else {
        \Synth\Bootstrap::forget('SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID');
    }

    if (!empty($savedSettings['has_patch_override_voice'])) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE', $savedSettings['patch_override_voice']);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE');
    }

    if (!empty($savedSettings['has_patch_override_voice_id'])) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE_ID', $savedSettings['patch_override_voice_id']);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE_ID');
    }

    if (!empty($savedSettings['has_patch_override_tts_language'])) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_TTS_LANGUAGE', $savedSettings['patch_override_tts_language']);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_LANGUAGE');
    }

    if (!empty($savedSettings['has_patch_override_tts_options'])) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_TTS_OPTIONS', $savedSettings['patch_override_tts_options']);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_OPTIONS');
    }
}

function synth_generate_deferred_tts(): void
{
    if (empty(\Synth\Bootstrap::config('SYNTH_DEFERRED_TTS')) || !is_array(\Synth\Bootstrap::config("SYNTH_DEFERRED_TTS"))) {
        return;
    }

    $pending = \Synth\Bootstrap::config("SYNTH_DEFERRED_TTS");
    \Synth\Bootstrap::set('SYNTH_DEFERRED_TTS', []);

    foreach ($pending as $entry) {
        if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
        $text = trim((string)($entry["text"] ?? ""));
        $cacheSeed = trim((string)($entry["cache_seed"] ?? ($entry["cache_key"] ?? "")));
        $cacheKey = trim((string)($entry["cache_key"] ?? ""));
        if (synth_npc_tts_status_key($cacheKey) === "") {
            $cacheKey = md5($cacheSeed);
        }
        $speaker = trim((string)($entry["speaker"] ?? (\Synth\Bootstrap::config("SYNTH_NAME") ?? "")));
        if ($text === "" || $cacheSeed === "" || $cacheKey === "") {
            continue;
        }

        $phaseName = "npc_tts_deferred:" . substr(md5($speaker . "|" . $cacheKey), 0, 8);
        Logger::phaseStart($phaseName, [
            "speaker" => $speaker,
            "connector" => $entry["tts_function"] ?? (\Synth\Bootstrap::config("TTSFUNCTION") ?? ""),
            "cache_key" => $cacheKey,
            "chars" => strlen($text),
        ]);
        $ttsOutput = synth_call_planned_npc_tts($entry['tts_plan'] ?? null, $text, (string)($entry['mood'] ?? 'default'), $cacheSeed);

        if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
        $cachePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . $cacheKey . ".wav";
        if ($ttsOutput && is_file($cachePath) && @filesize($cachePath) > 44) {
            $correlation = [
                'request_id' => $entry['request_id'] ?? '',
                'turn_id' => $entry['turn_id'] ?? '',
                'utterance_id' => $entry['utterance_id'] ?? '',
                'generation' => $entry['generation'] ?? null,
                'speaker_identity_id' => $entry['speaker_identity_id'] ?? null,
            ];
            $published = synth_register_npc_tts_media($cacheKey, \Synth\Bootstrap::db() ?? null, $correlation, null, [
                'speaker_identity'=>$entry['speaker_identity'] ?? null,'speaker'=>$speaker,'output'=>$ttsOutput,
            ]);
            if ($published !== null) {
                \Synth\Bootstrap::appendPath('TRACK', ["FILES_GENERATED"], $ttsOutput);
                Logger::info("[TTS] Generated deferred JSON-mode TTS for {$speaker}: {$ttsOutput}");
                Logger::phaseEnd($phaseName, [
                    "status" => "ok",
                    "speaker" => $speaker,
                    "output" => $ttsOutput,
                ], "info");
                continue;
            }
        }
        synth_write_npc_tts_status($cacheKey, "failed", [
            'request_id' => $entry['request_id'] ?? '',
            'turn_id' => $entry['turn_id'] ?? '',
            'utterance_id' => $entry['utterance_id'] ?? '',
            'generation' => $entry['generation'] ?? null,
            'speaker_identity_id' => $entry['speaker_identity_id'] ?? null,
            "speaker_identity" => $entry['speaker_identity'] ?? null,
            "speaker" => $speaker,
            "reason" => $ttsOutput ? "media_publication_failed" : "generation_failed",
        ]);
        Logger::warn("[TTS] Deferred JSON-mode TTS failed for {$speaker}: {$cacheKey}");
        Logger::phaseEnd($phaseName, [
            "status" => "failed",
            "speaker" => $speaker,
            "cache_key" => $cacheKey,
        ], "warn");
    }
}

// Immediate responses must register deliverable media before advertising it, just like deferred workers.
function synth_finish_immediate_npc_tts(string $cacheSeed, $output, string $speaker, string $utteranceId): bool
{
    $context=synth_protocol_response_context();
    $identity=synth_protocol_response_speaker($speaker);
    $db=\Synth\Bootstrap::db() ?? null;
    $correlation=[
        'request_id'=>$context['request_id'] ?? '', 'turn_id'=>$context['turn_id'] ?? '',
        'generation'=>$context['generation'] ?? null, 'utterance_id'=>$utteranceId,
        'speaker_identity_id'=>is_array($identity) && !isset($identity['kind']) && $db
            ? synth_protocol_actor_identity_id($db,$identity) : null,
    ];
    $metadata=['speaker'=>$speaker,'speaker_identity'=>$identity,'output'=>$output];
    if ($output && synth_register_npc_tts_media(md5($cacheSeed),$db,$correlation,null,$metadata)!==null) return true;
    synth_write_npc_tts_status(md5($cacheSeed),'failed',array_merge($correlation,$metadata,['reason'=>'generation_or_publication_failed']));
    return false;
}

function synth_npc_tts_php_binary(): string
{
    $knownCliCandidates = DIRECTORY_SEPARATOR === '\\'
        ? [
            defined('PHP_BINDIR') ? rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php.exe' : '',
            defined('PHP_BINDIR') ? rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php' : '',
            'php',
        ]
        : [
            '/usr/bin/php',
            '/usr/bin/php8.2',
            '/usr/local/bin/php',
            defined('PHP_BINDIR') ? rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php' : '',
            'php',
        ];

    $candidates = PHP_SAPI === 'cli'
        ? array_merge([defined('PHP_BINARY') ? (string)PHP_BINARY : ''], $knownCliCandidates)
        : $knownCliCandidates;

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if ($candidate === 'php' || is_file($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function synth_spawn_deferred_npc_tts_worker(array $entry): bool
{
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    // Extension closures stay in their originating PHP process, with the same captured settings.
    if (isset($entry['tts_plan']) && empty($entry['tts_plan']['portable'])) return false;
    $root = dirname(__DIR__, 3);
    $worker = $root . DIRECTORY_SEPARATOR . 'processor' . DIRECTORY_SEPARATOR . 'npc_tts_worker.php';
    if (!is_file($worker)) {
        Logger::warn('[TTS] NPC deferred worker missing' . Logger::formatContext([
            'worker' => $worker,
        ]));
        return false;
    }

    $text = trim((string)($entry['text'] ?? ''));
    $cacheSeed = trim((string)($entry['cache_seed'] ?? ($entry['cache_key'] ?? '')));
    $cacheKey = trim((string)($entry['cache_key'] ?? ''));
    if (synth_npc_tts_status_key($cacheKey) === '') {
        $cacheKey = md5($cacheSeed);
    }
    if ($text === '' || $cacheSeed === '' || $cacheKey === '') {
        return false;
    }

    $owner=\Synth\Bootstrap::requestEvent()['json'] ?? [];
    if (!empty(\Synth\Bootstrap::claimedRequest()) && synth_protocol_owns_dialogue_turn($owner)
        && in_array($entry['tts_plan']['inputs']['TTSFUNCTION'] ?? '',['xtts-fastapi','chatterbox','pockettts','cartesia','inworld'],true)) {
        require_once dirname(__DIR__, 3).'/lib'.'/synth_tts_worker.php';
        $entry['cache_seed']=$cacheSeed;$entry['cache_key']=$cacheKey;
        $id=synth_tts_enqueue_job(\Synth\Bootstrap::db(),$entry,$root);
        synth_tts_launch_job($id,$root);
        return true;
    }

    $jobsDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'npc_tts_jobs';
    if (!is_dir($jobsDir)) {
        @mkdir($jobsDir, 0775, true);
    }

    $jobId = $cacheKey;
    if (!empty(\Synth\Bootstrap::claimedRequest())) {
        $entry['protocol_event'] = \Synth\Bootstrap::requestEvent()['json'];
        $owner = $entry['protocol_event'];
        $jobId .= '-' . substr(hash('sha256', $owner['session_id'] . '|' . $owner['generation']
            . '|' . $owner['turn_id']), 0, 24);
    }
    // Each dispatch owns an immutable envelope. The worker's OS-held cache lock, not
    // a timestamp file, serializes synthesis and is released automatically on death.
    $jobPath = $jobsDir . DIRECTORY_SEPARATOR . $jobId . '-attempt-' . bin2hex(random_bytes(16)) . '.json';
    unset($entry['lock_path']);
    $entry['cache_seed'] = $cacheSeed;
    $entry['cache_key'] = $cacheKey;
    $entry['engine_root'] = $root;
    if (trim(strval($entry['request_id'] ?? '')) === '') {
        $entry['request_id'] = class_exists('Logger') ? Logger::getRequestId() : (\Synth\Bootstrap::config('SYNTH_REQUEST_ID') ?? '');
    }
    $entry['turn_id'] = trim(strval($entry['turn_id'] ?? (\Synth\Bootstrap::config('SYNTH_TURN_ID') ?? '')));
    $entry['utterance_id'] = trim(strval($entry['utterance_id'] ?? (\Synth\Bootstrap::config('SCRIPTLINE_UTTERANCE_ID') ?? '')));
    $entry['generation'] = intval($entry['generation'] ?? (\Synth\Bootstrap::config('SYNTH_GENERATION') ?? 0));
    $entry['npc_data'] = \Synth\Bootstrap::config('SYNTH_CORE_CURRENT_NPC_DATA') ?? null;
    $entry['profile_data'] = \Synth\Bootstrap::config('SYNTH_CORE_CURRENT_PROFILE_DATA') ?? null;
    $entry['connector_data'] = \Synth\Bootstrap::config('SYNTH_CORE_CURRENT_CONNECTOR_DATA') ?? null;
    synth_write_npc_tts_status($cacheKey, 'pending', [
        'speaker' => $entry['speaker'] ?? '',
        'request_id' => $entry['request_id'],
        'turn_id' => $entry['turn_id'],
        'utterance_id' => $entry['utterance_id'],
        'generation' => $entry['generation'],
        'speaker_identity_id' => $entry['speaker_identity_id'] ?? null,
        'speaker_identity' => $entry['speaker_identity'] ?? null,
    ], $root);

    $encodedJob = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encodedJob) || @file_put_contents($jobPath, $encodedJob, LOCK_EX) !== strlen($encodedJob)) {
        @unlink($jobPath);
        Logger::warn('[TTS] Could not write NPC deferred worker job' . Logger::formatContext([
            'job_path' => $jobPath,
        ]));
        return false;
    }

    $php = synth_npc_tts_php_binary();
    if (DIRECTORY_SEPARATOR === '\\') {
        $command = 'cmd /C start "" /B "' . str_replace('"', '\"', $php) . '" "' . str_replace('"', '\"', $worker) . '" "' . str_replace('"', '\"', $jobPath) . '" >NUL 2>NUL';
    } else {
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobPath) . ' > /dev/null 2>&1 < /dev/null &';
    }

    $handle = @popen($command, 'r');
    if (is_resource($handle)) {
        @pclose($handle);
        Logger::info('[TTS] Spawned NPC deferred TTS worker' . Logger::formatContext([
            'speaker' => $entry['speaker'] ?? '',
            'cache_key' => $cacheKey,
            'job' => basename($jobPath),
        ]));
        return true;
    }

    @unlink($jobPath);
    Logger::warn('[TTS] Failed to spawn NPC deferred TTS worker' . Logger::formatContext([
        'speaker' => $entry['speaker'] ?? '',
        'cache_key' => $cacheKey,
    ]));
    return false;
}
