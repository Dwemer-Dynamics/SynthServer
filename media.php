<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'media_store.php';

function synth_media_error(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'error' => ['code' => $code, 'message' => $message, 'retryable' => false],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

try {
    $method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'PUT'], true)) {
        header('Allow: GET, HEAD, PUT');
        throw new SynthMediaException(405, 'method_not_allowed', 'Media supports GET, HEAD and PUT only.');
    }

    $id = synth_media_validate_id(ltrim(rawurldecode(strval($_SERVER['PATH_INFO'] ?? '')), '/'));
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
    synthRuntimeBootstrap(__DIR__, ['run_db_updates' => false]);
    $db = $GLOBALS['db'] ?? null;

    if ($method === 'PUT') {
        $contentType = strtolower(trim(explode(';', strval($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if (!in_array($contentType, SYNTH_MEDIA_CONTENT_TYPES, true)) {
            throw new SynthMediaException(415, 'unsupported_media_type', 'Unsupported native media content type.');
        }
        $declared = strval($_SERVER['CONTENT_LENGTH'] ?? '');
        if ($declared !== '' && (!ctype_digit($declared) || intval($declared) > SYNTH_MEDIA_MAX_BYTES)) {
            throw new SynthMediaException(413, 'payload_too_large', 'Media exceeds the configured limit.');
        }
        $bytes = file_get_contents('php://input', false, null, 0, SYNTH_MEDIA_MAX_BYTES + 1);
        if (!is_string($bytes) || $bytes === '') {
            throw new SynthMediaException(400, 'empty_media', 'Media body is empty.');
        }
        if (strlen($bytes) > SYNTH_MEDIA_MAX_BYTES) {
            throw new SynthMediaException(413, 'payload_too_large', 'Media exceeds the configured limit.');
        }
        if ($declared !== '' && intval($declared) !== strlen($bytes)) {
            throw new SynthMediaException(400, 'content_length_mismatch', 'Media body length does not match Content-Length.');
        }
        if (!hash_equals(substr($id, 6), hash('sha256', $bytes))) {
            throw new SynthMediaException(409, 'media_hash_mismatch', 'Media bytes do not match the opaque media ID.');
        }

        [$target] = synth_media_storage(__DIR__, $id, true);
        $replayed = is_file($target);
        $published = synth_media_publish_bytes(__DIR__, $bytes, $contentType);
        synth_media_register($db, $published);
        http_response_code($replayed ? 200 : 201);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'schema' => 'synth.media.v1',
            'media_id' => $published['media_id'],
            'content_type' => $published['content_type'],
            'sha256' => $published['sha256'],
            'size' => $published['size'],
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }

    $media = synth_media_resolve(__DIR__, $id);
    synth_media_require_ready($db, $media);
    header('Content-Type: ' . $media['content_type']);
    header('Content-Length: ' . $media['size']);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: "' . $media['sha256'] . '"');
    header('X-Content-Type-Options: nosniff');
    if ($method === 'GET') {
        readfile($media['path']);
    }
} catch (SynthMediaException $e) {
    synth_media_error($e->httpStatus, $e->errorCode, $e->getMessage());
} catch (Throwable $e) {
    synth_media_error(500, 'media_internal_error', 'Media request failed.');
}
