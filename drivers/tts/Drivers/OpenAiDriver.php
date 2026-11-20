<?php

// OpenAi speech provider (TTS key "openai"). Moved from tts/tts-openai.php, which now only registers this driver.
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


    use Synth\Drivers\Tts\TtsProviderInterface;

    final class OpenAiDriver implements TtsProviderInterface
    {
        public const KEY = 'openai';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {


		// Resolve API key: prefer API Badge 'OpenAI', fallback to TTS schema key
		$apiKey = '';
		try {
			if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) require_once(SYNTH_TTS_SCRIPT_DIR . "/../lib/{$GLOBALS["DBDRIVER"]}.class.php");
			if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) $GLOBALS["db"] = new sql();
			require_once SYNTH_TTS_SCRIPT_DIR . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
			$row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel('openai');
			if (is_array($row) && !empty($row['api_key'])) $apiKey = trim($row['api_key']);
		} catch (Throwable $_e) {}
		if ($apiKey === '') $apiKey = \Synth\Bootstrap::config("TTS")["openai"]["API_KEY"];

		// Cache
		if (!isset($GLOBALS["AVOID_TTS_CACHE"]))
			if (file_exists(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
				return SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";

	    $starTime = microtime(true);

		$url = \Synth\Bootstrap::config("TTS")["openai"]["endpoint"]; //"https://api.openai.com/v1/audio/speech";

	// Request headers
	$headers = array(
		"Authorization: Bearer $apiKey",
		'Content-Type: application/json'
	);

	// Determine voice to use
	$voice = \Synth\Bootstrap::config("TTS")["openai"]["voice"];
	if (isset($GLOBALS["PATCH_OVERRIDE_VOICE"]) && !empty($GLOBALS["PATCH_OVERRIDE_VOICE"])) {
		$voice = \Synth\Bootstrap::config("PATCH_OVERRIDE_VOICE");
	}

	// Request data
	$data = array(
		'input' => "$textString",
		'model' => \Synth\Bootstrap::config("TTS")["openai"]["model_id"],
		'voice' => $voice,
		'style' => \Synth\Bootstrap::config("TTS")["openai"]["style"]+0
		);

		// add instructions if using gpt-4o-mini-tts
		if (\Synth\Bootstrap::config("TTS")["openai"]["model_id"] == "gpt-4o-mini-tts") {
			if ($mood && $mood != "default") {
				$data["instructions"] = "Emotion: $mood\n";
			}

			if (isset($GLOBALS["TTS"]["openai"]["instructions"]) && \Synth\Bootstrap::config("TTS")["openai"]["instructions"]) {
				$data["instructions"] .= \Synth\Bootstrap::config("TTS")["openai"]["instructions"];
			}
		}

		// Create stream context options
		$options = array(
			'http' => array(
				'method' => 'POST',
				'header' => implode("\r\n", $headers),
				'content' => json_encode($data)
			)
		);

		;
		// Create stream context
		$context = stream_context_create($options);

		// Send the request
		$response = synth_speech_fetch($url, $context, $http_response_header);

		// Handle the response
		if ($response !== false ) {
			// Handle the successful response
			//require_once(SYNTH_TTS_SCRIPT_DIR.DIRECTORY_SEPARATOR."../lib/misc_utils_mp3riffer.php");
			$wavName=SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
			$startTimeTrans = microtime(true);
			if (!synth_transcode_speech_cache($response,$wavName,'speechnorm=e=6:r=0.0001:l=1')) return false;
			$endTimeTrans = microtime(true)-$startTimeTrans;
            //file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".mp3", trim($response));
			//$finalData=MP3toWav($response,strlen($response));
			//$size=strlen($finalData);
			//file_put_contents(
			//	SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"
			//	, $finalData); // Save the audio response to a file
			//

            file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\r\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
			$GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in openai tts call and $endTimeTrans microseconds in ffmpeg transcoding";
			return "soundcache/" . md5(trim($stringforhash)) . ".wav";

		} else {
			$textString.=print_r($http_response_header,true);
			file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString));
            return false;

		}

        }
    }
}
