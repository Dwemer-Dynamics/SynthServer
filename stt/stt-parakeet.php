<?php

// Thin hook: resolves the key and hint, then the SttWrapper "parakeet" driver does the call.
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
    \Synth\Bootstrap::set('db', new sql());

    $additionalKeywords = lastKeyWords(30, ["chat", "chatme", "Sole Survivor", "Commonwealth", "Fallout 4"]);

    // Resolve API key: prefer API Badge 'OpenAI', fallback to STT conf
    $apiKey = '';
    try {
        if (!\Synth\Bootstrap::has('db') || !\Synth\Bootstrap::db()) \Synth\Bootstrap::set('db', new sql());
        require_once __DIR__ . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
        $row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel('openai');
        if (is_array($row) && !empty($row['api_key'])) $apiKey = trim($row['api_key']);
    } catch (Throwable $_e) {}
    if ($apiKey === '') {
        $apiKey = trim(\Synth\Bootstrap::config("STT")["PARAKEET"]["API_KEY"] ?? '');
    }

    return \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('parakeet', [
        'api_key' => $apiKey,
        'language' => \Synth\Bootstrap::config("STT")["PARAKEET"]["LANG"] ?? "en",
    ], new \Synth\Drivers\Stt\SttRequest(
        $file,
        [],
        (\Synth\Bootstrap::config('SYNTH_NAME') . ",Sole Survivor,Commonwealth,Fallout 4,$additionalKeywords")
    ));
}
