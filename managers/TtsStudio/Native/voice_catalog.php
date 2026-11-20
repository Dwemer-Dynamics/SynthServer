<?php
declare(strict_types=1);

// Read a single approved catalog selection; resolve every sample beneath the catalog directory.
function synth_voice_catalog_selection(string $catalog, string $voice): array
{
    if (!preg_match('/^[a-z0-9_+-]{1,128}$/D', $voice)) throw new InvalidArgumentException('Invalid voice ID');
    $base = realpath(dirname($catalog));
    $handle = fopen($catalog, 'rb');
    if ($base === false || !$handle) throw new RuntimeException('Catalog unavailable');
    $selected = null;
    try {
        $header = fgetcsv($handle, 65536, ',', '"', '');
        if (!$header) throw new InvalidArgumentException('Empty catalog');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $required = ['voice_id', 'source', 'sample_path', 'sample_sha256', 'source_sha256',
            'duration_seconds', 'reference_text', 'selected', 'review_status', 'language'];
        if (count($header) !== count(array_unique($header)) || array_diff($required, $header)) {
            throw new InvalidArgumentException('Invalid catalog columns');
        }
        while (($values = fgetcsv($handle, 65536, ',', '"', '')) !== false) {
            if (count($values) !== count($header)) throw new InvalidArgumentException('Malformed catalog row');
            $row = array_combine($header, $values);
            if ($row['voice_id'] !== $voice || $row['selected'] !== '1') continue;
            if ($selected !== null) throw new InvalidArgumentException('Multiple selected samples for voice');
            if ($row['review_status'] !== 'approved' || trim($row['reference_text']) === '') {
                throw new InvalidArgumentException('Selected sample requires listening approval and verified transcript');
            }
            if ($row['language'] !== 'en-US' || strlen($row['reference_text']) > 8192) {
                throw new InvalidArgumentException('Unsupported language or oversized transcript');
            }
            $sourceParts = explode('/', strtolower(str_replace('\\', '/', $row['source'])));
            if (count($sourceParts) !== 5 || array_slice($sourceParts, 0, 2) !== ['sound', 'voice']
                || $sourceParts[3] !== $voice || !preg_match('/\.(esm|esp|esl)$/D', $sourceParts[2])
                || !str_ends_with($sourceParts[4], '.fuz') || in_array('..', $sourceParts, true)) {
                throw new InvalidArgumentException('Source voice identity mismatch');
            }
            foreach (['sample_sha256', 'source_sha256'] as $key) {
                if (!preg_match('/^[a-f0-9]{64}$/D', $row[$key])) throw new InvalidArgumentException('Invalid sample hash');
            }
            $relative = str_replace('\\', '/', $row['sample_path']);
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, ':')
                || in_array('..', explode('/', $relative), true)) throw new InvalidArgumentException('Unsafe sample path');
            $sample = realpath($base . '/' . $relative);
            if ($sample === false || !str_starts_with($sample, $base . DIRECTORY_SEPARATOR)
                || !is_file($sample) || is_link($base . '/' . $relative)) throw new InvalidArgumentException('Sample outside catalog');
            $bytes = file_get_contents($sample, false, null, 0, 8388609);
            if (!is_string($bytes) || strlen($bytes) > 8388608 || !hash_equals($row['sample_sha256'], hash('sha256', $bytes))) {
                throw new InvalidArgumentException('Sample hash mismatch or oversized sample');
            }
            // The builder emits canonical mono 22050-Hz PCM16 WAV; do not trust a CSV duration.
            if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 8) !== 'WAVEfmt '
                || unpack('V', substr($bytes, 16, 4))[1] !== 16 || substr($bytes, 36, 4) !== 'data') {
                throw new InvalidArgumentException('Expected canonical PCM WAV');
            }
            $format = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($bytes, 20, 16));
            $dataSize = unpack('V', substr($bytes, 40, 4))[1];
            $duration = $dataSize / 44100;
            if ($format !== ['format'=>1, 'channels'=>1, 'rate'=>22050, 'byterate'=>44100, 'align'=>2, 'bits'=>16]
                || $dataSize !== strlen($bytes) - 44 || unpack('V', substr($bytes, 4, 4))[1] !== strlen($bytes) - 8
                || $dataSize % 2 !== 0 || $duration < 5 || $duration > 60
                || abs($duration - floatval($row['duration_seconds'])) > .01) {
                throw new InvalidArgumentException('Expected a complete 5-60 second mono PCM16 sample');
            }
            $selected = ['row' => $row, 'bytes' => $bytes, 'path' => $sample];
        }
    } finally { fclose($handle); }
    if ($selected === null) throw new InvalidArgumentException('No approved selected sample for ' . $voice);
    return $selected;
}

