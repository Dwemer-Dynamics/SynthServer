<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");

$cleaned_dialogue = synthExtractPlayerTtsDialogueLine($gameRequest[3] ?? '');
// Auto Chat publishes its exact accepted line, never a re-extraction of the derived payload.
$autoChatLine = synth_autochat_line();
if ($autoChatLine !== null) $cleaned_dialogue = $autoChatLine['text'];

if (!function_exists('emitPlayerTextOnlySpeechLine')) {
    function emitPlayerTextOnlySpeechLine($line)
    {
        $subtitle = (string)$line;
        if (function_exists('formatPlayerSubtitleText')) {
            $subtitle = formatPlayerSubtitleText($subtitle, \Synth\Bootstrap::config("PLAYER_NAME") ?? null);
        } else {
            $subtitle = str_replace(["\r", "\n", "|"], " ", $subtitle);
            $subtitle = trim(preg_replace('/\s+/', ' ', $subtitle));
        }

        if ($subtitle === '') {
            return;
        }

        synth_buffer_speech_response_line("Player", $subtitle, "", "__player_text_only", "", "", 1.0, speechRole: 'player');
        $outputLine = json_encode([
            "speaker" => "Player",
            "action" => "say",
            "text" => $subtitle,
            "metadata" => [
                "listener" => "__player_text_only",
                "volume" => 1.0,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        \Synth\Bootstrap::setAt('DEBUG_DATA', "OUTPUT_LOG", $outputLine);
        $outputToPluginLog = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "log" . DIRECTORY_SEPARATOR . "output_to_plugin.log";
        Logger::rotateLogIfTooLarge($outputToPluginLog);
        Logger::debug("[plugin-output] queued player text-only line" . Logger::formatContext([
            "chars" => strlen($subtitle),
        ]));
        @file_put_contents($outputToPluginLog, $outputLine, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('generatePlayerTtsCacheOnly')) {
    function generatePlayerTtsCacheOnly($line)
    {
        $subtitle = function_exists('formatPlayerSubtitleText')
            ? formatPlayerSubtitleText($line, \Synth\Bootstrap::config("PLAYER_NAME") ?? null)
            : trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "|"], " ", (string)$line)));

        if ($subtitle === '') {
            return false;
        }

        $cacheKey = function_exists('synthTtsCacheKeyForLine')
            ? synthTtsCacheKeyForLine("Player", $subtitle)
            : $subtitle;

        $ttsOutput = function_exists('callConfiguredTts')
            ? callConfiguredTts($line, "default", $cacheKey)
            : false;

        if ($ttsOutput) {
            \Synth\Bootstrap::appendPath('TRACK', ["FILES_GENERATED"], $ttsOutput);
            Logger::info("[Player TTS] Generated cache-only player speech: {$ttsOutput}");
        } else {
            Logger::warn("[Player TTS] Cache-only player speech generation returned no output.");
        }

        if (function_exists('synth_tts_soundcache_path')) {
            \Synth\Bootstrap::set('PLAYER_TTS_LAST_CACHE_PATH', synth_tts_soundcache_path(dirname(__DIR__), "Player", $subtitle));
        }

        $expectedCachePath = trim(strval(\Synth\Bootstrap::config("PLAYER_TTS_EXPECTED_CACHE_PATH") ?? ''));
        $resolvedTtsOutput = is_string($ttsOutput) ? trim($ttsOutput) : '';
        if ($resolvedTtsOutput !== '' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $resolvedTtsOutput) && !str_starts_with($resolvedTtsOutput, DIRECTORY_SEPARATOR)) {
            $resolvedTtsOutput = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($resolvedTtsOutput, "\\/");
        }
        if ($expectedCachePath !== '' && $resolvedTtsOutput !== '' && is_file($resolvedTtsOutput) && filesize($resolvedTtsOutput) > 44) {
            $normalizedOutput = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedTtsOutput);
            $normalizedExpected = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $expectedCachePath);
            if (strcasecmp($normalizedOutput, $normalizedExpected) !== 0) {
                $expectedDir = dirname($expectedCachePath);
                if (!is_dir($expectedDir)) {
                    @mkdir($expectedDir, 0775, true);
                }
                if (@copy($resolvedTtsOutput, $expectedCachePath)) {
                    \Synth\Bootstrap::set('PLAYER_TTS_LAST_CACHE_PATH', $expectedCachePath);
                    Logger::info("[Player TTS] Copied generated player speech to expected cache path" . Logger::formatContext([
                        "generated" => $resolvedTtsOutput,
                        "expected" => $expectedCachePath,
                    ]));
                } else {
                    Logger::warn("[Player TTS] Could not copy generated player speech to expected cache path" . Logger::formatContext([
                        "generated" => $resolvedTtsOutput,
                        "expected" => $expectedCachePath,
                    ]));
                }
            }
        }

        return $ttsOutput;
    }
}

