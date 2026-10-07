<?php
// Local single-voice rotation. No provider writes by default; exact old ID required for deletion.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['voice:', 'old-sample:', 'connector:', 'expected-old-id:', 'apply']);
$root = dirname(__DIR__);
try {
    $voice = strval($options['voice'] ?? '');
    $connector = filter_var($options['connector'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^[a-z0-9_+-]{1,128}$/D', $voice) || !$connector) {
        throw new InvalidArgumentException('Required: --voice=ID --connector=ID --old-sample=/absolute/catalog-backups/voice-timestamp/original.wav [--expected-old-id=ID --apply]');
    }
    $oldPath = realpath(strval($options['old-sample'] ?? ''));
    $backups = realpath($root . '/data/voices/catalog-backups');
    if ($oldPath === false || $backups === false || !str_starts_with($oldPath, $backups . '/' . $voice . '-')
        || basename($oldPath) !== 'original.wav') throw new InvalidArgumentException('Expected this voice\'s catalog backup');
    $details = json_decode(strval(file_get_contents($root . '/data/voices/' . $voice . '.json')), true);
    if (($details['selection'] ?? '') !== 'curated_catalog') throw new RuntimeException('Import an approved replacement first');
    require $root . '/conf/conf.php';
    require_once $root . '/lib/postgresql.class.php';
    $GLOBALS['db'] = new sql();
    require_once $root . '/tts/tts-inworld.php';
    $row = $GLOBALS['db']->fetchOne("SELECT t.metadata,b.api_key FROM core_tts_connector t JOIN core_api_badge b ON b.id=t.api_badge_id WHERE t.id={$connector} AND t.driver='inworld'");
    if (!$row) throw new RuntimeException('Inworld connector not found');
    $GLOBALS['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'] = $connector;
    $GLOBALS['TTS']['INWORLD'] = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
    $GLOBALS['TTS']['INWORLD']['API_KEY'] = $row['api_key'];
    $config = getInworldActiveConfig();
    $new = synth_voice_clone_snapshot($root . '/data/voices/' . $voice . '.wav', $root);
    $old = synth_voice_clone_snapshot($oldPath, $root);
    if (!$new || !$old || $new['sha256'] === $old['sha256'] || $new['sha256'] !== ($details['sha256'] ?? '')) {
        throw new RuntimeException('Expected distinct verified old and approved new samples');
    }
    $plan = ['inputs'=>['TTSFUNCTION'=>'inworld', 'TTS'=>$GLOBALS['TTS'],
        'SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'=>$connector], 'samples'=>[$voice=>$old]];
    $GLOBALS['SYNTH_ACTIVE_TTS_PLAN'] = $plan;
    $oldId = getCachedInworldVoiceId($voice, $config);
    $metadata = getInworldVoiceMetadata($voice, $config);
    if ($oldId === '' || empty($metadata['managed']) || ($metadata['voice_id'] ?? '') !== $oldId
        || ($metadata['sample_sha256'] ?? '') !== $old['sha256']
        || !str_starts_with($oldId, $config['workspace'] . '__')) {
        throw new RuntimeException('Old clone is not a verified installation-managed voice in this workspace');
    }
    echo json_encode(['voice'=>$voice, 'old_id'=>$oldId, 'old_sha256'=>$old['sha256'],
        'new_sha256'=>$new['sha256'], 'apply'=>isset($options['apply'])], JSON_PRETTY_PRINT) . PHP_EOL;
    if (!isset($options['apply'])) exit;
    if (!hash_equals($oldId, strval($options['expected-old-id'] ?? ''))) {
        throw new RuntimeException('Exact --expected-old-id from the dry run is required');
    }
    foreach (glob($root . '/data/voices/*.wav') ?: [] as $sample) {
        if (basename($sample, '.wav') !== $voice && hash_file('sha256', $sample) === $old['sha256']) {
            throw new RuntimeException('Old sample is shared by another local voice; retain the remote clone');
        }
    }
    $oldKey = $GLOBALS['db']->escape(getInworldVoiceCacheKey($voice, $config));
    $escapedId = $GLOBALS['db']->escape($oldId);
    $shared = $GLOBALS['db']->fetchOne("SELECT id FROM conf_opts WHERE value='{$escapedId}' AND id<>'{$oldKey}' LIMIT 1");
    if ($shared) throw new RuntimeException('Remote ID is shared by another cached voice; retain it');
    $remoteName = substr(synth_planned_cloud_voice_cache_name('inworld', $voice), 0, 60);
    if (getExistingInworldVoiceIdByName($remoteName, $config) !== $oldId) {
        throw new RuntimeException('Live Inworld identity does not match the old managed clone');
    }
    // Exclude importers throughout provider verification/deletion; no mutable sample can slip in.
    $lock = fopen($root . '/data/voices/.' . $voice . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Voice is busy');
    try {
        if (!hash_equals($new['sha256'], hash_file('sha256', $root . '/data/voices/' . $voice . '.wav'))) {
            throw new RuntimeException('Approved sample changed; rerun inspection');
        }
        $GLOBALS['SYNTH_ACTIVE_TTS_PLAN']['samples'][$voice] = $new;
        $newId = synth_get_planned_cloud_voice('inworld', $voice, $config);
        if (!$newId || $newId === $oldId) throw new RuntimeException('Replacement clone failed; old remote retained');
        $audio = generateInworldTTS('Voice verification complete. Ready when you are.', $newId);
        if (!is_string($audio) || strlen($audio) < 1000) throw new RuntimeException('Replacement audio failed; old remote retained');
        $GLOBALS['SYNTH_ACTIVE_TTS_PLAN'] = $plan;
        if (!deleteManagedInworldVoice($voice)) throw new RuntimeException('Replacement ready but old voice deletion failed: ' . getInworldLastError());
        if (getCachedInworldVoiceId($voice, $config) !== '' || getInworldVoiceMetadata($voice, $config) !== []) {
            throw new RuntimeException('Remote deleted but local cache cleanup needs attention');
        }
        echo json_encode(['deleted_id'=>$oldId, 'replacement_id'=>$newId, 'verified_pcm_bytes'=>strlen($audio)], JSON_PRETTY_PRINT) . PHP_EOL;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
