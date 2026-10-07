<?php

require_once dirname(__DIR__) . '/lib/speech_cache.php';

require_once dirname(__DIR__) . '/lib/provider_stream.php';



$GLOBALS['SYNTH_TTS_DRIVERS']['kokoro'] = $GLOBALS["TTS_IN_USE"]=function($textString, $mood , $stringforhash) {

		//xtts_fastapi_settings([]); //Check this
		
		/*if (!isset($GLOBALS["AVOID_TTS_CACHE"]))
			if (file_exists(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
				return dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
		*/
		
		
		$newString=$textString;
		
	    $starTime = microtime(true);

		$url = $GLOBALS["TTS"]["KOKORO"]["endpoint"]."/v1/audio/speech";

		// Request headers
		$headers = array(
			'Accept: audio/wav',
			'Content-Type: application/json'
		);
		
	
		$voice=isset($GLOBALS["TTS"]["FORCED_VOICE_DEV"])?$GLOBALS["TTS"]["FORCED_VOICE_DEV"]:$GLOBALS["TTS"]["KOKORO"]["voiceid"];
		
		if (empty($voice))
			$voice=$GLOBALS["TTS"]["KOKORO"]["voiceid"];
	
		if (isset($GLOBALS["PATCH_OVERRIDE_VOICE"]))
			$voice=$GLOBALS["PATCH_OVERRIDE_VOICE"];

		$data = array(
            'model'=>'kokoro',
			'input' => $newString,
			'voice' => $voice,
			'response_format' => 'wav',
            'speed' => $GLOBALS["TTS"]["KOKORO"]["speed"],
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


		if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
			$GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"]="adelay=150|150";
			$FFMPEG_FILTER='-af "'.implode(",",$GLOBALS["TTS_FFMPEG_FILTERS"]).'"';
			
		} else {
			$FFMPEG_FILTER='-filter:a "adelay=150|150"';
		}
		
		// Handle the response
		if ($response !== false ) {
			// Handle the successful response
			$size=strlen($response);
			$fname=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
			
			$startTimeTrans = microtime(true);
			$filter=!empty($GLOBALS['TTS_FFMPEG_FILTERS']) && is_array($GLOBALS['TTS_FFMPEG_FILTERS'])
			    ? implode(',',$GLOBALS['TTS_FFMPEG_FILTERS']) : 'adelay=150|150';
			if (!synth_transcode_speech_cache($response,$fname,$filter)) {
			    Logger::error('kokoro audio conversion or owned cache publication failed');
			    return false;
			}
			$endTimeTrans = microtime(true)-$startTimeTrans;
			
            file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
			$GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in kokoro call";
			return "soundcache/" . md5(trim($stringforhash)) . ".wav";
			
		} else {
			$textString.=print_r($http_response_header,true);
			file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString)."\n".json_encode($data));
            return false;
			
		}

};

/*
$GLOBALS["TTS"]["XTTSFASTAPI"]["endpoint"]='http://localhost:8020';
$GLOBALS["TTS"]["XTTSFASTAPI"]["voiceid"]='svenja';
$GLOBALS["TTS"]["XTTSFASTAPI"]["language"]='en';

$textTosay="Hello fellows...this is a new text to speech connector";

echo tts($textTosay,'',$textTosay).PHP_EOL;
*/
