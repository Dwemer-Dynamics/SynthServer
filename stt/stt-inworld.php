<?php

// Thin hook: resolves model, language and key, then the SttWrapper "inworld" driver does the call.
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);

function stt($filePath)
{
    if (!isset($GLOBALS["db"]) || !\Synth\Bootstrap::db()) {
        $GLOBALS["db"] = new sql();
    }

    $cfg = \Synth\Bootstrap::config("STT")["INWORLD"] ?? [];

    // Resolve API key: prefer STT conf, fallback to API Badge "Inworld"
    $apiKey = trim((string)($cfg["API_KEY"] ?? ''));
    if ($apiKey === '') {
        try {
            require_once __DIR__ . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
            $row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel('inworld');
            if (is_array($row) && !empty($row['api_key'])) {
                $apiKey = trim((string)$row['api_key']);
            }
        } catch (Throwable $_e) {}
    }

    return \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('inworld', [
        'api_key' => $apiKey,
        'model' => (string)($cfg["MODEL_ID"] ?? 'groq/whisper-large-v3'),
        'language' => (string)($cfg["LANGUAGE"] ?? 'en-US'),
    ], new \Synth\Drivers\Stt\SttRequest($filePath));
}
