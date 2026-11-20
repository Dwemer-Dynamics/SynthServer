<?php

// Deepgram speech provider (TTS key "deepgram"). Moved from tts/tts-deepgram.php, which now only registers this driver.
// The provider logic is unchanged: it reads the connector's own TTS settings from the legacy globals and writes
// the speech cache exactly as before.

namespace {
    if (!defined('SYNTH_TTS_SCRIPT_DIR')) define('SYNTH_TTS_SCRIPT_DIR', dirname(__DIR__, 3) . '/tts');
}

namespace {

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/speech_cache.php';

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/provider_stream.php';


}

namespace Synth\Drivers\Tts\Drivers {


    use Logger;

    use Synth\Drivers\Tts\TtsProviderInterface;

    final class DeepgramDriver implements TtsProviderInterface
    {
        public const KEY = 'deepgram';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {

    // Resolve API key: prefer API Badge 'Deepgram', fallback to schema
    $apiKey = '';
    try {
        if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) require_once(SYNTH_TTS_SCRIPT_DIR . "/../lib/{$GLOBALS["DBDRIVER"]}.class.php");
        if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) $GLOBALS["db"] = new sql();
        require_once SYNTH_TTS_SCRIPT_DIR . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
        $row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel('deepgram');
        if (is_array($row) && !empty($row['api_key'])) $apiKey = trim($row['api_key']);
    } catch (Throwable $_e) {}
    if ($apiKey === '') $apiKey = \Synth\Bootstrap::config("TTS")["deepgram"]["API_KEY"];

    $voiceModel = isset($GLOBALS["TTS"]["deepgram"]["model"]) ? \Synth\Bootstrap::config("TTS")["deepgram"]["model"] : "aura-2-thalia-en";
    // Allow voice override for player TTS or per-NPC voice
    if (isset($GLOBALS["PATCH_OVERRIDE_VOICE"]) && !empty($GLOBALS["PATCH_OVERRIDE_VOICE"])) {
        $voiceModel = \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE");
    }
    $bitRate = isset($GLOBALS["TTS"]["deepgram"]["bitrate"]) ? \Synth\Bootstrap::config("TTS")["deepgram"]["bitrate"] : 24000;
    $encoding = "linear16";

    $startTimeFull = microtime(true);
    Logger::info("TTS Deepgram started");

    $cachePath = SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/";
    $hash = md5(trim($stringforhash));
    $wavName = $cachePath . $hash . ".wav";

    // Check cache
    if (!isset($GLOBALS["AVOID_TTS_CACHE"])) {
        if (file_exists($wavName)) {
            Logger::info("TTS cache hit for hash: " . $hash);
            return "soundcache/" . $hash . ".wav";
        } else {
            Logger::info("TTS cache miss for hash: " . $hash);
        }
    } else {
        Logger::info("TTS cache avoidance enabled.");
    }

    $url = "https://api.deepgram.com/v1/speak?model=$voiceModel&encoding=$encoding&sample_rate=$bitRate";
    $headers = [
        "Authorization: Token $apiKey",
        "Content-Type: application/json"
    ];

    $data = json_encode(["text" => $textString]);

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $data,
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @synth_speech_fetch($url, $context, $http_response_header);

    if ($response !== false) {
        // Convert to WAV and normalize
        $startTimeTrans = microtime(true);
        if (!synth_transcode_speech_cache($response,$wavName,'speechnorm=e=6:r=0.0001:l=1')) return false;
        $endTimeTrans = microtime(true) - $startTimeTrans;
        $endTimeFull = microtime(true) - $startTimeFull;

        file_put_contents($cachePath . $hash . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $startTimeTrans) . " sec\n\r");
        $GLOBALS["DEBUG_DATA"][] = "Deepgram TTS complete. FFMPEG time: $endTimeTrans sec, total $endTimeFull secs";
        Logger::info("Deepgram TTS complete for hash: " . $hash . ", FFMPEG time: $endTimeTrans sec, total $endTimeFull secs");

        return "soundcache/" . $hash . ".wav";
    } else {
        $error = error_get_last();
        $errorMessage = "Error fetching TTS data from Deepgram: " . print_r($http_response_header, true) . ' ' . $error['message'];
        Logger::error($errorMessage);

        $textString .= print_r($http_response_header, true) . ' ' . $error['message'];
        file_put_contents($cachePath . $hash . ".err", trim($textString));

        Logger::error("TTS failed and error written to: " . $cachePath . $hash . ".err");
        return false;
    }
        }
    }
}