audit_log(__FILE__ . " " . __LINE__);
$playerTtsPhase = "player_tts:" . substr(md5((string)$cleaned_dialogue), 0, 8);
$playerTtsStatus = "started";
Logger::phaseStart($playerTtsPhase, [
    "chars" => strlen((string)$cleaned_dialogue),
    "write_output" => \Synth\Bootstrap::has('PLAYER_TTS_WRITE_OUTPUT') ? ((bool)\Synth\Bootstrap::config("PLAYER_TTS_WRITE_OUTPUT") ? "true" : "false") : "default",
]);

if (!class_exists('Player')) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
}
if (!class_exists('TTSConnector')) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");
}

$origTTS = \Synth\Bootstrap::config("TTSFUNCTION") ?? '';
$hadTtsFunctionAlias = array_key_exists("TTS_FUNCTION", $GLOBALS);
$origTtsFunctionAlias = \Synth\Bootstrap::config("TTS_FUNCTION") ?? null;
$hadCurrentTtsConnectorId = array_key_exists("SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID", $GLOBALS);
$origCurrentTtsConnectorId = \Synth\Bootstrap::config("SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID") ?? null;
$origName = \Synth\Bootstrap::config("SYNTH_NAME") ?? '';
$hadSpeechRole = array_key_exists('SYNTH_SPEECH_ROLE',$GLOBALS);
$origSpeechRole = \Synth\Bootstrap::config('SYNTH_SPEECH_ROLE') ?? null;
$hadPatchOverrideVoice = array_key_exists("PATCH_OVERRIDE_VOICE", $GLOBALS);
$hadPatchOverrideVoiceId = array_key_exists("PATCH_OVERRIDE_VOICE_ID", $GLOBALS);
$hadPatchOverrideLanguage = array_key_exists("PATCH_OVERRIDE_TTS_LANGUAGE", $GLOBALS);
$hadPatchOverrideTtsOptions = array_key_exists("PATCH_OVERRIDE_TTS_OPTIONS", $GLOBALS);
$oldPatchOverrideVoice = \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE") ?? null;
$oldPatchOverrideVoiceId = \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE_ID") ?? null;
$oldPatchOverrideLanguage = \Synth\Bootstrap::config("PATCH_OVERRIDE_TTS_LANGUAGE") ?? null;
$oldPatchOverrideTtsOptions = \Synth\Bootstrap::config("PATCH_OVERRIDE_TTS_OPTIONS") ?? null;

