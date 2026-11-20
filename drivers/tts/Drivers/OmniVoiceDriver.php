<?php

// OmniVoice speech provider (TTS key "omnivoice"). Moved from tts/tts-omnivoice.php, which now only registers this driver.
// The provider logic is unchanged: it reads the connector's own TTS settings from the legacy globals and writes
// the speech cache exactly as before.

namespace {
    if (!defined('SYNTH_TTS_SCRIPT_DIR')) define('SYNTH_TTS_SCRIPT_DIR', dirname(__DIR__, 3) . '/tts');
}

namespace {

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/speech_cache.php';

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/provider_stream.php';

if (!function_exists('omnivoice_normalize_endpoint_url')) {
    function omnivoice_normalize_endpoint_url($url) {
        return rtrim(trim(strval($url ?? '')), '/');
    }
}

if (!function_exists('omnivoice_sanitize_language')) {
    function omnivoice_sanitize_language($language): string {
        $language = preg_replace('/[^a-z\-]/i', '', strtolower(trim(strval($language ?? ''))));
        return $language !== '' ? $language : '';
    }
}

if (!function_exists('omnivoice_apply_text_hooks')) {
    function omnivoice_apply_text_hooks(string $text): string {
        foreach (['OMNIVOICE_TEXTMODIFIER', 'XTTS_TEXTMODIFIER'] as $hookGroup) {
            if (!isset($GLOBALS["HOOKS"][$hookGroup]) || !is_array(\Synth\Bootstrap::config("HOOKS")[$hookGroup])) {
                continue;
            }
            foreach (\Synth\Bootstrap::config("HOOKS")[$hookGroup] as $hook) {
                Logger::info("Calling hook." . __FILE__ . " " . __LINE__ . " " . __FUNCTION__);
                $text = call_user_func($hook, $text);
            }
        }
        return $text;
    }
}

if (!function_exists('omnivoice_fallback_codename')) {
    function omnivoice_fallback_codename(): string {
        $name = \Synth\Bootstrap::config("SYNTH_NAME") ?? \Synth\Bootstrap::config("HERIKA_NAME") ?? '';
        $codename = str_replace(" ", "_", mb_strtolower(strval($name), 'UTF-8'));
        $codename = str_replace("'", "+", $codename);
        return preg_replace('/[^a-zA-Z0-9_+]/u', '', $codename);
    }
}

if (!function_exists('omnivoice_post_tts')) {
    function omnivoice_post_tts(string $url, string $text, string $voice, string $language) {
        $data = [
            'text' => $text,
            'speaker_wav' => $voice,
        ];
        if ($language !== '') {
            $data['language'] = $language;
        }

        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\nAccept: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
            ],
        ];

        return [
            'response' => synth_speech_fetch($url, stream_context_create($options), $http_response_header),
            'options' => $options,
        ];
    }
}

if (!function_exists('omnivoice_switch_language')) {
    function omnivoice_switch_language(string $endpoint, string $language): void {
        $payload = json_encode(['language' => $language]);
        if (!is_string($payload) || $payload === '') {
            return;
        }

        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\nAccept: application/json\r\n",
                'method' => 'POST',
                'content' => $payload,
                'ignore_errors' => true,
            ],
        ];
        $result = @synth_speech_fetch($endpoint . "/active_language", stream_context_create($options), $http_response_header);
        if ($result === false) {
            Logger::warn("Unable to switch OmniVoice active language to '{$language}'");
        }
    }
}

