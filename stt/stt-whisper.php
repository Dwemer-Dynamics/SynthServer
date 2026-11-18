<?php

// Thin hook: resolves the OpenAI key and hint, then the SttWrapper "whisper" driver does the call.
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib" .DIRECTORY_SEPARATOR."runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);
require_once($localPath . "lib" .DIRECTORY_SEPARATOR."chat_helper_functions.php");


function stt($file)
{
    $GLOBALS["db"] = new sql();

    $additionalKeywords = lastKeyWords(30, ["chat", "chatme", "Sole Survivor", "Commonwealth", "Fallout 4"]);

    // Resolve API key: prefer API Badge 'OpenAI', fallback to STT conf
    $apiKey = '';
    try {
        if (!isset($GLOBALS["db"]) || !$GLOBALS["db"]) $GLOBALS["db"] = new sql();
        require_once __DIR__ . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
        $row = (new \Synth\Core\ApiBadgeRepository($GLOBALS["db"]))->keyByLowerLabel('openai');
        if (is_array($row) && !empty($row['api_key'])) $apiKey = trim($row['api_key']);
    } catch (Throwable $_e) {}
    if ($apiKey === '') {
        $apiKey = trim($GLOBALS["STT"]["WHISPER"]["API_KEY"] ?? '');
    }

    return \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('whisper', [
        'api_key' => $apiKey,
        'language' => $GLOBALS["STT"]["WHISPER"]["LANG"] ?? "en",
    ], new \Synth\Drivers\Stt\SttRequest(
        $file,
        [],
        "{$GLOBALS["SYNTH_NAME"]},Sole Survivor,Commonwealth,Fallout 4,$additionalKeywords"
    ));
}
