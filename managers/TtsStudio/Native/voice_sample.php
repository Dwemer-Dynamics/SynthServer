<?php

// Shared, side-effect-free trust boundary for multipart voice imports and their probes.
function synth_voice_sample_metadata(string $raw): array
{
    if (strlen($raw) > 8192) throw new InvalidArgumentException('Voice metadata is too large');
    $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !in_array($data['schema'] ?? '', ['synth.voice_sample.v1', 'synth.voice_sample.v2'], true)) {
        throw new InvalidArgumentException('Unsupported voice sample schema');
    }
    $v2 = $data['schema'] === 'synth.voice_sample.v2';
    $allowed = ['schema', 'actor_name', 'original_name', 'reference_text', 'game'];
    if ($v2) $allowed = array_merge($allowed, ['request_id', 'session_id', 'generation', 'voice_id', 'mode', 'compression', 'unpacked_bytes']);
    if (array_diff(array_keys($data), $allowed)) throw new InvalidArgumentException('Unknown voice metadata field');
    foreach (['actor_name' => 255, 'original_name' => 512, 'reference_text' => 4096, 'game' => 3] as $key => $limit) {
        $value = $data[$key] ?? ($key === 'game' ? 'fo4' : '');
        if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
            throw new InvalidArgumentException('Invalid voice metadata text');
        }
        $data[$key] = trim($value);
    }
    if ($data['game'] !== 'fo4' || (!$v2 && $data['actor_name'] === '')) throw new InvalidArgumentException('Invalid voice sample game or actor');
    $source = str_replace('\\', '/', $data['original_name']);
    if (!preg_match('#^sound/voice/([^/]{1,255}\.(?:esm|esp|esl))/([a-z0-9_+-]{1,128})/([a-z0-9_ .+-]{1,180}\.(?:fuz|xwm|wav|ogg))$#iD', $source, $parts)
        || str_contains($source, '..') || preg_match('/[\r\n:]/', $source)) {
        throw new InvalidArgumentException('Invalid voice sample source path');
    }
    $voice = strtolower($parts[2]);
    if ($v2) {
        foreach (['request_id', 'session_id'] as $id) {
            if (!is_string($data[$id] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $data[$id])) {
                throw new InvalidArgumentException('Invalid voice request identity');
            }
        }
        if (!is_int($data['generation'] ?? null) || $data['generation'] < 1 || $data['generation'] > 9007199254740991
            || ($data['voice_id'] ?? null) !== $voice
            || !in_array($data['mode'] ?? '', ['probe', 'import'], true)
            || !in_array($data['compression'] ?? '', ['none', 'zlib'], true)
            || !is_int($data['unpacked_bytes'] ?? null) || $data['unpacked_bytes'] < 1 || $data['unpacked_bytes'] > 8388608) {
            throw new InvalidArgumentException('Invalid voice request bounds or voice identity');
        }
    }
    return $data + ['voice_id' => $voice, 'mode' => 'import', 'compression' => 'none'];
}

function synth_voice_sample_unpack(string $bytes, array $metadata): string
{
    if ($bytes === '' || strlen($bytes) > 8388608) throw new InvalidArgumentException('Voice sample exceeds upload bounds');
    if ($metadata['compression'] === 'zlib') {
        $bytes = @gzuncompress($bytes, 8388608);
        if (!is_string($bytes)) throw new InvalidArgumentException('Invalid compressed BA2 voice entry');
    }
    if (isset($metadata['unpacked_bytes']) && strlen($bytes) !== $metadata['unpacked_bytes']) {
        throw new InvalidArgumentException('Voice entry length does not match metadata');
    }
    return $bytes;
}

function synth_voice_sample_local_endpoint(string $endpoint): bool
{
    $parts = parse_url($endpoint);
    return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        && in_array(strtolower($parts['host'] ?? ''), ['127.0.0.1', 'localhost', '[::1]', '::1'], true)
        && !isset($parts['user']) && !isset($parts['pass']);
}
