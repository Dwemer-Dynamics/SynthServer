<?php

// Mimic3 speech provider (TTS key "mimic3"). Moved from tts/tts-mimic3.php, which now only registers this driver.
// The provider logic is unchanged: it reads the connector's own TTS settings from the legacy globals and writes
// the speech cache exactly as before.

namespace {
    if (!defined('SYNTH_TTS_SCRIPT_DIR')) define('SYNTH_TTS_SCRIPT_DIR', dirname(__DIR__, 3) . '/tts');
}

namespace {

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/speech_cache.php';

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/provider_stream.php';

$localPath = SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");

// Delay standalone runtime setup until invocation; loading all adapters must be side-effect free.
function mimic3_prepare_runtime(): void {
    if (isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])) return;
    synthRuntimeBootstrapIfNeeded(dirname(SYNTH_TTS_SCRIPT_DIR), [
        'load_general_settings' => true, 'load_stt_connector' => false, 'load_itt_connector' => false,
    ]);
    synthEnsureTtsConnectorGlobals('mimic3');
}


}

namespace Synth\Drivers\Tts\Drivers {

    require_once dirname(__DIR__) . '/TtsProviderInterface.php';

    use Synth\Drivers\Tts\TtsProviderInterface;

    final class Mimic3Driver implements TtsProviderInterface
    {
        public const KEY = 'mimic3';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {
    mimic3_prepare_runtime();

    $start = microtime(true);

    $ttsServiceUri = rtrim(strval($GLOBALS["TTS"]["MIMIC3"]["URL"] ?? 'http://127.0.0.1:59125'), '/') . "/api/tts";

    $testP="?text=".urlencode($textString);
    $voice = $GLOBALS["PATCH_OVERRIDE_VOICE"] ?? ($GLOBALS["TTS"]["MIMIC3"]["voice"] ?? 'en_UK/apope_low');
    $voiceP="&voice=".urlencode($voice);

    $noiseScaleP="&noiseScale=0.667";
    $noiseW="&noiseW=0.8";
    $lengthScaleP="&lengthScale=".urlencode($GLOBALS["TTS"]["MIMIC3"]["rate"] ?? '1');
    $restP="&ssml=false&audioTarget=client";
    $result = synth_speech_fetch($ttsServiceUri.$testP.$voiceP.$noiseScaleP.$noiseW.$lengthScaleP.$restP);

    if (!$result) {
        file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString));
        return false;
    } else {
    }


    $target=dirname(SYNTH_TTS_SCRIPT_DIR).'/soundcache/'.md5(trim($stringforhash)).'.wav';
    if (!synth_publish_speech_cache($result,$target)) return false;
    $size=strlen($result);

    $end = microtime(true);

    $executionTime = ($end - $start);

    file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rsize of wav ($size)\n\rexecution time: $executionTime secs  function tts($textString,$mood=\"cheerful\",$stringforhash)");

    return "soundcache/" . md5(trim($stringforhash)) . ".wav";


        }
    }
}
