<?php

// Thin hook: reads the LocalWhisper settings, then the SttWrapper "localwhisper" driver does the call and
// reports its diagnostic, which the speech pipeline reads from \Synth\Bootstrap::config('STT_LAST_DIAGNOSTIC').
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($localPath . "lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);

function stt($file)
{
    \Synth\Bootstrap::set('STT_LAST_DIAGNOSTIC', []);
    $diagnostic = [];
    $text = \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('localwhisper', [
        'url' => strval(\Synth\Bootstrap::config("STT")["LOCALWHISPER"]["URL"] ?? ''),
        'form_field' => strval(\Synth\Bootstrap::config("STT")["LOCALWHISPER"]["FORMFIELD"] ?? 'file'),
        'timeout' => intval(\Synth\Bootstrap::config("STT")["LOCALWHISPER"]["TIMEOUT"] ?? 60),
    ], new \Synth\Drivers\Stt\SttRequest($file), $diagnostic);
    \Synth\Bootstrap::set('STT_LAST_DIAGNOSTIC', $diagnostic);
    return $text;
}
