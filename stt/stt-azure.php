<?php

// Thin hook: resolves region, language and key, then the SttWrapper "azure" driver does the call.
// API-DOC https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-speech-to-text-short
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);



function stt($file)
{
    global $localPath;

    // Resolve API key: prefer STT conf, fallback to API Badge 'Azure'
    $apiKey = trim(\Synth\Bootstrap::config("STT")["AZURE"]["API_KEY"] ?? '');
    if ($apiKey === '') {
        try {
            if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) require_once($localPath . "lib/{$GLOBALS["DBDRIVER"]}.class.php");
            if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) $GLOBALS["db"] = new sql();
            require_once __DIR__ . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
            $row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel('azure');
            if (is_array($row) && !empty($row['api_key'])) $apiKey = $row['api_key'];
        } catch (Throwable $_e) {}
    }

    return \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('azure', [
        'region' => strval(\Synth\Bootstrap::config("STT")["AZURE"]["region"] ?? 'eastus'),
        'language' => strval(\Synth\Bootstrap::config("STT")["AZURE"]["LANG"] ?? 'en-US'),
        'api_key' => $apiKey,
    ], new \Synth\Drivers\Stt\SttRequest($file));
}
