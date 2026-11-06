<?php

// A native player's own text/transcript is an acknowledgement, independent of NPC prompt and voice work.
// Bind deduplication to the exact admitted request; a fallback must not repeat it.
function synth_emit_native_player_transcript(array $gameRequest): bool
{
    $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
        || !synth_protocol_owns_dialogue_turn($event)
        || !in_array($event['type'] ?? '', ['input_text', 'input_audio'], true)
        || !in_array($gameRequest[0] ?? '', ['inputtext', 'inputtext_s', 'narrator_inputtext'], true)
        || !($GLOBALS['PLAYER_TTS_WRITE_OUTPUT'] ?? true)
        || synthShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? '')) return false;
    synth_protocol_assert_request_active();
    $owner = json_encode(array_intersect_key($event,
        array_flip(['session_id', 'request_id', 'generation', 'turn_id', 'context_sequence'])));
    if (($GLOBALS['SYNTH_NATIVE_PLAYER_TRANSCRIPT_OWNER'] ?? null) === $owner) return true;
    $subtitle = synthExtractPlayerTtsDialogueLine($gameRequest[3] ?? '');
    if (function_exists('formatPlayerSubtitleText'))
        $subtitle = formatPlayerSubtitleText($subtitle, $GLOBALS['PLAYER_NAME'] ?? null);
    if ($subtitle === '') return false;
    synth_buffer_speech_response_line('Player', $subtitle, '', '__player_text_only', '', '', 1.0, speechRole: 'player');
    $GLOBALS['SYNTH_NATIVE_PLAYER_TRANSCRIPT_OWNER'] = $owner;
    return true;
}

if (!function_exists('synthExtractPlayerTtsDialogueLine')) {
    function synthExtractPlayerTtsDialogueLine($rawLine)
    {
        $line = @mb_convert_encoding((string)$rawLine, 'UTF-8', 'UTF-8');
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        $jsonCandidate = preg_replace(
            '/\s*\((?:(?:Talking|Whispering|Shouting|Speaking privately) to [^)]+|speaking loudly to [^)]+ from far away)\)\s*$/i',
            '',
            $line
        );
        $jsonCandidate = is_string($jsonCandidate) ? trim($jsonCandidate) : $line;

        $decoded = json_decode($jsonCandidate, true);
        if (is_array($decoded)) {
            $extracted = '';
            foreach (['text', 'line', 'dialogue', 'message', 'player_text'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string)$decoded[$key]) !== '') {
                    $extracted = trim((string)$decoded[$key]);
                    break;
                }
            }

            if (isset($decoded['payload']) && is_array($decoded['payload'])) {
                foreach (['text', 'line', 'dialogue', 'message', 'player_text'] as $key) {
                    if (isset($decoded['payload'][$key]) && is_scalar($decoded['payload'][$key]) && trim((string)$decoded['payload'][$key]) !== '') {
                        $extracted = trim((string)$decoded['payload'][$key]);
                        break;
                    }
                }
            }

            if ($extracted !== '') {
                $line = $extracted;
            } elseif (isset($decoded['schema']) && stripos((string)$decoded['schema'], 'synth.input') !== false) {
                return '';
            }
        }

        $split = explode(':', $line, 2);
        if (count($split) === 2 && preg_match('/^[A-Za-z][A-Za-z0-9_\' -]{0,40}$/', trim($split[0]))) {
            $line = trim($split[1]);
        }

        $line = preg_replace(
            '/\s*\((?:(?:Talking|Whispering|Shouting|Speaking privately) to [^)]+|speaking loudly to [^)]+ from far away)\)\s*$/i',
            '',
            $line
        );
        $line = is_string($line) ? $line : '';
        $line = str_replace(["\r", "\n", "|"], " ", $line);
        $line = preg_replace('/\s+/', ' ', $line);
        return trim($line);
    }
}

