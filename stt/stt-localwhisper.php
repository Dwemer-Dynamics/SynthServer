<?php

// Thin hook: reads the LocalWhisper settings, then the SttWrapper "localwhisper" driver does the call and
// reports its diagnostic, which the speech pipeline reads from $GLOBALS['STT_LAST_DIAGNOSTIC'].
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);

function stt($file)
{
    $GLOBALS['STT_LAST_DIAGNOSTIC'] = [];
    $diagnostic = [];
    $text = \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('localwhisper', [
        'url' => strval($GLOBALS["STT"]["LOCALWHISPER"]["URL"] ?? ''),
        'form_field' => strval($GLOBALS["STT"]["LOCALWHISPER"]["FORMFIELD"] ?? 'file'),
        'timeout' => intval($GLOBALS["STT"]["LOCALWHISPER"]["TIMEOUT"] ?? 60),
    ], new \Synth\Drivers\Stt\SttRequest($file), $diagnostic);
    $GLOBALS['STT_LAST_DIAGNOSTIC'] = $diagnostic;
    return $text;
}
