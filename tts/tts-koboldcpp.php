<?php

require_once dirname(__DIR__) . '/lib/speech_cache.php';

require_once dirname(__DIR__) . '/lib/provider_stream.php';


$GLOBALS['SYNTH_TTS_DRIVERS']['koboldcpp'] = $GLOBALS["TTS_IN_USE"]=function($textString, $mood , $stringforhash) {

    // Cache
		if (!isset($GLOBALS["AVOID_TTS_CACHE"]))
			if (file_exists(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
				return dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";

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
			$wavName=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
			if (!synth_publish_speech_cache($response,$wavName)) return false;
			return "soundcache/" . md5(trim($stringforhash)) . ".wav";
		} else {
			$textString.=print_r($http_response_header,true);
			file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString));
            return false;

		}

};
