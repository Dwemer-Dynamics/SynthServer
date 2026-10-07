<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_store.php';
require_once __DIR__ . '/synth_protocol_state.php';

function synth_npc_tts_status_key(string $cacheKey): string
{
    $cacheKey = strtolower(trim($cacheKey));
    return preg_match('/^[a-f0-9]{32}$/', $cacheKey) === 1 ? $cacheKey : '';
}

function synth_npc_tts_status_dir(?string $root = null): string
{
    $root = $root ?: dirname(__DIR__);
    return rtrim($root, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'tmp'
        . DIRECTORY_SEPARATOR . 'npc_tts_status';
}

function synth_write_npc_tts_status(
    string $cacheKey,
    string $status,
    array $metadata = [],
    ?string $root = null
): bool {
    $cacheKey = synth_npc_tts_status_key($cacheKey);
    $status = strtolower(trim($status));
    if ($cacheKey === '' || !in_array($status, ['pending', 'ready', 'failed'], true)) {
        return false;
    }
    try { synth_protocol_assert_request_active(); }
    catch (SYNTHProtocolCancelled) { return false; }

    $directory = synth_npc_tts_status_dir($root);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $payload = array_merge($metadata, [
        'schema' => 'synth.tts_status.v1',
        'cache_key' => $cacheKey,
        'status' => $status,
        'updated_at' => time(),
    ]);
    $target = $directory . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || @file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }

    $publish=static function() use($temporary,$target): bool {
        synth_protocol_assert_request_active();
        @chmod($temporary,0664);
        return @rename($temporary,$target);
    };
    try {
        if (!empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
            && ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2) {
            $db=$GLOBALS['db'] ?? null;
            if (!is_object($db) || !method_exists($db,'withNativeRequestLock')) return false;
            return $db->withNativeRequestLock($publish,2000);
        }
        return $publish();
    } catch (SYNTHProtocolCancelled) {
        return false;
    } catch (Throwable) {
        error_log('[SYNTH TTS] Status publication failed');
        return false;
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

function synth_read_npc_tts_status(string $cacheKey, ?string $root = null): array
{
    $cacheKey = synth_npc_tts_status_key($cacheKey);
    $root = $root ?: dirname(__DIR__);
    if ($cacheKey === '') {
        return [
            'schema' => 'synth.tts_status.v1',
            'cache_key' => '',
            'status' => 'invalid',
        ];
    }

    $path = synth_npc_tts_status_dir($root) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    if (!is_file($path)) {
        return [
            'schema' => 'synth.tts_status.v1',
            'cache_key' => $cacheKey,
            'status' => 'unknown',
        ];
    }

    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [
            'schema' => 'synth.tts_status.v1',
            'cache_key' => $cacheKey,
            'status' => 'unknown',
        ];
    }

    $status = strtolower(trim(strval($decoded['status'] ?? 'unknown')));
    if (!in_array($status, ['pending', 'ready', 'failed'], true)) {
        $status = 'unknown';
    }
    $result = [
        'schema' => 'synth.tts_status.v1',
        'cache_key' => $cacheKey,
        'status' => $status,
    ];
    if (isset($decoded['updated_at']) && is_numeric($decoded['updated_at'])) {
        $result['updated_at'] = intval($decoded['updated_at']);
    }
    // Internal only: HTTP and response encoders must resolve/remove this identifier before writing the wire.
    if ($status==='pending' && intval($decoded['job_id'] ?? 0)>0) $result['_job_id']=intval($decoded['job_id']);
    if ($status === 'failed') {
        $result['error_code'] = 'synthesis_failed';
    }
    if ($status==='ready') {
        // Polling may resolve existing immutable audio, but cannot publish or register it.
        $media=synth_resolve_npc_tts_media($cacheKey,$root);
        if ($media!==null) $result['media']=$media;
        else {
            $result['status']='failed';
            $result['error_code']='media_not_published';
        }
    }
    return $result;
}

// Resolve existing immutable audio; only the generation path opts into publication.
function synth_resolve_npc_tts_media(string $cacheKey, ?string $root = null, bool $publish = false): ?array
{
    $cacheKey = synth_npc_tts_status_key($cacheKey);
    $root = $root ?: dirname(__DIR__);
    if ($cacheKey === '') {
        return null;
    }
    $cachePath = rtrim($root, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'soundcache'
        . DIRECTORY_SEPARATOR . $cacheKey . '.wav';
    $size = is_file($cachePath) && !is_link($cachePath) ? @filesize($cachePath) : false;
    if (!is_int($size) || $size <= 44 || $size > SYNTH_MEDIA_MAX_BYTES) {
        return null;
    }
    $bytes = @file_get_contents($cachePath);
    if (!is_string($bytes) || strlen($bytes) !== $size) {
        return null;
    }
    try {
        $media = $publish ? synth_media_publish_bytes($root, $bytes, 'audio/wav')
            : synth_media_resolve($root,'media/'.hash('sha256',$bytes),['audio/wav']);
        return [
            'schema' => 'synth.media.v1',
            'media_id' => $media['media_id'],
            'content_type' => $media['content_type'],
            'sha256' => $media['sha256'],
            'size' => $media['size'],
        ];
    } catch (Throwable) {
        return null;
    }
}

// Publishes and registers ready TTS audio so status=ready always names deliverable media.
function synth_register_npc_tts_media(
    string $cacheKey,
    $db,
    array $correlation = [],
    ?string $root = null,
    array $statusMetadata = []
): ?array {
    synth_protocol_assert_request_active();
    $media = synth_resolve_npc_tts_media($cacheKey, $root, true);
    if ($media === null) {
        return null;
    }
    try {
        $register=static function() use($db,$media,$correlation,$cacheKey,$root,$statusMetadata): array {
            synth_protocol_assert_request_active();
            synth_media_register($db, $media, $correlation);
            $metadata=array_intersect_key(array_merge($correlation,$statusMetadata),array_flip([
                'request_id','turn_id','utterance_id','generation','speaker_identity_id','speaker_identity','speaker','output','job_id',
            ]));
            if (!synth_write_npc_tts_status($cacheKey,'ready',$metadata,$root)) {
                throw new SynthMediaException(500,'tts_status_write_failed','Speech readiness could not be published.');
            }
            return $media;
        };
        if (!empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
            && ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2) {
            if (!is_object($db) || !method_exists($db,'withNativeRequestLock')) throw new LogicException('Speech registration requires its native owner lock');
            return $db->withNativeRequestLock($register,2000);
        }
        return $register();
    } catch (SynthMediaException) {
        return null;
    }
}

?>
