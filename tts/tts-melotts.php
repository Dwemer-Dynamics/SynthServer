<?php

require_once dirname(__DIR__) . '/lib/speech_cache.php';

require_once dirname(__DIR__) . '/lib/provider_stream.php';

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

$melotts_pronunciation_file = dirname(__FILE__) . DIRECTORY_SEPARATOR ."tts-melotts_pronunciation.php";
if (file_exists($melotts_pronunciation_file)) {
    include_once($melotts_pronunciation_file);
    //error_log("melotts pronunciation adjustment file found: " . $melotts_pronunciation_file);
}

$GLOBALS['SYNTH_TTS_DRIVERS']['melotts'] = $GLOBALS["TTS_IN_USE"]=function($textString, $mood , $stringforhash) {

    $newString=$textString;

    $starTime = microtime(true);


    $lang=isset($GLOBALS["TTS"]["FORCED_LANG_DEV"])?$GLOBALS["TTS"]["FORCED_LANG_DEV"]:($GLOBALS["TTS"]["MELOTTS"]["language"] ?? 'EN');


    if ((isset($GLOBALS["LLM_LANG"]))&&(isset($GLOBALS["LANG_LLM_XTTS"]))&&$GLOBALS["LANG_LLM_XTTS"]) {
        $lang=$GLOBALS["LLM_LANG"];

    }

    if (isset($GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"]))
        $lang=$GLOBALS["PATCH_OVERRIDE_TTS_LANGUAGE"];

    if (empty($lang))
        $lang=$GLOBALS["TTS"]["MELOTTS"]["language"] ?? 'EN';




    $voice = $GLOBALS["PATCH_OVERRIDE_VOICE"]
        ?? $GLOBALS["TTS"]["FORCED_VOICE_DEV"]
        ?? ($GLOBALS["TTS"]["MELOTTS"]["voiceid"] ?? '');


    if (empty($voice)) {

        $codename=strtr(strtolower(trim($GLOBALS["SYNTH_NAME"])),[" "=>"_","'"=>"+"]);
        $codename=preg_replace('/[^a-zA-Z0-9_+]/u', '', $codename);

        $cn=$GLOBALS["db"]->escape("Voicetype/$codename");
        $vtype=$GLOBALS["db"]->fetchAll("select value from conf_opts where id='$cn'");
        $voicetypeString=(isOk($vtype))?$vtype[0]["value"]:'';
        $voicetype=explode("\\",$voicetypeString);
        $voice=isset($voicetype[3]) ? strtolower($voicetype[3]) : '';
    }


    // Normalize special narrator voice id
    if (is_string($voice) && strtolower($voice) === 'thenarrator')
        $voice = 'maleadult02';


    $speed=floatval($GLOBALS["TTS"]["MELOTTS"]["speed"] ?? 1.0)+0.01;


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

    $url="{$GLOBALS["TTS"]["MELOTTS"]["endpoint"]}/tts";

    $result = synth_speech_fetch($url, $context, $http_response_header);

    if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
        $GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"]="adelay=150|150";
        $FFMPEG_FILTER='-af "'.implode(",",$GLOBALS["TTS_FFMPEG_FILTERS"]).'"';

    } else {

        $FFMPEG_FILTER='-filter:a "adelay=150|150"';
    }


    // Handle the response
    if ($result !== false ) {
        $response = $result;
        // Handle the successful response
        $size=strlen($response);
        $fname=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";


        $startTimeTrans = microtime(true);
        $filter=!empty($GLOBALS['TTS_FFMPEG_FILTERS']) && is_array($GLOBALS['TTS_FFMPEG_FILTERS'])
            ? implode(',',$GLOBALS['TTS_FFMPEG_FILTERS']) : 'adelay=150|150';
        if (!synth_transcode_speech_cache($response,$fname,$filter)) {
            Logger::error('melotts audio conversion or owned cache publication failed');
            return false;
        }
        $endTimeTrans = microtime(true)-$startTimeTrans;

        file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
        $GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in melotts call";
        return "soundcache/" . md5(trim($stringforhash)) . ".wav";

    }


};


/*
$GLOBALS["TTS"]["MELOTTS"]["endpoint"]='http://127.0.0.1:8084';
$GLOBALS["TTS"]["MELOTTS"]["voiceid"]='maleadult02';
$GLOBALS["TTS"]["MELOTTS"]["language"]='EN';
$GLOBALS["TTS"]["MELOTTS"]["speed"]=1;


$textTosay="In Fallout 4's land of snow and ice, Where dragons soar and souls entwine, Heroes rise, their fate unveiled, As ancient tales, the land does bind.";

echo tts($textTosay,'',$textTosay).PHP_EOL;
*/

?>