// A changed curated sample invalidates captured jobs, including their old cache identity.
function synth_assert_curated_voice_snapshot(string $root, string $voice, ?array $snapshot): void
{
    if (!preg_match('/^[a-z0-9_+-]{1,128}$/D', $voice)) return;
    $path = $root . '/data/voices/' . $voice . '.json';
    if (!is_file($path)) return;
    $metadata = json_decode(strval(file_get_contents($path)), true);
    if (!is_array($metadata)) throw new RuntimeException('Voice metadata is unreadable');
    if (($metadata['selection'] ?? '') !== 'curated_catalog') return;
    if (!hash_equals(strval($metadata['sha256'] ?? ''), strval($snapshot['sha256'] ?? ''))) {
        throw new RuntimeException('Queued voice sample was superseded by the curated catalog');
    }
}

// CLI import uses the upload endpoint's per-voice lock, with rollback and permanent local backups.
function synth_import_voice_catalog(string $root, string $catalog, string $voice, bool $apply): array
{
    $selection = synth_voice_catalog_selection($catalog, $voice);
    $row = $selection['row'];
    $directory = realpath($root . '/data/voices');
    if ($directory === false) throw new RuntimeException('Voice directory unavailable');
    $wav = $directory . '/' . $voice . '.wav';
    $json = $directory . '/' . $voice . '.json';
    foreach ([$wav, $json, $directory . '/.' . $voice . '.lock'] as $path) {
        if (is_link($path)) throw new RuntimeException('Refusing linked runtime voice file');
    }
    $lock = fopen($directory . '/.' . $voice . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Voice is busy; retry with game closed');
    $staged = [];
    try {
        $old = is_file($wav) ? file_get_contents($wav) : null;
        $oldJson = is_file($json) ? file_get_contents($json) : null;
        $details = ['schema'=>'synth.voice_sample.metadata.v1', 'voice_id'=>$voice, 'source'=>$row['source'],
            'reference_text'=>$row['reference_text'], 'game'=>'fo4', 'sha256'=>$row['sample_sha256'],
            'bytes'=>strlen($selection['bytes']), 'source_sha256'=>$row['source_sha256'],
            'selection'=>'curated_catalog', 'language'=>$row['language'], 'updated_at'=>gmdate('c')];
        $previous = json_decode(strval($oldJson), true);
        $unchanged = is_string($old) && hash_equals(hash('sha256', $old), $row['sample_sha256'])
            && ($previous['selection'] ?? '') === 'curated_catalog'
            && ($previous['reference_text'] ?? '') === $row['reference_text']
            && ($previous['source_sha256'] ?? '') === $row['source_sha256'];
        $result = ['voice_id'=>$voice, 'sha256'=>$row['sample_sha256'], 'unchanged'=>$unchanged, 'applied'=>false];
        if (!$apply || $unchanged) return $result;
        $backup = $directory . '/catalog-backups/' . $voice . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (is_link($directory . '/catalog-backups')) throw new RuntimeException('Refusing linked backup directory');
        if (!mkdir($backup, 0750, true)) throw new RuntimeException('Backup directory unavailable');
        foreach (['wav'=>$old, 'json'=>$oldJson] as $extension=>$bytes) {
            if (is_string($bytes) && file_put_contents($backup . '/original.' . $extension, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Backup failed; sample unchanged');
            }
        }
        foreach (['wav'=>$selection['bytes'], 'json'=>json_encode($details, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)] as $extension=>$bytes) {
            $temp = $directory . '/.' . $voice . '.' . bin2hex(random_bytes(8)) . '.' . $extension;
            $staged[$extension] = $temp;
            if (file_put_contents($temp, $bytes) !== strlen($bytes)) throw new RuntimeException('Voice staging failed');
            chmod($temp, 0644);
        }
        // Publish the new authority first: old queued plans fail closed during the two-file switch.
        if (!rename($staged['json'], $json)) throw new RuntimeException('Metadata commit failed');
        if (!rename($staged['wav'], $wav)) {
            if (is_string($oldJson)) copy($backup . '/original.json', $json); else unlink($json);
            throw new RuntimeException('WAV commit failed; previous authority restored');
        }
        $result['applied'] = true;
        $result['backup'] = $backup;
        return $result;
    } finally {
        foreach ($staged as $file) if (is_file($file)) unlink($file);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
