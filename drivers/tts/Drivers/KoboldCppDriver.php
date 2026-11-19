<?php

// KoboldCpp speech provider (TTS key "koboldcpp"). Moved from tts/tts-koboldcpp.php, which now only registers this driver.
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

    require_once dirname(__DIR__) . '/TtsProviderInterface.php';

    use Synth\Drivers\Tts\TtsProviderInterface;

    final class KoboldCppDriver implements TtsProviderInterface
    {
        public const KEY = 'koboldcpp';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {

    // Cache
		if (!isset($GLOBALS["AVOID_TTS_CACHE"]))
			if (file_exists(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
				return SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";

		$url = $GLOBALS["TTS"]["koboldcpp"]["endpoint"]; //http://127.0.0.1:5001/api/extra/tts

		// Request headers
		$headers = array(
			"accept: audio/wav",
			'Content-Type: application/json'
		);


		// Request data
		$data = array(
			'input' => "$textString",
			'voice' => $GLOBALS["TTS"]["koboldcpp"]["voice"],
			);


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
			$wavName=SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
			if (!synth_publish_speech_cache($response,$wavName)) return false;
			return "soundcache/" . md5(trim($stringforhash)) . ".wav";
		} else {
			$textString.=print_r($http_response_header,true);
			file_put_contents(SYNTH_TTS_SCRIPT_DIR . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString));
            return false;

		}

        }
    }
}
