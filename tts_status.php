<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'npc_tts_status.php';

$cacheKey = strval($_GET['cache_key'] ?? '');
$status = synth_read_npc_tts_status($cacheKey, __DIR__);
if (($status['status'] ?? '') === 'invalid') {
    http_response_code(400);
}
if (isset($status['_job_id']) || (($status['status'] ?? '') === 'ready' && is_array($status['media'] ?? null))) {
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
        synthRuntimeBootstrap(__DIR__, ['run_db_updates' => false]);
        $db = $GLOBALS['db'] ?? null;
        if (isset($status['_job_id'])) {
            require_once __DIR__.'/lib/synth_tts_worker.php';
            $status=synth_tts_resolve_job_status($db,$status);
        }
        if (($status['status'] ?? '')==='ready') {
            $media = $status['media'];
            synth_media_require_ready($db, [
                'media_id' => strval($media['media_id']),
                'content_type' => strval($media['content_type']),
                'sha256' => strval($media['sha256']),
                'size' => intval($media['size']),
            ]);
        }
    } catch (Throwable $error) {
        error_log('[SYNTH TTS] Ready media verification failed: ' . $error->getMessage());
        $status['status'] = 'failed';
        $status['error_code'] = 'media_registry_failed';
        unset($status['media']);
    }
}
unset($status['_job_id']);

echo json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

?>
