<?php

// A local worker accepts one durable job ID; no HTTP dispatch, request body or provider override.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !preg_match('/^[1-9][0-9]{0,17}$/D',$argv[1])) {
    fwrite(STDERR,"Usage: php service/memory_worker.php <job-id>\n");
    exit(2);
}
$enginePath = dirname(__DIR__).DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_ROOT']=$enginePath;
require_once $enginePath.'lib/runtime_bootstrap.php';
require_once $enginePath.'lib/synth_memory_worker.php';
try {
    synthRuntimeBootstrap($enginePath,['load_general_settings'=>true,'load_stt_connector'=>false]);
    $result = synth_memory_run_persisted_job($GLOBALS['db'],intval($argv[1]));
    echo $result === null ? "Native memory job unavailable.\n" : "Native memory job completed.\n";
} catch (SYNTHProtocolCancelled $error) {
    echo "Native memory job cancelled.\n";
} catch (Throwable $error) {
    // Provider bodies, prompts, URLs and credentials must not enter scheduler output.
    fwrite(STDERR,"Native memory job failed.\n");
    exit(1);
}
