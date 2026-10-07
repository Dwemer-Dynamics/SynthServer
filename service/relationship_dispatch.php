<?php

// Use a fresh CLI process so the manager cannot share its PostgreSQL socket or legacy request globals.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if ($argc!==1) {fwrite(STDERR,"Usage: php service/relationship_dispatch.php\n");exit(2);}
$enginePath=dirname(__DIR__).DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_ROOT']=$enginePath;
require_once $enginePath.'lib/runtime_bootstrap.php';
require_once $enginePath.'lib/synth_relationship_interaction_dispatch.php';
try {
    synthRuntimeBootstrap($enginePath,['load_general_settings'=>true,'load_stt_connector'=>false]);
} catch (Throwable $error) {
    fwrite(STDERR,"Native relationship dispatch failed.\n");exit(1);
}
// A failed evaluation lane must not prevent the independent lane from making progress.
$failed=false;
foreach ([static fn()=>synth_relationship_dispatch($GLOBALS['db'],null,true),
    static fn()=>synth_relationship_dispatch_interaction($GLOBALS['db'])] as $dispatch) {
    try {
        if ($dispatch()!==null) echo "Native relationship dispatch completed.\n";
    } catch (SYNTHProtocolCancelled $error) {
        echo "Native relationship dispatch cancelled.\n";
    } catch (Throwable $error) {
        fwrite(STDERR,"Native relationship dispatch failed.\n");$failed=true;
    }
}
exit($failed ? 1 : 0);
