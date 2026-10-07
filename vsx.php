<?php

/* SYNTH Voice Sample Extractor. Audio stays in the user's runtime, not the package. */
ob_start();
ini_set('display_errors', '0');
$path = __DIR__ . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $path;
require_once $path . 'lib/voice_sample.php';
require_once $path . 'lib/runtime_bootstrap.php';
require_once $path . 'lib/fuz_convert.php';
require_once $path . 'lib/voice_clone_sync.php';

function synthVsxRespond(int $status, array $payload): void
{
    ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// Check again before committing a conversion, because a save may load during decoding.
function synthVsxCheckSession(array $metadata): void
{
    if ($metadata['schema'] !== 'synth.voice_sample.v2') return;
    $db = $GLOBALS['db'];
    $session = $db->escapeLiteral($metadata['session_id']);
    $row = $db->fetchOne("SELECT active_generation, state FROM synth_meta.runtime_sessions WHERE session_id={$session}");
    if (!$row || $row['state'] !== 'active' || intval($row['active_generation']) !== $metadata['generation']) {
        throw new RuntimeException('Voice session is missing, halted or stale', 409);
    }
}

$status = 500;
$response = ['schema' => 'synth.voice_sample.response.v1', 'request_id' => '', 'ok' => false];
$temporary = [];
$lock = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Method Not Allowed', 405);
    $raw = $_POST['metadata'] ?? '';
    if (!is_string($raw)) throw new InvalidArgumentException('Invalid voice sample metadata');
    // Echo only bounded ownership fields, including for validation failures.
    $envelope = strlen($raw) <= 8192 ? json_decode($raw, true) : null;
    if (is_array($envelope) && ($envelope['schema'] ?? '') === 'synth.voice_sample.v2') {
        $response['schema'] = 'synth.voice_sample.response.v2';
        $response['request_id'] = is_string($envelope['request_id'] ?? null) ? substr($envelope['request_id'], 0, 128) : '';
        $response['generation'] = is_int($envelope['generation'] ?? null)
            && $envelope['generation'] >= 1 && $envelope['generation'] <= 9007199254740991
            ? $envelope['generation'] : 0;
    }
    $metadata = synth_voice_sample_metadata($raw);
    synthRuntimeBootstrap($path, [
        'load_general_settings' => true, 'load_stt_connector' => false,
        'load_tts_connector' => 'pockettts', 'load_player_name' => false,
    ]);
    synthVsxCheckSession($metadata);
    $voice = $metadata['voice_id'];
    $voiceDir = $path . 'data/voices';
    if (!is_dir($voiceDir) && !mkdir($voiceDir, 0775, true) && !is_dir($voiceDir)) throw new RuntimeException('Voice directory unavailable');
    $wav = $voiceDir . '/' . $voice . '.wav';
    $lock = fopen($voiceDir . '/.' . $voice . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('This voice is already being imported; retry shortly', 423);
    $available = is_file($wav) && filesize($wav) > 44;
    $probe = $metadata['mode'] === 'probe';
    if ($probe && !$available) throw new RuntimeException('Voice sample not found', 404);

    if (!$probe) {
        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($upload['tmp_name'] ?? '') || ($upload['size'] ?? 0) < 1 || $upload['size'] > 8388608) {
            throw new InvalidArgumentException('Missing, failed or oversized voice upload (8 MiB maximum)');
        }
    }
    // Reviewed catalog authority survives both automatic v2 and legacy v1 uploads.
    $voiceDetails = is_file($voiceDir . '/' . $voice . '.json')
        ? json_decode(strval(file_get_contents($voiceDir . '/' . $voice . '.json')), true) : null;
    if (!$probe && ($voiceDetails['selection'] ?? '') === 'curated_catalog') {
        throw new RuntimeException('Curated voice samples must be updated through the catalog importer', 409);
    }
    // Automatic v2 imports never replace an existing manually reviewed/uploaded sample.
    if (!$probe && (!$available || $metadata['schema'] === 'synth.voice_sample.v1')) {
        $bytes = synth_voice_sample_unpack(file_get_contents($upload['tmp_name']), $metadata);
        $ext = strtolower(pathinfo(str_replace('\\', '/', $metadata['original_name']), PATHINFO_EXTENSION));
        $stem = tempnam($path . 'soundcache', '_vsx_');
        if ($stem === false) throw new RuntimeException('Voice staging directory unavailable');
        $temporary[] = $stem;
        $source = $stem . '.' . $ext;
        $temporary[] = $source;
        if (file_put_contents($source, $bytes) !== strlen($bytes)) throw new RuntimeException('Voice staging failed');
        $converted = match ($ext) {
            'fuz' => fuzToWav($source), 'xwm' => xwmToWav($source),
            'wav' => wavToWav($source), 'ogg' => oggToWav($source),
        };
        // Track expected output even if ffmpeg leaves a partial file.
        $temporary[] = $ext === 'wav' ? $stem . '_normalized.wav' : $stem . '.wav';
        if ($converted === '' || !is_file($converted) || filesize($converted) < 132344) {
            throw new InvalidArgumentException('Voice conversion failed or speech sample is shorter than 3 seconds');
        }
        synthVsxCheckSession($metadata);
        $staged = $voiceDir . '/.' . $voice . '.' . bin2hex(random_bytes(8)) . '.wav';
        $temporary[] = $staged;
        if (!copy($converted, $staged) || !rename($staged, $wav)) throw new RuntimeException('Voice sample could not be committed');
        $sidecar = $voiceDir . '/.' . $voice . '.' . bin2hex(random_bytes(8)) . '.json';
        $temporary[] = $sidecar;
        $details = [
            'schema' => 'synth.voice_sample.metadata.v1', 'voice_id' => $voice,
            'source' => $metadata['original_name'], 'reference_text' => $metadata['reference_text'],
            'game' => 'fo4', 'sha256' => hash_file('sha256', $wav), 'bytes' => filesize($wav),
            'source_sha256' => hash('sha256', $bytes), 'selection' => 'automatic_candidate', 'updated_at' => gmdate('c'),
        ];
        if (file_put_contents($sidecar, json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
            || !rename($sidecar, $voiceDir . '/' . $voice . '.json')) throw new RuntimeException('Sample saved but metadata commit failed');
    }
    $runtime = synth_voice_clone_sync_runtime(['root' => $path, 'actor_name' => $metadata['actor_name']]);
    $ready = synth_sync_voice_clone_sample($voice, $wav, ['root' => $path, 'actor_name' => $metadata['actor_name']]);
    $status = 200;
    $response += [
        'codename' => $voice, 'driver' => $runtime['driver'], 'saved' => true, 'ready' => $ready,
        'already_available' => $available, 'cached_path' => 'data/voices/' . $voice . '.wav',
        'sha256' => hash_file('sha256', $wav),
        'message' => $ready ? 'Voice sample ready; preview it in TTS Studio' : 'Sample saved; voice backend is not ready',
    ];
    $response['ok'] = true;
} catch (InvalidArgumentException | JsonException $error) {
    $status = 400;
    $response['error'] = $error->getMessage();
    error_log('[SYNTH VSX] Rejected sample: ' . $error->getMessage());
} catch (Throwable $error) {
    $status = in_array($error->getCode(), [404, 405, 409, 423], true) ? $error->getCode() : 500;
    $response['error'] = $status === 500 ? 'Voice import failed; check server logs' : $error->getMessage();
    error_log('[SYNTH VSX] ' . $error->getMessage());
} finally {
    foreach (array_unique($temporary) as $file) if (is_file($file)) @unlink($file);
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
synthVsxRespond($status, $response);
