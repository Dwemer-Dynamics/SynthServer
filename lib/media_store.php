<?php

declare(strict_types=1);

if (!defined('SYNTH_MEDIA_MAX_BYTES')) {
    define('SYNTH_MEDIA_MAX_BYTES', 16777216);
}
if (!defined('SYNTH_MEDIA_CONTENT_TYPES')) {
    define('SYNTH_MEDIA_CONTENT_TYPES', [
        'audio/wav',
        'audio/ogg',
        'image/jpeg',
        'image/png',
        'image/webp',
    ]);
}

final class SynthMediaException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

// Validates a bounded RIFF/WAVE container before it enters the shared media store.
function synth_media_is_wave(string $bytes): bool
{
    $length = strlen($bytes);
    if ($length < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
        return false;
    }
    $riff = unpack('Vsize', substr($bytes, 4, 4));
    $riffEnd = intval($riff['size'] ?? 0) + 8;
    if ($riffEnd < 44 || $riffEnd > $length) {
        return false;
    }
    $format = null;
    $dataBytes = 0;
    for ($offset = 12; $offset + 8 <= $riffEnd;) {
        $chunk = substr($bytes, $offset, 4);
        $decoded = unpack('Vsize', substr($bytes, $offset + 4, 4));
        $chunkSize = intval($decoded['size'] ?? -1);
        $start = $offset + 8;
        if ($chunkSize < 0 || $chunkSize > $riffEnd - $start) {
            return false;
        }
        if ($chunk === 'fmt ' && $chunkSize >= 16) {
            $format = unpack('vtag/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits', substr($bytes, $start, 16));
        } elseif ($chunk === 'data') {
            $dataBytes = $chunkSize;
        }
        $offset = $start + $chunkSize + ($chunkSize & 1);
    }
    if (!is_array($format)
        || !in_array(intval($format['tag'] ?? 0), [1, 3, 65534], true)
        || intval($format['channels'] ?? 0) < 1
        || intval($format['sample_rate'] ?? 0) < 1
        || intval($format['block_align'] ?? 0) < 1
        || intval($format['bits'] ?? 0) < 1
        || $dataBytes < 1) {
        return false;
    }
    return $dataBytes % intval($format['block_align']) === 0;
}

// Confirms that the declared native content type matches the bounded media bytes.
function synth_media_validate_bytes(string $bytes, string $contentType): void
{
    $valid = match ($contentType) {
        'audio/wav' => synth_media_is_wave($bytes),
        'audio/ogg' => strlen($bytes) >= 4 && substr($bytes, 0, 4) === 'OggS',
        'image/jpeg', 'image/png', 'image/webp' => (static function () use ($bytes, $contentType): bool {
            if (!function_exists('getimagesizefromstring')) {
                return false;
            }
            $image = @getimagesizefromstring($bytes);
            return is_array($image) && strtolower(strval($image['mime'] ?? '')) === $contentType;
        })(),
        default => false,
    };
    if (!$valid) {
        throw new SynthMediaException(422, 'media_content_mismatch', 'Media bytes do not match the declared native content type.');
    }
}

// Validates an opaque content-addressed ID without accepting a filesystem path.
function synth_media_validate_id(string $id): string
{
    if (preg_match('#^media/[a-f0-9]{64}$#D', $id) !== 1) {
        throw new SynthMediaException(400, 'invalid_media_id', 'A valid opaque media ID is required.');
    }
    return $id;
}

