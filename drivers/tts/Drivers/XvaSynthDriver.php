<?php

// XvaSynth speech provider (TTS key "xvasynth"). Moved from tts/tts-xvasynth.php, which now only registers this driver.
// The provider logic is unchanged: it reads the connector's own TTS settings from the legacy globals and writes
// the speech cache exactly as before.

namespace {
    if (!defined('SYNTH_TTS_SCRIPT_DIR')) define('SYNTH_TTS_SCRIPT_DIR', dirname(__DIR__, 3) . '/tts');
}

namespace {

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/speech_cache.php';

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/provider_stream.php';

// Each external synthesis gets a unique private destination; a late writer cannot satisfy another request.
function xvasynth_private_output(): array|false
{
    $root=realpath(dirname(SYNTH_TTS_SCRIPT_DIR));
    if ($root===false) return false;
    if (PHP_OS_FAMILY!=='Windows') {
        $distro=strval($GLOBALS['TTS']['XVASYNTH']['distroname'] ?? '');
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 ._-]{0,126}[A-Za-z0-9_-])?$/D',$distro)) return false;
    }
    synth_protocol_assert_request_active();
    $directory=$root.'/data/tmp/npc_tts_jobs/xvasynth-'.bin2hex(random_bytes(16));
    if (!@mkdir($directory,0775,true)) return false;
    $output=$directory.'/audio.wav';
    $remote=PHP_OS_FAMILY==='Windows'?$output:'\\\\wsl.localhost\\'.$distro.str_replace('/','\\',$output);
    return ['directory'=>$directory,'output'=>$output,'remote'=>$remote];
}

}

namespace Synth\Drivers\Tts\Drivers {

    require_once dirname(__DIR__) . '/TtsProviderInterface.php';

    use Synth\Drivers\Tts\TtsProviderInterface;

    final class XvaSynthDriver implements TtsProviderInterface
    {
        public const KEY = 'xvasynth';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {
    // Cache check
    if (isset($GLOBALS["AVOID_TTS_CACHE"]) && $GLOBALS["AVOID_TTS_CACHE"] === false) {
        if (file_exists(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav")) {
            return SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
        }
    }

    $starTime = microtime(true);
    $apiEndpoint = $GLOBALS["TTS"]["XVASYNTH"]["url"];
    $fileName = md5(trim($stringforhash));

    // Determine which voice to use
    $voice = $GLOBALS["TTS"]["XVASYNTH"]["model"]; // Default NPC voice
    if (isset($GLOBALS["PATCH_OVERRIDE_VOICE"]) && !empty($GLOBALS["PATCH_OVERRIDE_VOICE"])) {
        $voice = $GLOBALS["PATCH_OVERRIDE_VOICE"]; // Player voice
    }

    // Normalize special narrator voice id
    if (is_string($voice) && strtolower($voice) === 'thenarrator') {
        $voice = 'fo4_maleadult02';
    }

    // Fallout 4 xVASynth models use the fo4_ prefix.
    if (!preg_match('/^[A-Za-z]+_/', $voice)) {
        $voice = 'fo4_' . $voice;
    }

    $lang = $GLOBALS["TTS"]["XVASYNTH"]["base_lang"];
    if (isset($GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"]))
        $lang = $GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"];

    // Always initialize the model for each call
    $initData = array(
        "outputs" => "",
        "model" =>   "resources/app/models/{$GLOBALS["TTS"]["XVASYNTH"]["game"]}/{$voice}",
        "modelType" =>  $GLOBALS["TTS"]["XVASYNTH"]["modelType"],
        "version" => $GLOBALS["TTS"]["XVASYNTH"]["version"],
        "base_lang" => $lang,
        "pluginsContext" => "{}"
    );

    $initContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/plain;charset=UTF-8\r\n",
            'content' => json_encode($initData),
            'timeout' => 30,
        ],
    ]);

    $GLOBALS["DEBUG_DATA"]["XVASYNTH"]["prerequest"]=json_encode($initData);

    // Initialize with the correct voice for this call
    $response = synth_speech_fetch("$apiEndpoint/loadModel", $initContext, $http_response_header);
    $GLOBALS["DEBUG_DATA"]["XVASYNTH"]["preresponse"]=$response;
    if ($response===false) return false;

    $private=xvasynth_private_output();
    if ($private===false) return false;
    try {

        // For synthesis, use the determined voice model
        $jsonData = array(
            "sequence" => "$textString",
            "editorStyles" => (object)[],
            "pace" => $GLOBALS["TTS"]["XVASYNTH"]["pace"],
            "base_lang" => $lang,
            "base_emb" => array(),
            "modelType" => $GLOBALS["TTS"]["XVASYNTH"]["modelType"],
            "useSR" => false,
            "useCleanup" => false,
            "outfile" => $private['remote'],
            "pluginsContext" => "{}",
            "vocoder" => $GLOBALS["TTS"]["XVASYNTH"]["vocoder"],
            "waveglowPath" => $GLOBALS["TTS"]["XVASYNTH"]["waveglowPath"],
            "model" => "resources/app/models/{$GLOBALS["TTS"]["XVASYNTH"]["game"]}/{$voice}"
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/plain;charset=UTF-8\r\n",
                'content' => json_encode($jsonData),
                'timeout' => 60,
            ],
        ]);

