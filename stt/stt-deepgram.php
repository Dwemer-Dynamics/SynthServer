<?php

// Thin hook: resolves model, language and key, then the SttWrapper "deepgram" driver does the call.
// API-DOC https://developers.deepgram.com/docs/getting-started-with-pre-recorded-audio
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname(__FILE__) . '/../';
require_once($localPath . "lib/runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);
require_once($localPath . "lib/chat_helper_functions.php");


function stt($filePath)
{

    if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db())
        $GLOBALS["db"] = new sql();

    // Resolve API key: prefer STT conf, fallback to API Badge 'Deepgram'
    $apiKey = trim(\Synth\Bootstrap::config('STT')['DEEPGRAM']['API_KEY'] ?? '');
    if ($apiKey === '') {
        try {
            require_once __DIR__ . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
            $row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel('deepgram');
            if (is_array($row) && !empty($row['api_key'])) $apiKey = $row['api_key'];
        } catch (Throwable $_e) {}
    }

    return \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('deepgram', [
        'model' => \Synth\Bootstrap::config("STT")["DEEPGRAM"]["MODEL"] ?? "none",
        'language' => \Synth\Bootstrap::config("STT")["DEEPGRAM"]["LANG"] ?? "en",
        'api_key' => $apiKey,
    ], new \Synth\Drivers\Stt\SttRequest($filePath));
}