try {
    \Synth\Bootstrap::set('SYNTH_SPEECH_ROLE', 'player');
    $writeOutput = \Synth\Bootstrap::has('PLAYER_TTS_WRITE_OUTPUT') ? (bool)\Synth\Bootstrap::config("PLAYER_TTS_WRITE_OUTPUT") : true;
    $requestEvent = \Synth\Bootstrap::requestEvent()['json'] ?? [];
    if ($writeOutput && !empty(\Synth\Bootstrap::claimedRequest())
        && synth_protocol_owns_dialogue_turn($requestEvent)
        && in_array($requestEvent['type'] ?? '', ['input_text', 'input_audio'], true)) {
        if (synthShouldSkipPlayerTtsForRequest($gameRequest[0] ?? '', $gameRequest[3] ?? '')) {
            $playerTtsStatus = "skipped";
            return;
        }
        // The native input is already the player's speech; retain its transcript without revoicing it. Auto Chat's
        // generated line is new player speech, so only it continues to the configured player TTS preference below.
        if ($autoChatLine === null) {
            synth_emit_native_player_transcript($gameRequest);
            $playerTtsStatus = "text_only";
            return;
        }
    }

    $player = new Player();
    $ttsConnector = new TTSConnector();

    $connectorId = intval($player->get('tts_connector_id') ?? 0);
    $currentConnector = $connectorId > 0 ? $ttsConnector->getById($connectorId) : null;
    $hasPlayerTtsConnector = $currentConnector && strtolower(trim(strval($currentConnector['driver'] ?? 'none'))) !== 'none';

    if ($hasPlayerTtsConnector) {
        $ttsConnector->setOldGlobals($currentConnector);
        \Synth\Bootstrap::set('TTSFUNCTION_PLAYER', strval($currentConnector['driver'] ?? ''));
        $playerDriver = strtolower(trim(strval($currentConnector['driver'] ?? '')));
        \Synth\Bootstrap::set('TTSFUNCTION', $playerDriver);
        \Synth\Bootstrap::set('TTS_FUNCTION', $playerDriver);

        $playerVoiceId = trim(strval($player->get('tts_voice_override') ?? ''));
        $voiceIdOverride = trim(strval($player->get('tts_voice_id_override') ?? ''));
        $languageOverride = trim(strval($player->get('tts_language_override') ?? ''));

        \Synth\Bootstrap::set('TTSFUNCTION_PLAYER_VOICE', $playerVoiceId);
        \Synth\Bootstrap::set('TTSFUNCTION_PLAYER_VOICE_ID', $voiceIdOverride);
        \Synth\Bootstrap::set('TTSFUNCTION_PLAYER_LANGUAGE', $languageOverride);

        if ($playerVoiceId !== '') {
            \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE', $playerVoiceId);
        } else {
            \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE');
        }

        if ($voiceIdOverride !== '') {
            \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE_ID', $voiceIdOverride);
        } else {
            \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE_ID');
        }

        if ($languageOverride !== '') {
            \Synth\Bootstrap::set('PATCH_OVERRIDE_TTS_LANGUAGE', $languageOverride);
        } else {
            \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_LANGUAGE');
        }

        if ($playerDriver === '11labs') {
            $playerElevenLabsOverrides = [];
            foreach ([
                'model_id' => 'tts_elevenlabs_model_id',
                'speed' => 'tts_elevenlabs_speed',
                'stability' => 'tts_elevenlabs_stability',
                'similarity_boost' => 'tts_elevenlabs_similarity_boost',
                'style' => 'tts_elevenlabs_style',
                'v3_audio_tags' => 'tts_elevenlabs_v3_audio_tags',
            ] as $metadataKey => $playerKey) {
                $rawValue = trim(strval($player->get($playerKey) ?? ''));
                if ($rawValue !== '') {
                    $playerElevenLabsOverrides[$metadataKey] = $rawValue;
                }
            }

            $speakerBoostValue = trim(strval($player->get('tts_elevenlabs_use_speaker_boost') ?? ''));
            if ($speakerBoostValue !== '') {
                $playerElevenLabsOverrides['use_speaker_boost'] = strtolower($speakerBoostValue) === 'true';
            }

            if (!empty($playerElevenLabsOverrides)) {
                \Synth\Bootstrap::set('PATCH_OVERRIDE_TTS_OPTIONS', [
                    'driver' => $playerDriver,
                    'metadata' => $playerElevenLabsOverrides,
                ]);
            } else {
                \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_OPTIONS');
            }
        } else {
            \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_OPTIONS');
        }
    }

    \Synth\Bootstrap::set('PATCH_DONT_STORE_SPEECH_ON_DB', true);
    \Synth\Bootstrap::set('SYNTH_NAME', "Player");

    if ($cleaned_dialogue === '') {
        Logger::info("[Player TTS] Skipping empty player dialogue after normalization.");
        $playerTtsStatus = "empty";
        return;
    }

    if ($hasPlayerTtsConnector) {
        Logger::info("[Player TTS] Generating playable player speech via {$currentConnector['driver']}.");
        $playerTtsStatus = "generating";
        $hadScriptlineListener = array_key_exists("SCRIPTLINE_LISTENER", $GLOBALS);
        $hadScriptlineListenerAtomic = array_key_exists("SCRIPTLINE_LISTENER_ATOMIC", $GLOBALS);
        $oldScriptlineListener = \Synth\Bootstrap::config("SCRIPTLINE_LISTENER") ?? null;
        $oldScriptlineListenerAtomic = \Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC") ?? null;

        try {
            if (trim(strval(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER") ?? '')) === '') {
                \Synth\Bootstrap::set('SCRIPTLINE_LISTENER', "__player_tts");
            }
            if (trim(strval(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC") ?? '')) === '') {
                \Synth\Bootstrap::set('SCRIPTLINE_LISTENER_ATOMIC', "__player_tts");
            }

            if ($writeOutput && $autoChatLine !== null) {
                synth_autochat_emit_player_line(true);
            } elseif ($writeOutput) {
                $ownspeech = returnlines([$cleaned_dialogue], true);
            } else {
                generatePlayerTtsCacheOnly($cleaned_dialogue);
            }

            if ($writeOutput && function_exists('formatPlayerSubtitleText') && function_exists('synth_tts_soundcache_path')) {
                $subtitle = formatPlayerSubtitleText($cleaned_dialogue, \Synth\Bootstrap::config("PLAYER_NAME") ?? null);
                \Synth\Bootstrap::set('PLAYER_TTS_LAST_CACHE_PATH', synth_tts_soundcache_path(dirname(__DIR__), "Player", $subtitle));
            }
        } finally {
            if ($hadScriptlineListener) {
                \Synth\Bootstrap::set('SCRIPTLINE_LISTENER', $oldScriptlineListener);
            } else {
                \Synth\Bootstrap::forget('SCRIPTLINE_LISTENER');
            }
            if ($hadScriptlineListenerAtomic) {
                \Synth\Bootstrap::set('SCRIPTLINE_LISTENER_ATOMIC', $oldScriptlineListenerAtomic);
            } else {
                \Synth\Bootstrap::forget('SCRIPTLINE_LISTENER_ATOMIC');
            }
        }
    } elseif ($writeOutput) {
        Logger::info("[Player TTS] No player TTS connector configured; emitting text-only player line.");
        if ($autoChatLine !== null) synth_autochat_emit_player_line(false);
        else emitPlayerTextOnlySpeechLine($cleaned_dialogue);
        $playerTtsStatus = "text_only";
    }

    if ($playerTtsStatus === "generating") {
        $playerTtsStatus = "ok";
    }
} finally {
    if ($hadSpeechRole) \Synth\Bootstrap::set('SYNTH_SPEECH_ROLE', $origSpeechRole);
    else \Synth\Bootstrap::forget('SYNTH_SPEECH_ROLE');
    if ($hadPatchOverrideVoice) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE', $oldPatchOverrideVoice);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE');
    }

    if ($hadPatchOverrideVoiceId) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_VOICE_ID', $oldPatchOverrideVoiceId);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_VOICE_ID');
    }

    if ($hadPatchOverrideLanguage) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_TTS_LANGUAGE', $oldPatchOverrideLanguage);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_LANGUAGE');
    }

    if ($hadPatchOverrideTtsOptions) {
        \Synth\Bootstrap::set('PATCH_OVERRIDE_TTS_OPTIONS', $oldPatchOverrideTtsOptions);
    } else {
        \Synth\Bootstrap::forget('PATCH_OVERRIDE_TTS_OPTIONS');
    }

    \Synth\Bootstrap::set('TTSFUNCTION', $origTTS);
    if ($hadTtsFunctionAlias) {
        \Synth\Bootstrap::set('TTS_FUNCTION', $origTtsFunctionAlias);
    } else {
        \Synth\Bootstrap::forget('TTS_FUNCTION');
    }
    if ($hadCurrentTtsConnectorId) {
        \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID', $origCurrentTtsConnectorId);
    } else {
        \Synth\Bootstrap::forget('SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID');
    }
    \Synth\Bootstrap::forget('SCRIPTLINE_ANIMATION_SENT');
    \Synth\Bootstrap::set('SYNTH_NAME', $origName);
    \Synth\Bootstrap::forget('PATCH_DONT_STORE_SPEECH_ON_DB');
    Logger::phaseEnd($playerTtsPhase, [
        "status" => $playerTtsStatus,
        "cache_path" => \Synth\Bootstrap::config("PLAYER_TTS_LAST_CACHE_PATH") ?? "",
    ], in_array($playerTtsStatus, ["ok", "text_only", "empty", "skipped"], true) ? "info" : "warn");
}

audit_log(__FILE__ . " " . __LINE__);
$startTimeAfterPlayerTTTS = microtime(true);

?>
