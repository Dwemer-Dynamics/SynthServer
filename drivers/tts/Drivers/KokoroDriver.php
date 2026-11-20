<?php

// Kokoro speech provider (TTS key "kokoro"). Moved from tts/tts-kokoro.php, which now only registers this driver.
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

    final class KokoroDriver implements TtsProviderInterface
    {
        public const KEY = 'kokoro';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {

		//xtts_fastapi_settings([]); //Check this
		
		/*if (!isset($GLOBALS["AVOID_TTS_CACHE"]))
			if (file_exists(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
				return SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
		*/
		
		
		$newString=$textString;
		
	    $starTime = microtime(true);

		$url = \Synth\Bootstrap::config("TTS")["KOKORO"]["endpoint"]."/v1/audio/speech";

		// Request headers
		$headers = array(
			'Accept: audio/wav',
			'Content-Type: application/json'
		);
		
	
		$voice=isset($GLOBALS["TTS"]["FORCED_VOICE_DEV"])?\Synth\Bootstrap::config("TTS")["FORCED_VOICE_DEV"]:\Synth\Bootstrap::config("TTS")["KOKORO"]["voiceid"];
		
		if (empty($voice))
			$voice=\Synth\Bootstrap::config("TTS")["KOKORO"]["voiceid"];
	
		if (isset($GLOBALS["PATCH_OVERRIDE_VOICE"]))
			$voice=\Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE");

		$data = array(
            'model'=>'kokoro',
			'input' => $newString,
			'voice' => $voice,
			'response_format' => 'wav',
            'speed' => \Synth\Bootstrap::config("TTS")["KOKORO"]["speed"],
            'stream'=>true,
			"lang_code"=>"a" // wtf?


		);
		$options = array(
			'http' => array(
				'header' => "Content-type: application/json\r\n" .
							"Accept: application/json\r\n",
				'method' => 'POST',
				'content' => json_encode($data)
			)
		);
		$context = stream_context_create($options);
		$response = synth_speech_fetch($url, $context, $http_response_header);


		if (is_array(\Synth\Bootstrap::config("TTS_FFMPEG_FILTERS"))) {
			$GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"]="adelay=150|150";
			$FFMPEG_FILTER='-af "'.implode(",",\Synth\Bootstrap::config("TTS_FFMPEG_FILTERS")).'"';
			
		} else {
			$FFMPEG_FILTER='-filter:a "adelay=150|150"';
		}
		
		// Handle the response
		if ($response !== false ) {
			// Handle the successful response
			$size=strlen($response);
			$fname=SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
			
			$startTimeTrans = microtime(true);
			$filter=!empty($GLOBALS['TTS_FFMPEG_FILTERS']) && is_array(\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS'))
			    ? implode(',',\Synth\Bootstrap::config('TTS_FFMPEG_FILTERS')) : 'adelay=150|150';
			if (!synth_transcode_speech_cache($response,$fname,$filter)) {
			    Logger::error('kokoro audio conversion or owned cache publication failed');
			    return false;
			}
			$endTimeTrans = microtime(true)-$startTimeTrans;
			
            file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
			$GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in kokoro call";
			return "soundcache/" . md5(trim($stringforhash)) . ".wav";
			
		} else {
			$textString.=print_r($http_response_header,true);
			file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString)."\n".json_encode($data));
            return false;
			
		}

        }
    }
}
