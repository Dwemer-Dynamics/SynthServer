<?php

require_once dirname(__DIR__) . '/lib/speech_cache.php';

require_once dirname(__DIR__) . '/lib/provider_stream.php';
$localPath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");
require_once($localPath . "lib".DIRECTORY_SEPARATOR."emote_moods.php");

$GLOBALS['SYNTH_TTS_DRIVERS']['azure'] = $GLOBALS["TTS_IN_USE"]=function($textString, $mood , $stringforhash)
{
    $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    // Loading an adapter must not change another speaker's settings; planned calls are already prepared.
    if (!isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])) {
        synthRuntimeBootstrapIfNeeded($localPath, [
            'load_general_settings' => true, 'load_stt_connector' => false, 'load_itt_connector' => false,
        ]);
        synthEnsureTtsConnectorGlobals('azure');
    }
    if (!isset($GLOBALS["AVOID_TTS_CACHE"]))
        if (file_exists(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav"))
            return dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";

    if (empty($mood))
        $mood="default";

    $region = $GLOBALS["TTS"]["AZURE"]["region"];
    $apiKey = trim($GLOBALS["TTS"]["AZURE"]["API_KEY"] ?? '');
    // Prefer API Badge 'Azure' if schema key is empty
    if ($apiKey === '' && !isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])) {
        try {
            if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) require_once($localPath . "lib/{$GLOBALS["DBDRIVER"]}.class.php");
            if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) $GLOBALS["db"] = new sql();
            $row = $GLOBALS["db"]->fetchOne("SELECT api_key FROM core_api_badge WHERE lower(label)='azure' LIMIT 1");
            if (is_array($row) && !empty($row['api_key'])) $apiKey = trim($row['api_key']);
        } catch (Throwable $_e) {}
    }

    if (empty(trim($mood)))
        $mood = "default";

    $mood = extractFirstEmoteMood($mood, "default");

    if ($GLOBALS["TTS"]["AZURE"]["validMoods"])
        $valid_tokens = $GLOBALS["TTS"]["AZURE"]["validMoods"];
    else
        $valid_tokens = array('angry', 'cheerful', 'assistant', 'calm', 'embarrassed', 'excited', 'lyrical', 'sad', 'shouting', 'whispering', 'terrified');


    if (in_array($mood, $valid_tokens))
        $validMood = $mood;
    else
        $validMood = "default";

    if ($validMood=="dazed")
        $OverWriteRate=0.7;

    if ($validMood=="angry") {
        if (!isset($GLOBALS["TEMP"]["VOLHACK"])) {
            $GLOBALS["TEMP"]["VOLHACK"]=$GLOBALS["TTS"]["AZURE"]["volume"];
        }
        $GLOBALS["TTS"]["AZURE"]["volume"]=$GLOBALS["TEMP"]["VOLHACK"]+20;

    } else {
        if (isset($GLOBALS["TEMP"]["VOLHACK"]))
            $GLOBALS["TTS"]["AZURE"]["volume"]=$GLOBALS["TEMP"]["VOLHACK"];
    }

    $starTime = microtime(true);

    // Azure supports the selected resource key directly; no shared cross-connector token cache is needed.
    $cacheUsed = 'subscription-key';
    if ($apiKey === '') {
        return false;
    } else {


        $ttsServiceUri = "https://" . $region . ".tts.speech.microsoft.com/cognitiveservices/v1";

        //$SsmlTemplate = "<speak version='1.0' xml:lang='en-us'><voice xml:lang='%s' xml:gender='%s' name='%s'>%s</voice></speak>";
        $doc = new DOMDocument();

        $root = $doc->createElement("speak");
        $root->setAttribute("version", "1.0");




        $root->setAttribute("xml:lang", "en-us");
        $root->setAttribute("xmlns:mstts", "https://www.w3.org/2001/mstts");


        $voice = $doc->createElement("voice");
        //$voice->setAttribute( "xml:lang" , "en-us" );
        //$voice->setAttribute("xml:gender", "Female");

        $voiceId=$GLOBALS["TTS"]["AZURE"]["voice"];

        if (isset($GLOBALS["PATCH_OVERRIDE_VOICE"]))
			$voiceId=$GLOBALS["PATCH_OVERRIDE_VOICE"];


            $pattern = '/^[a-z]{2}-[A-Z]{2}/'; // Matches language-region code at the start of the string
            if (preg_match($pattern, $voiceId, $matches)) {

            $language = strtolower($matches[0]);

            $root->setAttribute("xml:lang", $language);

        }

        $voice->setAttribute("name", $voiceId); // Read https://learn.microsoft.com/es-es/azure/cognitive-services/speech-service/language-support?tabs=tts

        $text = $doc->createTextNode($textString);


        $prosody = $doc->createElement("prosody");
        $prosody->setAttribute("rate", (isset($OverWriteRate))?$OverWriteRate:$GLOBALS["TTS"]["AZURE"]["rate"]); //https://learn.microsoft.com/en-us/azure/cognitive-services/speech-service/speech-synthesis-markup-voice#adjust-prosody
        $prosody->setAttribute("volume", $GLOBALS["TTS"]["AZURE"]["volume"]);
        if ($GLOBALS["TTS"]["AZURE"]["countour"])
            $prosody->setAttribute("contour", $GLOBALS["TTS"]["AZURE"]["countour"]);



        $prosody->appendChild($text);

        $style = $doc->createElement("mstts:express-as");
        if (isset($GLOBALS["TTS"]["AZURE"]["fixedMood"])&& (!empty($GLOBALS["TTS"]["AZURE"]["fixedMood"])))
            $style->setAttribute("style", $GLOBALS["TTS"]["AZURE"]["fixedMood"]); // not supported for all voices
        else
            $style->setAttribute("style", $validMood); // not supported for all voices

        $style->setAttribute("styledegree", "2"); // not supported for all voices
        //$style->setAttribute( "role" , "YoungAdultFemale" );  // not supported for all voices
        $style->appendChild($prosody);

        $voice->appendChild($style);
        $root->appendChild($voice);
        $doc->appendChild($root);
        $data = $doc->saveXML();

        //echo "tts post data: ". $data . "<br>";
        $data=str_replace("Fallout 4","<phoneme ph=\"ˈskaɪrɪm\">Fallout 4</phoneme>",$data); // Hack to correect Fallout 4 pronunciation in other langs

        $options = array(
            'http' => array(
                'header' => "Content-type: application/ssml+xml\r\n" .
                "X-Microsoft-OutputFormat: riff-24khz-16bit-mono-pcm\r\n" .
                "Ocp-Apim-Subscription-Key: " . $apiKey . "\r\n" .
                "X-Search-AppId: 07D3234E49CE426DAA29772419F436CA\r\n" .
                "X-Search-ClientID: 1ECFAE91408841A480F00935DC390960\r\n" .
                "User-Agent: TTSPHP\r\n" .
                "content-length: " . strlen($data) . "\r\n",
                'method' => 'POST',
                'content' => $data,
            ),
        );

        $context = stream_context_create($options);

        $starTime = microtime(true);

        // get the wave data
        $result = synth_speech_fetch($ttsServiceUri, $context, $http_response_header);
        if (!$result) {
            file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".err", trim($data));
            return false;
            //throw new Exception("Problem with $ttsServiceUri, $php_errormsg");
        } else {
            //echo "Wave data length: ". strlen($result);
        }
        //fwrite(STDOUT, $result);

        $GLOBALS["TTS_FFMPEG_FILTERS"]["loudnorm"]="loudnorm=I=-16:TP=-1.5:LRA=11";

        if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
			$GLOBALS["TTS_FFMPEG_FILTERS"]["adelay"]="adelay=150|150";
			$FFMPEG_FILTER='-af "'.implode(",",$GLOBALS["TTS_FFMPEG_FILTERS"]).'"';

		} else {
			$FFMPEG_FILTER='-filter:a "adelay=150|150"';
		}

        $fileName=dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".wav";
        $size=strlen($result);
        $filter=implode(',',$GLOBALS['TTS_FFMPEG_FILTERS']);
        if (!synth_transcode_speech_cache($result,$fileName,$filter)) return false;

        //file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR."soundcache/" . md5(trim($stringforhash)) . ".wav", $result);
        file_put_contents(dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "soundcache/" . md5(trim($stringforhash)) . ".txt", trim($data) . "\n\rCache:$cacheUsed\n\rtotal call time:" . (microtime(true) - $starTime) . " ms\n\rsize of wav ($size)\n\rfunction tts($textString,$mood / $validMood ,$stringforhash)");
        $GLOBALS["DEBUG_DATA"][]=(microtime(true) - $starTime)." secs in azure call ";

        return "soundcache/" . md5(trim($stringforhash)) . ".wav";
    }

};
