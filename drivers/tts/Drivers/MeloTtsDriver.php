<?php

// MeloTts speech provider (TTS key "melotts"). Moved from tts/tts-melotts.php, which now only registers this driver.
// The provider logic is unchanged: it reads the connector's own TTS settings from the legacy globals and writes
// the speech cache exactly as before.

namespace {
    if (!defined('SYNTH_TTS_SCRIPT_DIR')) define('SYNTH_TTS_SCRIPT_DIR', dirname(__DIR__, 3) . '/tts');
}

namespace {

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/speech_cache.php';

require_once dirname(SYNTH_TTS_SCRIPT_DIR) . '/lib/provider_stream.php';

/*
curl -X 'POST' \
  'http://localhost:8084/tts' \
  -H 'accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
  "speaker": "maleadult02",
  "text": "In Fallout 4\'s land of snow and ice, Where dragons soar and souls entwine, Heroes rise, their fate unveiled, As ancient tales, the land does bind.",
  "speed": 1,
  "language": "EN"
}'
*/

$melotts_pronunciation_file = SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR ."tts-melotts_pronunciation.php";
if (file_exists($melotts_pronunciation_file)) {
    include_once($melotts_pronunciation_file);
    //error_log("melotts pronunciation adjustment file found: " . $melotts_pronunciation_file);
}

}

namespace Synth\Drivers\Tts\Drivers {


    use Logger;

    use Synth\Drivers\Tts\TtsProviderInterface;

    final class MeloTtsDriver implements TtsProviderInterface
    {
        public const KEY = 'melotts';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {

    $newString=$textString;

    $starTime = microtime(true);


    $lang=isset(\Synth\Bootstrap::config('TTS')["FORCED_LANG_DEV"])?\Synth\Bootstrap::config("TTS")["FORCED_LANG_DEV"]:(\Synth\Bootstrap::config("TTS")["MELOTTS"]["language"] ?? 'EN');


    if ((\Synth\Bootstrap::has('LLM_LANG'))&&(\Synth\Bootstrap::has('LANG_LLM_XTTS'))&&\Synth\Bootstrap::config("LANG_LLM_XTTS")) {
        $lang=\Synth\Bootstrap::config("LLM_LANG");

    }

    if (\Synth\Bootstrap::has('PATCH_OVERRIDE_TTS_LANGUAGE'))
        $lang=\Synth\Bootstrap::config("PATCH_OVERRIDE_TTS_LANGUAGE");

    if (empty($lang))
        $lang=\Synth\Bootstrap::config("TTS")["MELOTTS"]["language"] ?? 'EN';




    $voice = \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE")
        ?? \Synth\Bootstrap::config("TTS")["FORCED_VOICE_DEV"]
        ?? (\Synth\Bootstrap::config("TTS")["MELOTTS"]["voiceid"] ?? '');


    if (empty($voice)) {

        $codename=strtr(strtolower(trim(\Synth\Bootstrap::config("SYNTH_NAME"))),[" "=>"_","'"=>"+"]);
        $codename=preg_replace('/[^a-zA-Z0-9_+]/u', '', $codename);

        $cn=\Synth\Bootstrap::db()->escape("Voicetype/$codename");
        $vtype=\Synth\Bootstrap::db()->fetchAll("SELECT config_value AS value FROM public.core_config_setting WHERE scope = 'conf' AND config_key='$cn'");
        $voicetypeString=(isOk($vtype))?$vtype[0]["value"]:'';
        $voicetype=explode("\\",$voicetypeString);
        $voice=isset($voicetype[3]) ? strtolower($voicetype[3]) : '';
    }


    // Normalize special narrator voice id
    if (is_string($voice) && strtolower($voice) === 'thenarrator')
        $voice = 'maleadult02';


    $speed=floatval(\Synth\Bootstrap::config("TTS")["MELOTTS"]["speed"] ?? 1.0)+0.01;


    if (empty($voice))
        Logger::error("voiceid is no set");

    $cleanString = $textString;
    if (function_exists('adjust_pronunciation')) {
        if (function_exists('pronunciation_adjust_enabled'))
            $b_ok = pronunciation_adjust_enabled();
        else
            $b_ok = true;
        //error_log("melotts pronunciation_adjust_enabled: " . ($b_ok ? "YES" : "NO"));
        if ($b_ok)
            $cleanString = adjust_pronunciation($textString); // adjust English mispronunciations.
    } else Logger::warn("melotts info: pronunciation adjustments NOT defined.");

    $finalData =["speaker"=>"$voice","text"=>"$cleanString","language"=>$lang,"speed"=>$speed];
    //print_r($finalData);

	$options = array(
		'http' => array(
			'header' => "Content-type: application/json\r\n" .
						"Accept: application/json\r\n",
			'method' => 'POST',
			'content' => json_encode($finalData)
		)
	);
	$context = stream_context_create($options);

    $url=(\Synth\Bootstrap::config('TTS')["MELOTTS"]["endpoint"] . "/tts");

    $result = synth_speech_fetch($url, $context, $http_response_header);

    if (is_array(\Synth\Bootstrap::config("TTS_FFMPEG_FILTERS"))) {
        \Synth\Bootstrap::setAt('TTS_FFMPEG_FILTERS', "adelay", "adelay=150|150");
        $FFMPEG_FILTER='-af "'.implode(",",\Synth\Bootstrap::config("TTS_FFMPEG_FILTERS")).'"';

    } else {

        $FFMPEG_FILTER='-filter:a "adelay=150|150"';
    }


    // Handle the response
    if ($result !== false ) {
        $response = $result;
        // Handle the successful response
        $size=strlen($response);
        $fname=SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";


        $startTimeTrans = microtime(true);
        $filter=!empty(\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS')) && is_array(\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS'))
            ? implode(',',\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS')) : 'adelay=150|150';
        if (!synth_transcode_speech_cache($response,$fname,$filter)) {
            Logger::error('melotts audio conversion or owned cache publication failed');
            return false;
        }
        $endTimeTrans = microtime(true)-$startTimeTrans;

        file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
        \Synth\Bootstrap::append('DEBUG_DATA', (microtime(true) - $starTime)." secs in melotts call");
        return "soundcache/" . md5(trim($stringforhash)) . ".wav";

    }


        }
    }
}