        $GLOBALS["DEBUG_DATA"]["XVASYNTH"]["request"]=json_encode($jsonData);

        // Send the POST request with the specified headers
        $response = synth_speech_fetch("$apiEndpoint/synthesize", $context, $http_response_header);

        $GLOBALS["DEBUG_DATA"]["XVASYNTH"]["response"]=$response;

        // Add FFMPEG filter handling from xtts
        if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
            $GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"] = "adelay=150|150";
            $FFMPEG_FILTER = '-af "' . implode(",", $GLOBALS["TTS_FFMPEG_FILTERS"]) . '"';
        } else {
            $FFMPEG_FILTER = '-filter:a "adelay=150|150"';
        }

        // Handle the response with improved error handling and audio processing
        if ($response !== false) {
            $oname = $private['output'];
            $fname = SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . $fileName . ".wav";
            synth_protocol_assert_request_active();
            if (is_link($oname) || !is_file($oname)) return false;
            $audio=file_get_contents($oname,false,null,0,SYNTH_MEDIA_MAX_BYTES+1);
            if (!is_string($audio) || $audio==='' || strlen($audio)>SYNTH_MEDIA_MAX_BYTES || !synth_media_is_wave($audio)) return false;
            $size=strlen($audio);

            // Add audio processing like in xtts
            $startTimeTrans = microtime(true);
            $filter=!empty($GLOBALS['TTS_FFMPEG_FILTERS']) && is_array($GLOBALS['TTS_FFMPEG_FILTERS'])
                ? implode(',',$GLOBALS['TTS_FFMPEG_FILTERS']) : 'adelay=150|150';
            if (!synth_transcode_speech_cache($audio,$fname,$filter)) return false;
            $endTimeTrans = microtime(true) - $startTimeTrans;

            // Add detailed logging like in xtts
            file_put_contents(
                SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . $fileName . ".txt",
                trim($textString) . "\n$FFMPEG_FILTER\n\rtotal call time:" . (microtime(true) - $starTime) .
                " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"default\",$stringforhash)"
            );

            $GLOBALS["DEBUG_DATA"][] = (microtime(true) - $starTime) . " secs in xvasynth call";
            return "soundcache/" . $fileName . ".wav";
        } else {
            $textString .= print_r($http_response_header, true);
            $GLOBALS["DEBUG_DATA"]["XVASYNTH"]["error"] = "$textString";
            file_put_contents(
                SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . $fileName . ".err",
                trim($textString)
            );
            return false;
        }
    } finally {
        if (is_file($private['output']) || is_link($private['output'])) @unlink($private['output']);
        @rmdir($private['directory']);
    }
        }
    }
}
