<?php

require_once dirname(__DIR__) . '/lib/speech_cache.php';

require_once dirname(__DIR__) . '/lib/provider_stream.php';

$localPath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");

// Delay standalone runtime setup until invocation; loading all adapters must be side-effect free.
function mimic3_prepare_runtime(): void {
    if (isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])) return;
    synthRuntimeBootstrapIfNeeded(dirname(__DIR__), [
        'load_general_settings' => true, 'load_stt_connector' => false, 'load_itt_connector' => false,
    ]);
    synthEnsureTtsConnectorGlobals('mimic3');
}


$GLOBALS['SYNTH_TTS_DRIVERS']['mimic3'] = $GLOBALS["TTS_IN_USE"]=function($textString, $mood = "cheerful", $stringforhash='') {
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
        file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($textString));
        return false;
    } else {
    }


    $target=dirname(__DIR__).'/soundcache/'.md5(trim($stringforhash)).'.wav';
    if (!synth_publish_speech_cache($result,$target)) return false;
    $size=strlen($result);

    $end = microtime(true);

    $executionTime = ($end - $start);

    file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rsize of wav ($size)\n\rexecution time: $executionTime secs  function tts($textString,$mood=\"cheerful\",$stringforhash)");

    return "soundcache/" . md5(trim($stringforhash)) . ".wav";


};

function ttsMimicOld($textString, $mood = "cheerful", $stringforhash='')
{
    mimic3_prepare_runtime();

    $starTime = microtime(true);

    $ttsServiceUri = $GLOBALS["TTS"]["MIMIC3"]["URL"] . "/api/tts";

    $doc = new DOMDocument();

    $root = $doc->createElement("speak");

    $voice = $doc->createElement("voice");
    $voice->setAttribute("name", $GLOBALS["TTS"]["MIMIC3"]["voice"]);

    $text = $doc->createTextNode($textString);


    $prosody = $doc->createElement("prosody");
    $prosody->setAttribute("rate", $GLOBALS["TTS"]["MIMIC3"]["rate"]);
    $prosody->setAttribute("volume", $GLOBALS["TTS"]["MIMIC3"]["volume"]);

    $sentence = $doc->createElement("s");
    $sentence->appendChild($text);
    $prosody->appendChild($sentence);

    $voice->appendChild($prosody);
    $root->appendChild($voice);
    $doc->appendChild($root);
    $data = $doc->saveXML();

    $options = array(
        'http' => array(
            'header' => "Content-type: application/ssml+xml\r\n" .
            "User-Agent: TTSPHP\r\n" .
            "content-length: " . strlen($data) . "\r\n",
            'method' => 'POST',
            'content' => $data,
        ),
    );

    $context = stream_context_create($options);

    if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
        $GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"]="adelay=150|150";
        $FFMPEG_FILTER='-af "'.implode(",",$GLOBALS["TTS_FFMPEG_FILTERS"]).'"';

    } else {
        $FFMPEG_FILTER='-filter:a "adelay=150|150"';
    }


    // get the wave data
    $result = synth_speech_fetch($ttsServiceUri, $context, $http_response_header);
    if (!$result) {
        file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($data));
        return false;
    } else {
    }



    $size=strlen($result);
    $fname=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";

    $startTimeTrans = microtime(true);
    $filter=!empty($GLOBALS['TTS_FFMPEG_FILTERS']) && is_array($GLOBALS['TTS_FFMPEG_FILTERS'])
        ? implode(',',$GLOBALS['TTS_FFMPEG_FILTERS']) : 'adelay=150|150';
    if (!synth_transcode_speech_cache($result,$fname,$filter)) return false;
    $endTimeTrans = microtime(true)-$startTimeTrans;

    file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($textString) . "\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rffmpeg transcoding: $endTimeTrans secs\n\rsize of wav ($size)\n\rfunction tts($textString,$mood=\"cheerful\",$stringforhash)");
    $GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in xtts-fast-api call";
    return "soundcache/" . md5(trim($stringforhash)) . ".wav";

}
