<?php

// A local worker accepts one durable relationship job ID, never request/provider overrides from HTTP.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if ($argc!==2 || !preg_match('/^[1-9][0-9]{0,17}$/D',$argv[1])) {
    fwrite(STDERR,"Usage: php service/relationship_worker.php <job-id>\n");exit(2);
}
$enginePath=dirname(__DIR__).DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_ROOT']=$enginePath;
require_once $enginePath.'lib/runtime_bootstrap.php';
require_once $enginePath.'lib/synth_relationship_worker.php';
try {
    synthRuntimeBootstrap($enginePath,['load_general_settings'=>true,'load_stt_connector'=>false]);
    $result=synth_relationship_run_persisted_job($GLOBALS['db'],intval($argv[1]));
    echo $result===null ? "Native relationship job unavailable.\n" : "Native relationship job completed.\n";
} catch (SYNTHProtocolCancelled $error) {
    echo "Native relationship job cancelled.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Native relationship job failed.\n");exit(1);
}
