<?php

// Run in a fresh process: a fork of the legacy manager must not share its PostgreSQL socket.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 1) { fwrite(STDERR,"Usage: php service/memory_dispatch.php\n"); exit(2); }
$enginePath = dirname(__DIR__).DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_ROOT']=$enginePath;
require_once $enginePath.'lib/runtime_bootstrap.php';
require_once $enginePath.'lib/synth_memory_dispatch.php';
try {
    synthRuntimeBootstrap($enginePath,['load_general_settings'=>true,'load_stt_connector'=>false]);
    $result = synth_memory_dispatch($GLOBALS['db']);
    if ($result !== null) echo "Native memory dispatch completed.\n";
} catch (SYNTHProtocolCancelled $error) {
    echo "Native memory dispatch cancelled.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Native memory dispatch failed.\n");
    exit(1);
}
