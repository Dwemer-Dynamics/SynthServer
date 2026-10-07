<?php

if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
if ($argc>2 || ($argc===2 && $argv[1]!=='--loop')) { fwrite(STDERR,"Usage: php service/tts_dispatch.php [--loop]\n");exit(2); }
$root=dirname(__DIR__);
require_once $root.'/lib/runtime_bootstrap.php';
require_once $root.'/lib/synth_tts_worker.php';
$running=true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,static function() use(&$running): void {$running=false;});
    pcntl_signal(SIGINT,static function() use(&$running): void {$running=false;});
}
try {
    synthRuntimeBootstrap($root,['load_general_settings'=>false,'load_stt_connector'=>false,'run_db_updates'=>false]);
    $path=synth_tts_job_input_path($root,str_repeat('0',32),true).'.dispatch-lock';
    // Recovery workers must not inherit and retain their dispatcher's single-instance lock.
    $lock=fopen($path,PHP_OS_FAMILY==='Windows'?'c':'ce');
    if ($lock===false || !chmod($path,0600)) throw new RuntimeException('Speech dispatch lock unavailable');
    if (!flock($lock,LOCK_EX|LOCK_NB)) exit(0);
    do {
        foreach(synth_tts_due_jobs($GLOBALS['db']) as $id) synth_tts_launch_job($id,$root);
        synth_tts_cleanup_job_inputs($GLOBALS['db'],$root);
        if ($argc===1) break;
        usleep(500000);
    } while ($running);
} catch (Throwable) {
    error_log('[SYNTH TTS] Durable speech dispatcher stopped');
    exit(1);
}