if (!function_exists('omnivoice_first_non_empty')) {
    function omnivoice_first_non_empty(...$values): string {
        foreach ($values as $value) {
            $text = trim(strval($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }
}

if (!function_exists('omnivoice_fallback_candidates')) {
    function omnivoice_fallback_candidates(string $requestedVoice): array {
        // Planned replies retry only their captured NPC fallback through the shared reply executor.
        if (isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])) return [];
        $candidates = [
            \Synth\Bootstrap::config("TTS")["OMNIVOICE"]["fallback_male"] ?? '',
            \Synth\Bootstrap::config("TTS")["OMNIVOICE"]["fallback_female"] ?? '',
            omnivoice_fallback_codename(),
        ];

        $seen = [strtolower($requestedVoice) => true];
        $result = [];
        foreach ($candidates as $candidate) {
            $candidate = trim(strval($candidate));
            $key = strtolower($candidate);
            if ($candidate === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $candidate;
        }
        return $result;
    }
}

}

namespace Synth\Drivers\Tts\Drivers {


    use Logger;

    use Synth\Drivers\Tts\TtsProviderInterface;

    final class OmniVoiceDriver implements TtsProviderInterface
    {
        public const KEY = 'omnivoice';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {
    $cacheHash = md5(trim($stringforhash));
    $cachePath = SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache" . DIRECTORY_SEPARATOR;

    if (isset($GLOBALS["AVOID_TTS_CACHE"]) && \Synth\Bootstrap::config("AVOID_TTS_CACHE") === false) {
        $cachedFile = $cachePath . $cacheHash . ".wav";
        if (file_exists($cachedFile)) {
            return $cachedFile;
        }
    }

    $starTime = microtime(true);
    $newString = omnivoice_apply_text_hooks($textString);
    $endpoint = omnivoice_normalize_endpoint_url(\Synth\Bootstrap::config("TTS")["OMNIVOICE"]["endpoint"] ?? 'http://127.0.0.1:8021');
    $url = $endpoint . "/tts_to_audio";

    $lang = omnivoice_first_non_empty(
        \Synth\Bootstrap::config("PATCH_OVERRIDE_TTS_LANGUAGE") ?? '',
        (!empty($GLOBALS["LANG_LLM_XTTS"]) ? (\Synth\Bootstrap::config("LLM_LANG") ?? '') : ''),
        \Synth\Bootstrap::config("TTS")["FORCED_LANG_DEV"] ?? '',
        \Synth\Bootstrap::config("TTS")["OMNIVOICE"]["language"] ?? ''
    );
    $lang = omnivoice_sanitize_language($lang);
    if ($lang !== '') {
        omnivoice_switch_language($endpoint, $lang);
    }

    $voice = omnivoice_first_non_empty(
        \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE") ?? '',
        \Synth\Bootstrap::config("TTS")["FORCED_VOICE_DEV"] ?? '',
        \Synth\Bootstrap::config("TTS")["OMNIVOICE"]["voiceid"] ?? ''
    );

    $result = omnivoice_post_tts($url, $newString, $voice, $lang);
    $response = $result['response'];
    $options = $result['options'];

    if ($response === false) {
        Logger::error("Error occurred. " . __FILE__ . " " . __LINE__ . " " . __FUNCTION__);
        foreach (omnivoice_fallback_candidates($voice) as $fallbackVoice) {
            $result = omnivoice_post_tts($url, $newString, $fallbackVoice, $lang);
            $response = $result['response'];
            $options = $result['options'];
            if ($response !== false) {
                $voice = $fallbackVoice;
                break;
            }
        }
    }

    if (is_array(\Synth\Bootstrap::config("TTS_FFMPEG_FILTERS") ?? null)) {
        $GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"] = "adelay=150|150";
        $FFMPEG_FILTER = '-af "' . implode(",", \Synth\Bootstrap::config("TTS_FFMPEG_FILTERS")) . '"';
    } else {
        $FFMPEG_FILTER = '-filter:a "adelay=150|150"';
    }

    if ($response !== false) {
        $size = strlen($response);
        $fname = $cachePath . $cacheHash . ".wav";

        $startTimeTrans = microtime(true);
        $filter=!empty($GLOBALS['TTS_FFMPEG_FILTERS']) && is_array(\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS'))
            ? implode(',',\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS')) : 'adelay=150|150';
        if (!synth_transcode_speech_cache($response,$fname,$filter)) return false;
        $endTimeTrans = microtime(true) - $startTimeTrans;

        $textString .= PHP_EOL . print_r($options, true);
        $textString .= PHP_EOL . print_r($http_response_header ?? [], true);
        file_put_contents($cachePath . $cacheHash . ".txt", trim($textString) . "\n$FFMPEG_FILTER\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
        $GLOBALS["DEBUG_DATA"][] = (microtime(true) - $starTime) . " secs in omnivoice call";

        if (isset($GLOBALS["DEVELOP_STORE_AUDIO_FOR_TRANING"]) && \Synth\Bootstrap::config("DEVELOP_STORE_AUDIO_FOR_TRANING")) {
            $tfolder = $cachePath . "/" . $voice;
            @mkdir($tfolder);
            copy($fname, $tfolder . "/" . basename($fname));
        }

        return "soundcache/" . $cacheHash . ".wav";
    }

    $textString .= PHP_EOL . print_r($options, true);
    $textString .= PHP_EOL . print_r($http_response_header ?? 'No HTTP response headers available', true);
    file_put_contents($cachePath . $cacheHash . ".err", trim($textString));
    return false;
        }
    }
}
