<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_store.php';

// An accepted service response with no recognized text is a completed no-work request.
final class SYNTHSpeechNotRecognized extends RuntimeException {}

// Resolves an uploaded opaque media object and transcribes it through the configured STT connector.
function synth_protocol_transcribe_media(array $media): string
{
    $mediaId = strval($media['media_id'] ?? '');
    $sha256 = strval($media['sha256'] ?? '');
    $contentType = strval($media['content_type'] ?? '');
    $expectedSize = intval($media['size'] ?? 0);
    if ($mediaId !== 'media/' . $sha256 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
        throw new RuntimeException('invalid_media_reference');
    }
    $published = synth_media_resolve(dirname(__DIR__), $mediaId, ['audio/wav', 'audio/ogg']);
    if ($published['content_type'] !== $contentType
        || $published['size'] !== $expectedSize
        || !hash_equals($published['sha256'], $sha256)) {
        throw new RuntimeException('media_integrity_error');
    }
    $event = is_array($GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null)
        ? $GLOBALS['SYNTH_REQUEST_EVENT']['json']
        : [];
    synth_media_register($GLOBALS['db'] ?? null, $published, [
        'request_id' => $event['request_id'] ?? '',
        'turn_id' => $event['turn_id'] ?? '',
        'generation' => $event['generation'] ?? null,
    ]);
    synth_media_require_ready($GLOBALS['db'] ?? null, $published);

    $extension = $contentType === 'audio/ogg' ? '.ogg' : '.wav';
    $resolvedRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'soundcache');
    if ($resolvedRoot === false) {
        throw new RuntimeException('media_storage_unavailable');
    }
    $temporaryBase = tempnam($resolvedRoot, '_synth-stt-');
    if ($temporaryBase === false) {
        throw new RuntimeException('stt_temporary_file_failed');
    }
    $temporary = $temporaryBase . $extension;
    @unlink($temporaryBase);
    if (!copy($published['path'], $temporary)) {
        throw new RuntimeException('stt_temporary_file_failed');
    }
    try {
        $driver = strtolower(trim(strval($GLOBALS['STTFUNCTION'] ?? 'none')));
        $connector = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'stt' . DIRECTORY_SEPARATOR . 'stt-' . $driver . '.php';
        if (!is_file($connector)) {
            throw new RuntimeException('stt_connector_unavailable');
        }
        require_once $connector;
        if (!function_exists('stt')) {
            throw new RuntimeException('stt_connector_invalid');
        }
        // A previous connector/call cannot lend its successful-empty provenance to this one.
        $GLOBALS['STT_LAST_DIAGNOSTIC'] = [];
        $text = trim(strval(stt($temporary)));
        if ($text === '') {
            $diagnostic=$GLOBALS['STT_LAST_DIAGNOSTIC'] ?? [];
            if ($driver==='localwhisper' && ($diagnostic['driver'] ?? '')==='localwhisper') {
                $status=$diagnostic['http_status_code'] ?? 0;
                if (($diagnostic['stage'] ?? '')==='recognized_empty'
                    && is_int($status) && $status>=200 && $status<300
                    && in_array($diagnostic['response_key'] ?? null,['text','transcription'],true)
                    && ($diagnostic['response_value_type'] ?? '')==='string') {
                    throw new SYNTHSpeechNotRecognized('speech_not_recognized');
                }
                $code=$diagnostic['error_code'] ?? '';
                if (in_array($code,['stt_transport_failed','stt_http_error','stt_invalid_response'],true)) {
                    throw new RuntimeException($code);
                }
            }
            throw new RuntimeException('stt_empty_transcript');
        }
        if (strlen($text) > 4096) {
            throw new RuntimeException('stt_transcript_too_large');
        }
        return $text;
    } finally {
        @unlink($temporary);
    }
}
