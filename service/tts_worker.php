<?php

if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
if ($argc!==2 || !preg_match('/^[1-9][0-9]{0,17}$/D',$argv[1])) { fwrite(STDERR,"Usage: php service/tts_worker.php <job-id>\n");exit(2); }
$root=dirname(__DIR__);
require_once $root.'/lib/runtime_bootstrap.php';
require_once $root.'/lib/synth_tts_worker.php';
try {
    synthRuntimeBootstrap($root,['load_general_settings'=>true,'load_stt_connector'=>false,'load_tts_connector'=>true,
        'load_narrator'=>false,'run_db_updates'=>false]);
    synth_tts_run_job($GLOBALS['db'],intval($argv[1]),$root,static fn(array $entry): bool=>synth_tts_generate_job_audio($entry,$root));
} catch (SYNTHProtocolCancelled) {
    // An expired attempt or old owner cannot publish; durable state decides whether recovery is allowed.
} catch (Throwable) {
    error_log('[SYNTH TTS] Durable speech attempt did not complete');
    exit(1);
}
