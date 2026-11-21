<?php

if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
if ($argc!==2 || !preg_match('/^[1-9][0-9]{0,17}$/D',$argv[1])) { fwrite(STDERR,"Usage: php service/tts_worker.php <job-id>\n");exit(2); }
$root=dirname(__DIR__);
require_once $root.'/lib/runtime_bootstrap.php';
require_once $root.'/lib/synth_tts_worker.php';
// Stored speech state may have changed (ready or failed): hint a waiting plugin to look again. Best effort and
// silent; with the notification channel absent nothing happens and the plugin's normal poll finds the state.
$wakePlugin=static fn()=>\Synth\Managers\Notification\NotifyPublisher::publish('speech');
// The job's queue message (if its client uses the queue) is completed or cancelled once the job is final, then announced.
$settleMessage=static function() use($root,$argv): void {
    try {
        if (\Synth\Managers\MessageHandler\MessageHandler::enabled() && is_object(\Synth\Bootstrap::db() ?? null)) {
            \Synth\Managers\MessageHandler\SpeechMessages::settle(\Synth\Bootstrap::db(),intval($argv[1]),$root);
        }
    } catch (Throwable) {
        error_log('[SYNTH TTS] Unable to settle the queued speech message');
    }
};
try {
    synthRuntimeBootstrap($root,['load_general_settings'=>true,'load_stt_connector'=>false,'load_tts_connector'=>true,
        'load_narrator'=>false,'run_db_updates'=>false]);
    synth_tts_run_job(\Synth\Bootstrap::db(),intval($argv[1]),$root,static fn(array $entry): bool=>synth_tts_generate_job_audio($entry,$root));
} catch (SYNTHProtocolCancelled) {
    // An expired attempt or old owner cannot publish; durable state decides whether recovery is allowed.
    $settleMessage();
} catch (Throwable) {
    error_log('[SYNTH TTS] Durable speech attempt did not complete');
    $settleMessage();
    $wakePlugin();
    exit(1);
}
$settleMessage();
$wakePlugin();