// Resolves the fixed media directory and never incorporates client path segments.
function synth_media_storage(string $serverRoot, string $id, bool $create = false): array
{
    synth_media_validate_id($id);
    $root = rtrim($serverRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soundcache';
    $directory = $root . DIRECTORY_SEPARATOR . 'media';
    if ($create && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new SynthMediaException(500, 'media_storage_unavailable', 'Media storage is unavailable.');
    }
    if (!is_dir($directory) || is_link($directory)) {
        throw new SynthMediaException(500, 'media_storage_invalid', 'Media storage is invalid.');
    }
    $resolvedRoot = realpath($root);
    $resolvedDirectory = realpath($directory);
    if ($resolvedRoot === false || $resolvedDirectory === false || dirname($resolvedDirectory) !== $resolvedRoot) {
        throw new SynthMediaException(500, 'media_storage_invalid', 'Media storage escaped its configured root.');
    }
    $sha256 = substr($id, 6);
    return [$resolvedDirectory . DIRECTORY_SEPARATOR . $sha256, $sha256];
}

function synth_media_read_metadata(string $path, string $sha256, int $size): array
{
    $metadataPath = $path . '.json';
    if (!is_file($metadataPath) || is_link($metadataPath)) {
        throw new SynthMediaException(500, 'media_integrity_error', 'Media metadata is missing.');
    }
    $decoded = json_decode(strval(file_get_contents($metadataPath)), true);
    if (!is_array($decoded)
        || !in_array($decoded['content_type'] ?? '', SYNTH_MEDIA_CONTENT_TYPES, true)
        || !hash_equals($sha256, strval($decoded['sha256'] ?? ''))
        || intval($decoded['size'] ?? -1) !== $size) {
        throw new SynthMediaException(500, 'media_integrity_error', 'Media metadata failed verification.');
    }
    return $decoded;
}

// Resolves and verifies a published object before a connector or response consumes it.
function synth_media_resolve(string $serverRoot, string $id, array $allowedContentTypes = SYNTH_MEDIA_CONTENT_TYPES): array
{
    [$path, $sha256] = synth_media_storage($serverRoot, $id);
    if (!is_file($path) || is_link($path)) {
        throw new SynthMediaException(404, 'media_not_found', 'Media was not found.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size < 1 || $size > SYNTH_MEDIA_MAX_BYTES
        || !hash_equals($sha256, strval(hash_file('sha256', $path)))) {
        throw new SynthMediaException(500, 'media_integrity_error', 'Stored media failed verification.');
    }
    $metadata = synth_media_read_metadata($path, $sha256, $size);
    if (!in_array($metadata['content_type'], $allowedContentTypes, true)) {
        throw new SynthMediaException(415, 'unsupported_media_type', 'Media has the wrong native content type.');
    }
    return [
        'media_id' => $id,
        'path' => $path,
        'content_type' => $metadata['content_type'],
        'sha256' => $sha256,
        'size' => $size,
    ];
}

function synth_media_publish_file(string $target, string $bytes): void
{
    if (is_file($target)) {
        return;
    }
    $temporary = tempnam(dirname($target), '.synth-media-');
    if ($temporary === false) {
        throw new SynthMediaException(500, 'media_write_failed', 'Unable to create an atomic media write.');
    }
    try {
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new SynthMediaException(500, 'media_write_failed', 'Unable to open the atomic media write.');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new SynthMediaException(500, 'media_write_failed', 'Unable to write the complete media object.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new SynthMediaException(500, 'media_write_failed', 'Unable to synchronize the media object.');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($temporary, $target) && !is_file($target)) {
            throw new SynthMediaException(500, 'media_write_failed', 'Unable to publish the media object atomically.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function synth_media_publish_metadata(string $target, array $metadata): void
{
    $metadataPath = $target . '.json';
    $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (is_file($metadataPath)) {
        return;
    }
    $temporary = tempnam(dirname($target), '.synth-meta-');
    if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        throw new SynthMediaException(500, 'media_write_failed', 'Unable to persist media metadata.');
    }
    if (!@rename($temporary, $metadataPath) && !is_file($metadataPath)) {
        @unlink($temporary);
        throw new SynthMediaException(500, 'media_write_failed', 'Unable to publish media metadata atomically.');
    }
    @unlink($temporary);
}

// Publishes trusted server-generated bytes through the same immutable media store.
function synth_media_publish_bytes(string $serverRoot, string $bytes, string $contentType): array
{
    if ($bytes === '' || strlen($bytes) > SYNTH_MEDIA_MAX_BYTES) {
        throw new SynthMediaException(413, 'payload_too_large', 'Media exceeds the configured limit.');
    }
    if (!in_array($contentType, SYNTH_MEDIA_CONTENT_TYPES, true)) {
        throw new SynthMediaException(415, 'unsupported_media_type', 'Unsupported native media content type.');
    }
    synth_media_validate_bytes($bytes, $contentType);
    $sha256 = hash('sha256', $bytes);
    $id = 'media/' . $sha256;
    [$target] = synth_media_storage($serverRoot, $id, true);
    synth_media_publish_file($target, $bytes);
    if (filesize($target) !== strlen($bytes) || !hash_equals($sha256, strval(hash_file('sha256', $target)))) {
        throw new SynthMediaException(500, 'media_integrity_error', 'Published media failed verification.');
    }
    synth_media_publish_metadata($target, [
        'content_type' => $contentType,
        'sha256' => $sha256,
        'size' => strlen($bytes),
    ]);
    return synth_media_resolve($serverRoot, $id, [$contentType]);
}

// Mirrors a published object into PostgreSQL while keeping the storage key server-internal.
function synth_media_register($db, array $media, array $correlation = []): void
{
    if (!$db) {
        throw new SynthMediaException(503, 'media_database_unavailable', 'Media metadata database is unavailable.');
    }
    $textOrNull = static function ($value) use ($db): string {
        $value = trim(strval($value ?? ''));
        return $value === '' ? 'NULL' : $db->escapeLiteral($value);
    };
    $intOrNull = static function ($value): string {
        return is_numeric($value) && intval($value) > 0 ? strval(intval($value)) : 'NULL';
    };
    $mediaId = $db->escapeLiteral($media['media_id']);
    $contentType = $db->escapeLiteral($media['content_type']);
    $sha256 = $db->escapeLiteral($media['sha256']);
    $size = intval($media['size']);
    $result = $db->execQuery(
        "INSERT INTO synth_meta.media_objects AS existing
            (media_id, storage_key, content_type, byte_length, sha256, state, request_id,
             turn_id, utterance_id, generation, speaker_identity_id, published_at)
         VALUES ({$mediaId}, {$mediaId}, {$contentType}, {$size}, {$sha256}, 'ready', "
            . $textOrNull($correlation['request_id'] ?? '') . ', '
            . $textOrNull($correlation['turn_id'] ?? '') . ', '
            . $textOrNull($correlation['utterance_id'] ?? '') . ', '
            . $intOrNull($correlation['generation'] ?? null) . ', '
            . $intOrNull($correlation['speaker_identity_id'] ?? null) . ", NOW())
         ON CONFLICT (media_id) DO UPDATE SET
            content_type=EXCLUDED.content_type, byte_length=EXCLUDED.byte_length,
            sha256=EXCLUDED.sha256, state='ready', published_at=NOW(), failure_code=NULL,
            request_id=COALESCE(existing.request_id, EXCLUDED.request_id),
            turn_id=COALESCE(existing.turn_id, EXCLUDED.turn_id),
            utterance_id=COALESCE(existing.utterance_id, EXCLUDED.utterance_id),
            generation=COALESCE(existing.generation, EXCLUDED.generation),
            speaker_identity_id=COALESCE(
                existing.speaker_identity_id,
                EXCLUDED.speaker_identity_id
            )"
    );
    if ($result === false) {
        throw new SynthMediaException(500, 'media_database_failed', 'Media metadata could not be registered.');
    }
}

// Requires the database record to match the immutable object before HTTP delivery.
function synth_media_require_ready($db, array $media): void
{
    if (!$db) {
        throw new SynthMediaException(503, 'media_database_unavailable', 'Media metadata database is unavailable.');
    }
    $row = $db->fetchOne(
        'SELECT content_type, byte_length, sha256, state FROM synth_meta.media_objects WHERE media_id='
        . $db->escapeLiteral($media['media_id']) . ' LIMIT 1'
    );
    if (!$row) {
        throw new SynthMediaException(404, 'media_not_registered', 'Media is not registered for delivery.');
    }
    if (strval($row['state'] ?? '') !== 'ready'
        || strval($row['content_type'] ?? '') !== $media['content_type']
        || intval($row['byte_length'] ?? -1) !== $media['size']
        || !hash_equals(strval($row['sha256'] ?? ''), $media['sha256'])) {
        throw new SynthMediaException(409, 'media_not_ready', 'Media metadata is not ready or does not match storage.');
    }
}
