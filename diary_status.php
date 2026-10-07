<?php

ini_set('display_errors','0');
header('Content-Type: application/x-ndjson; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/lib/request.php';
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') synth_protocol_fail('method_not_allowed',405);
$normalized=synth_decode_event_from_request();
$event=$normalized['json'] ?? [];
if (($event['protocol_version'] ?? null)!==2 || ($event['type'] ?? '')!=='diary_request') {
    synth_protocol_fail('invalid_diary_status_owner',422);
}
try {
    require_once __DIR__.'/lib/runtime_bootstrap.php';
    synthRuntimeBootstrap(__DIR__.DIRECTORY_SEPARATOR,['run_db_updates'=>false]);
    require_once __DIR__.'/lib/synth_diary_status.php';
    // The read lock rechecks the original durable owner; no new request is claimed here.
    $GLOBALS['SYNTH_REQUEST_EVENT']=$normalized;
    $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
    echo synth_diary_request_status($GLOBALS['db']);
} catch (SYNTHProtocolCancelled|LogicException $error) {
    synth_protocol_fail('diary_owner_unavailable',409);
} catch (Throwable $error) {
    error_log('[SYNTH diary] Status read unavailable.');
    synth_reject_json_request(503,'Diary status temporarily unavailable',['code'=>'diary_status_unavailable','retryable'=>true]);
}
