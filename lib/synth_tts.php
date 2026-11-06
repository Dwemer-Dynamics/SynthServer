<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . "runtime_bootstrap.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "voice_clone_resolver.php";
require_once __DIR__ . DIRECTORY_SEPARATOR . "logger.php";

function synth_tts_cache_seed(string $root, string $speaker, string $text, ?array $canonicalActor = null): string
{
    $speaker = trim($speaker);
    $text = trim($text);
    $ttsFunction = trim(strval($GLOBALS["TTSFUNCTION"] ?? ''));
    $voice = '';

    try {
        if (!isset($GLOBALS["TTSFUNCTION"])) {
            synthRuntimeBootstrapIfNeeded($root, [
                'load_tts_connector' => true,
                'run_db_updates' => false,
                'load_general_settings' => true,
            ]);
            $ttsFunction = trim(strval($GLOBALS["TTSFUNCTION"] ?? ''));
        }

        $currentSpeaker = trim(strval($GLOBALS["SYNTH_NAME"] ?? ''));
        if ($canonicalActor === null && $speaker !== '' && $currentSpeaker !== '' && strcasecmp($speaker, $currentSpeaker) === 0) {
            $voice = trim(strval($GLOBALS["PATCH_OVERRIDE_VOICE"] ?? ''));
            if ($voice === '') {
                $voice = trim(strval($GLOBALS["TTS_NPC_RESOLVED_VOICE_SAMPLE"] ?? ''));
            }
            if ($voice === '') {
                $voice = trim(strval($GLOBALS["TTS_NPC_RESOLVED_VOICE"] ?? ''));
            }
        }

        $db = $GLOBALS["db"] ?? null;
        if ($voice === '' && is_object($db)) {
            $voiceData = synth_tts_load_npc_voice_data($db, $speaker, $canonicalActor);
            $voice = trim(strval($voiceData['voiceid'] ?? ''));
            if ($voice !== '') {
                $streaming = function_exists('synth_response_stream_requested')
                    ? synth_response_stream_requested()
                    : (function_exists('synth_json_streaming_enabled') && synth_json_streaming_enabled());
                if (!$streaming) {
                    $voice = synth_resolve_tts_voice_reference($voice, [
                        'root' => $root,
                        'actor_name' => $canonicalActor===null ? $speaker : '',
                        'voice_name' => $voiceData['voice_name'] ?? '',
                        'voice_formid' => $voiceData['voice_formid'] ?? '',
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log("[SYNTH TTS] Could not resolve cache voice for {$speaker}: " . $e->getMessage());
    }

    $sample = $voice !== '' ? synth_resolve_voice_clone_wav($voice, [
        'root' => $root, 'sync_sample' => false, 'allow_game_scan' => false,
    ]) : '';
    $sampleHash = is_file($sample) ? (hash_file('sha256', $sample) ?: '') : '';
    $actorSeed=$canonicalActor===null ? '' : "\nactor:".hash('sha256',json_encode([
        strtoupper($canonicalActor['form_id'] ?? ''),strtolower($canonicalActor['origin_plugin'] ?? ''),
        $canonicalActor['playthrough_id'] ?? ''],JSON_THROW_ON_ERROR));
    return "synth.tts.v5\n" . $ttsFunction . "\n" . $speaker . "\n" . $voice . "\n" . $sampleHash . "\n" . $text.$actorSeed;
}

function synth_tts_cache_key(string $root, string $speaker, string $text, ?array $canonicalActor = null): string
{
    return md5(synth_tts_cache_seed($root, $speaker, $text, $canonicalActor));
}

function synth_tts_soundcache_path(string $root, string $speaker, string $text): string
{
    return $root . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . synth_tts_cache_key($root, $speaker, $text) . ".wav";
}

function synth_tts_is_temporary_silent_voice(string $voiceId, string $voiceName = ''): bool
{
    if (function_exists('synth_is_temporary_silent_voice')) {
        return synth_is_temporary_silent_voice($voiceId, $voiceName);
    }

    $voiceKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', $voiceId . ' ' . $voiceName));
    if ($voiceKey === '') {
        return false;
    }

    return str_contains($voiceKey, 'nodialogue') ||
        str_contains($voiceKey, 'donotrecord') ||
        str_contains($voiceKey, 'nvdlec01femaleunquenodialogue') ||
        str_contains($voiceKey, 'nvdlc01femaleunquenodialogue');
}

function synth_tts_find_actor_voiceid_from_local_samples(string $speaker): string
{
    $speakerKey = function_exists('synth_voice_clone_normalize_key')
        ? synth_voice_clone_normalize_key($speaker)
        : strtolower(preg_replace('/[^a-z0-9]+/i', '', $speaker));
    if ($speakerKey === '' || strlen($speakerKey) < 4) {
        return '';
    }

    $voiceDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices';
    if (!is_dir($voiceDir)) {
        return '';
    }

    $matches = [];
    foreach (glob($voiceDir . DIRECTORY_SEPARATOR . '*.wav') ?: [] as $path) {
        if (!is_file($path) || @filesize($path) <= 44) {
            continue;
        }
        $voiceId = pathinfo($path, PATHINFO_FILENAME);
        $voiceKey = function_exists('synth_voice_clone_normalize_key')
            ? synth_voice_clone_normalize_key($voiceId)
            : strtolower(preg_replace('/[^a-z0-9]+/i', '', $voiceId));
        if ($voiceKey !== '' && str_contains($voiceKey, $speakerKey)) {
            $matches[$voiceId] = true;
        }
    }

    return count($matches) === 1 ? (string)array_key_first($matches) : '';
}

function synth_tts_load_npc_voice(object $db, string $speaker): string
{
    $voiceData = synth_tts_load_npc_voice_data($db, $speaker);
    return trim(strval($voiceData['voiceid'] ?? ''));
}

function synth_tts_load_npc_voice_data(object $db, string $speaker, ?array $canonicalActor = null): array
{
    if ($speaker === '' || !method_exists($db, 'fetchOne')) {
        return [];
    }

    if ($canonicalActor !== null) {
        if (!method_exists($db,'escapeLiteral') || !is_string($canonicalActor['form_id'] ?? null)
            || preg_match('/^0x[0-9a-f]{8}$/Di',$canonicalActor['form_id'])!==1
            || strtoupper($canonicalActor['form_id'])==='0X00000000'
            || !is_string($canonicalActor['origin_plugin'] ?? null) || strlen($canonicalActor['origin_plugin'])>255
            || str_contains($canonicalActor['origin_plugin'],"\0") || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di',$canonicalActor['origin_plugin'])!==1
            || !is_string($canonicalActor['playthrough_id'] ?? null) || $canonicalActor['playthrough_id']===''
            || strlen($canonicalActor['playthrough_id'])>128 || str_contains($canonicalActor['playthrough_id'],"\0")
            || preg_match('//u',$canonicalActor['playthrough_id'])!==1) return [];
        $form=$db->escapeLiteral($canonicalActor['form_id']);
        $plugin=$db->escapeLiteral($canonicalActor['origin_plugin']);
        $playthrough=$db->escapeLiteral($canonicalActor['playthrough_id']);
        $row=$db->fetchOne("SELECT n.voiceid,n.extended_data FROM synth.core_npc_master n
            JOIN synth.actor_identities a ON a.id=n.actor_identity_id
            WHERE a.game='fo4' AND upper(a.full_form_id)=upper({$form})
                AND lower(a.origin_plugin)=lower({$plugin}) AND a.playthrough_id={$playthrough}");
    } else {
        $escaped = method_exists($db, 'escape') ? $db->escape($speaker) : str_replace("'", "''", $speaker);
        $row = $db->fetchOne("SELECT voiceid, extended_data FROM synth.core_npc_master WHERE npc_name='{$escaped}' LIMIT 1");
    }
    if (!is_array($row)) {
        return [];
    }

    $voiceData = [
        'voiceid' => trim(strval($row['voiceid'] ?? '')),
        'voice_name' => '',
        'voice_formid' => '',
    ];

    $extended = $row['extended_data'] ?? null;
    if (is_string($extended) && trim($extended) !== '') {
        $decoded = json_decode($extended, true);
        $extended = is_array($decoded) ? $decoded : [];
    }

    if (is_array($extended)) {
        $metadata = $extended['voice_metadata'] ?? [];
        if (is_array($metadata)) {
            $voiceData['voice_name'] = trim(strval($metadata['voice_name'] ?? ''));
            $voiceData['voice_formid'] = trim(strval($metadata['voice_formid'] ?? ''));
            if ($voiceData['voiceid'] === '') {
                $voiceData['voiceid'] = trim(strval($metadata['voiceid'] ?? $metadata['voice_id'] ?? ''));
            }
        }
    }

    if (synth_tts_is_temporary_silent_voice($voiceData['voiceid'], $voiceData['voice_name'])) {
        $voiceData['voiceid'] = '';
        $voiceData['voice_name'] = '';
        $voiceData['voice_formid'] = '';
    }

    if ($voiceData['voiceid'] === '' && $canonicalActor === null) {
        $fallbackVoiceId = synth_tts_find_actor_voiceid_from_local_samples($speaker);
        if ($fallbackVoiceId !== '') {
            $voiceData['voiceid'] = $fallbackVoiceId;
        }
    }

    return $voiceData;
}

function synth_tts_generate_for_response(string $root, string $speaker, string $text, ?array $canonicalActor = null): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    $phaseName = "npc_tts_direct:" . substr(md5($speaker . "|" . $text), 0, 8);
    $oldName = $GLOBALS["SYNTH_NAME"] ?? null;
    $oldVoice = $GLOBALS["PATCH_OVERRIDE_VOICE"] ?? null;
    $hadName = array_key_exists("SYNTH_NAME", $GLOBALS);
    $hadVoice = array_key_exists("PATCH_OVERRIDE_VOICE", $GLOBALS);
    $canonicalKeys=['SYNTH_CORE_CURRENT_NPC_DATA','SYNTH_CORE_CURRENT_PROFILE_DATA','SYNTH_SPEECH_ROLE',
        'TTS_NPC_RESOLVED_VOICE','TTS_NPC_RESOLVED_VOICE_SAMPLE','TTS_NPC_ORIGINAL_VOICE','TTS_NPC_FALLBACK_VOICE',
        'PATCH_OVERRIDE_VOICE_ID','SYNTH_ACTIVE_TTS_PLAN','LAST_LLM_RESPONSE','TTS_FALLBACK_FNCT'];
    $canonicalSaved=[];
    if ($canonicalActor!==null)foreach($canonicalKeys as $key) {
        $canonicalSaved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];unset($GLOBALS[$key]);
    }
    Logger::phaseStart($phaseName, [
        "speaker" => $speaker,
        "chars" => strlen($text),
    ]);

    try {
        synthRuntimeBootstrapIfNeeded($root, [
            'load_tts_connector' => true,
            'run_db_updates' => false,
            'load_general_settings' => true,
        ]);

        require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php";

        if (!isset($GLOBALS["TTS_FFMPEG_FILTERS"]) || !is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
            $GLOBALS["TTS_FFMPEG_FILTERS"] = [];
        }

        $GLOBALS["SYNTH_NAME"] = $speaker;
        if ($canonicalActor!==null) $GLOBALS['SYNTH_SPEECH_ROLE']='npc';

        $canonicalVoice=null;
        if ($canonicalActor!==null) {
            $db=$GLOBALS['db']??null;
            $canonicalVoice=is_object($db)?synth_tts_load_npc_voice_data($db,$speaker,$canonicalActor):[];
            if (trim(strval($canonicalVoice['voiceid']??''))==='') return false;
        }

        $cacheSeed = synth_tts_cache_seed($root, $speaker, $text, $canonicalActor);
        $cacheKey = md5($cacheSeed);
        $cachePath = $root . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . $cacheKey . ".wav";
        $cacheReady = is_file($cachePath) && filesize($cachePath) > 44;

        if (!$cacheReady) {
            $db = $GLOBALS["db"] ?? null;
            if (is_object($db)) {
                $voiceData = $canonicalVoice ?? synth_tts_load_npc_voice_data($db, $speaker, $canonicalActor);
                $voice = trim(strval($voiceData['voiceid'] ?? ''));
                if ($voice !== '') {
                    $GLOBALS["PATCH_OVERRIDE_VOICE"] = synth_resolve_tts_voice_reference($voice, [
                        'root' => $root,
                        'actor_name' => $canonicalActor===null ? $speaker : '',
                        'voice_name' => $voiceData['voice_name'] ?? '',
                        'voice_formid' => $voiceData['voice_formid'] ?? '',
                    ]);
                } elseif ($canonicalActor !== null) {
                    unset($GLOBALS['PATCH_OVERRIDE_VOICE']);
                }
            }

            if ($canonicalActor!==null) {
                // The existing immutable plan prevents provider-time name/profile lookups.
                // Only this exact canonical row's voice may cross the generation boundary.
                require_once __DIR__.'/synth_tts_plan.php';
                $plan=synth_capture_tts_plan($root,$speaker,$text,'default');
                $result=synth_with_tts_plan($plan,$plan['cache_seed'],$speaker,$root,
                    static fn()=>callConfiguredTts($text,'default',$cacheSeed));
            } else $result = callConfiguredTts($text, "default", $cacheSeed);
        } else {
            $result = $cachePath;
        }

        $ok = is_file($cachePath) && filesize($cachePath) > 44;
        if (!$ok) {
            Logger::warn("[SYNTH TTS] TTS generation did not create expected WAV" . Logger::formatContext([
                "speaker" => $speaker,
                "result" => strval($result),
                "cache_path" => $cachePath,
            ]));
        }

        Logger::phaseEnd($phaseName, [
            "status" => $ok ? "ok" : "failed",
            "speaker" => $speaker,
            "connector" => $GLOBALS["TTSFUNCTION"] ?? "",
            "cache_ready_before" => $cacheReady ? "true" : "false",
            "cache_path" => $cachePath,
        ], $ok ? "info" : "warn");

        return $ok;
    } catch (Throwable $e) {
        if ($e instanceof SYNTHProtocolCancelled) throw $e;
        Logger::error("[SYNTH TTS] TTS generation failed" . Logger::formatContext([
            "speaker" => $speaker,
            "error" => $e->getMessage(),
        ]));
        Logger::phaseEnd($phaseName, [
            "status" => "error",
            "speaker" => $speaker,
            "error" => $e->getMessage(),
        ], "error");
        return false;
    } finally {
        // Restore temporary speaker overrides on success, provider failure and cancellation.
        if ($hadName) $GLOBALS["SYNTH_NAME"] = $oldName;
        else unset($GLOBALS["SYNTH_NAME"]);
        if ($hadVoice) $GLOBALS["PATCH_OVERRIDE_VOICE"] = $oldVoice;
        else unset($GLOBALS["PATCH_OVERRIDE_VOICE"]);
        foreach($canonicalSaved as $key=>[$exists,$value]) {
            if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);
        }
    }
}

?>