if (!function_exists('synthShouldSkipPlayerTtsForRequest')) {
    function synthShouldSkipPlayerTtsForRequest($eventType = null, $eventData = null): bool
    {
        $eventType = strtolower(trim((string)($eventType ?? ($GLOBALS['gameRequest'][0] ?? ''))));
        if (!in_array($eventType, ['inputtext', 'inputtext_s', 'narrator_inputtext'], true)) {
            return false;
        }

        if (!empty($GLOBALS['SYNTH_SKIP_PLAYER_TTS'])) {
            return true;
        }

        $executionMode = strtoupper(trim((string)($GLOBALS['SYNTH_EXECUTION_MODE'] ?? '')));
        if (in_array($executionMode, ['DIRECTOR', 'INJECTION_LOG', 'INJECTION_CHAT'], true)) {
            return true;
        }

        if ($eventData === null && isset($GLOBALS['gameRequest'][3])) {
            $eventData = $GLOBALS['gameRequest'][3];
        }

        if (!is_string($eventData) || trim($eventData) === '') {
            return false;
        }

        $queryParts = function_exists('synth_parse_payload_fields')
            ? synth_parse_payload_fields($eventData)
            : [];
        if (empty($queryParts) && isset($GLOBALS['SYNTH_STRUCTURED_INPUT_FIELDS']) && is_array($GLOBALS['SYNTH_STRUCTURED_INPUT_FIELDS'])) {
            $queryParts = $GLOBALS['SYNTH_STRUCTURED_INPUT_FIELDS'];
        }
        $skipValue = strtolower(trim(strval($queryParts['skip_player_tts'] ?? '')));
        return in_array($skipValue, ['1', 'true', 'yes'], true);
    }
}

if (!function_exists("extractPlayerMenuDialogueLine")) {
    function extractPlayerMenuDialogueLine($rawLine)
    {
        return synthExtractPlayerTtsDialogueLine($rawLine);
    }
}

if (!function_exists("playerMenuTtsCachePath")) {
    function playerMenuTtsCachePath($line)
    {
        $subtitle = function_exists('formatPlayerSubtitleText')
            ? formatPlayerSubtitleText((string)$line, $GLOBALS["PLAYER_NAME"] ?? null)
            : trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "|"], " ", (string)$line)));

        if (function_exists('synth_tts_soundcache_path')) {
            return synth_tts_soundcache_path(dirname(__DIR__), "Player", $subtitle);
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR . md5(trim((string)$line)) . ".wav";
    }
}

if (!function_exists("playerMenuTtsCacheKey")) {
    function playerMenuTtsCacheKey($line)
    {
        $path = playerMenuTtsCachePath($line);
        $basename = basename($path);
        if (strtolower(substr($basename, -4)) === ".wav") {
            $basename = substr($basename, 0, -4);
        }
        return trim($basename);
    }
}

if (!function_exists("emitPlayerMenuSpeechLine")) {
    function emitPlayerMenuSpeechLine($line)
    {
        $subtitle = str_replace(["\r", "\n", "|"], " ", (string)$line);
        $subtitle = trim(preg_replace('/\s+/', ' ', $subtitle));
        if ($subtitle === "") {
            return;
        }

        synth_buffer_response_line("Player", "say", $subtitle, [
            "listener" => "__player_menu_tts",
            "volume" => 1.0,
            "tts_cache_key" => playerMenuTtsCacheKey($line),
        ], 'player');
    }
}

if (!function_exists("emitPlayerMenuTextOnlyLine")) {
    function emitPlayerMenuTextOnlyLine($line)
    {
        $subtitle = function_exists('formatPlayerSubtitleText')
            ? formatPlayerSubtitleText((string)$line, $GLOBALS["PLAYER_NAME"] ?? null)
            : trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "|"], " ", (string)$line)));
        if ($subtitle === "") {
            return;
        }

        synth_buffer_response_line("Player", "say", $subtitle, [
            "listener" => "__player_text_only",
            "text_only" => true,
            "volume" => 1.0,
        ], 'player');
    }
}

?>
