<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'voice_clone_sync.php';
require_once __DIR__ . '/media_store.php';

// Keep an immutable private WAV for upload; never read a URL or publish voice samples as downloadable media.
function synth_voice_clone_snapshot(string $sample, string $root): ?array
{
    $local = realpath($sample);
    if ($local === false || !is_file($local)) return null;
    $bytes = @file_get_contents($local, false, null, 0, SYNTH_MEDIA_MAX_BYTES + 1);
    if (!is_string($bytes) || strlen($bytes) > SYNTH_MEDIA_MAX_BYTES || !synth_media_is_wave($bytes)) return null;
    $hash = hash('sha256', $bytes);
    $directory = $root . '/data/tmp/voice_uploads';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return null;
    $rootPath = realpath($root);
    $directoryPath = realpath($directory);
    if ($rootPath === false || $directoryPath === false || !str_starts_with($directoryPath, $rootPath . DIRECTORY_SEPARATOR)) return null;
    $snapshot = $directoryPath . DIRECTORY_SEPARATOR . $hash . '.wav';
    if (is_link($snapshot)) return null;
    try {
        synth_media_publish_file($snapshot, $bytes);
        if (!hash_equals($hash, strval(@hash_file('sha256', $snapshot)))) return null;
    } catch (SynthMediaException $error) {
        return null;
    }
    return ['sha256' => $hash, 'path' => $snapshot];
}

// XTTS-compatible queued requests use content-addressed uploads, never a mutable local sample path.
function synth_prepare_planned_clone_voice(string $driver, string $endpoint, string $voice, ?string $root = null): string|false
{
    $plan = $GLOBALS['SYNTH_ACTIVE_TTS_PLAN'] ?? null;
    if (!is_array($plan)) return $voice;
    $voice = trim($voice);
    if (!in_array($driver, ['xtts-fastapi', 'chatterbox'], true)
        || synth_voice_clone_sync_normalize_driver($plan['inputs']['TTSFUNCTION'] ?? '') !== $driver
        || !array_key_exists($voice, $plan['samples'] ?? [])) return false;
    $snapshot = $plan['samples'][$voice];
    // A missing local path is not a backend preset: never let the backend read bytes added later.
    if ($snapshot === null) return strpbrk($voice, '/\\') === false ? $voice : false;
    $path = strval($snapshot['path'] ?? '');
    $hash = strval($snapshot['sha256'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || is_link($path) || !is_file($path)
        || !hash_equals($hash, strval(@hash_file('sha256', $path)))) return false;
    $voiceId = 'synth_' . $hash;
    return synth_sync_voice_clone_sample($voiceId, $path, [
        'root' => $root ?? dirname(__DIR__), 'driver' => $driver,
        'endpoint' => $endpoint, 'verify_remote' => true,
    ]) ? $voiceId : false;
}

// Planned cloud clones are reusable only for the exact captured sample and clone language.
function synth_planned_cloud_voice_cache_name(string $driver, string $voice): string
{
    $plan = $GLOBALS['SYNTH_ACTIVE_TTS_PLAN'] ?? null;
    if (!is_array($plan)) return $voice;
    if (($plan['inputs']['TTSFUNCTION'] ?? '') !== $driver
        || !array_key_exists($voice, $plan['samples'] ?? [])) throw new RuntimeException('Uncaptured cloud speech voice');
    $snapshot = $plan['samples'][$voice];
    if ($snapshot === null) return $voice;
    $language = $plan['inputs']['PATCH_OVERRIDE_TTS_LANGUAGE']
        ?? $plan['inputs']['TTS'][strtoupper($driver)]['language'] ?? ($driver === 'inworld' ? 'en-US' : 'en');
    return 'synth_' . hash('sha256', json_encode([$snapshot['sha256'], $language], JSON_THROW_ON_ERROR));
}

// Never fall back to mutable disk data when a queued cloud reply had no local sample at capture.
function synth_get_planned_cloud_voice(string $driver, string $voice, array $config): string|false
{
    require_once __DIR__ . '/voice_catalog.php';
    synth_assert_curated_voice_snapshot(dirname(__DIR__), $voice, $GLOBALS['SYNTH_ACTIVE_TTS_PLAN']['samples'][$voice] ?? null);
    $cacheName = synth_planned_cloud_voice_cache_name($driver, $voice);
    $snapshot = $GLOBALS['SYNTH_ACTIVE_TTS_PLAN']['samples'][$voice];
    if ($snapshot === null) {
        $id = $GLOBALS['SYNTH_ACTIVE_TTS_PLAN']['remote_voices'][$voice] ?? '';
        return is_string($id) && $id !== '' ? $id : false;
    }
    $suffix = $driver === 'cartesia' ? 'Cartesia' : 'Inworld';
    $getCached = 'getCached' . $suffix . 'VoiceId';
    $findRemote = 'getExisting' . $suffix . 'VoiceIdByName';
    $store = 'storeCached' . $suffix . 'VoiceId';
    $metadata = 'store' . $suffix . 'VoiceMetadata';
    $clone = 'cloneVoiceTo' . $suffix;
    $path = strval($snapshot['path'] ?? '');
    if (is_link($path) || !is_file($path)
        || !hash_equals(strval($snapshot['sha256'] ?? ''), strval(@hash_file('sha256', $path)))) return false;
    $cached = $getCached($voice, $config);
    if ($cached !== '') return $cached;
    // Inworld permits 60 display-name characters. Keep the full identity in our
    // cache key; shorten only its ASCII provider label (216 bits remain).
    $remoteName = $driver === 'inworld' ? substr($cacheName, 0, 60) : $cacheName;
    $lock = null;
    if ($driver === 'inworld') {
        $lockPath = dirname($path) . '/inworld-clone-' . hash('sha256', getInworldVoiceCachePrefix($config) . $cacheName) . '.lock';
        if (is_link($lockPath)) return false;
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) return false;
    }
    try {
        if (is_resource($lock)) {
            $deadline = microtime(true) + 45;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
                if (microtime(true) >= $deadline) return false;
                usleep(100000);
            }
            if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
            // Sentence workers must reuse the first worker's clone, including aliases
            // with the same captured sample, workspace, language and credentials.
            $cached = $getCached($voice, $config);
            if ($cached !== '') return $cached;
            rewind($lock);
            $failedAt = floatval(stream_get_contents($lock, 32));
            if ($failedAt > microtime(true) - 60) return false;
        }
        $remote = $findRemote($remoteName, $config);
        if ($remote !== '') {
            $store($voice, $remote, $config);
            $metadata($voice, $remote, '', false, $config);
            return $remote;
        }
        $remote = $clone($remoteName, $snapshot['path'], $config);
        if ($remote === false || $remote === '') {
            if (is_resource($lock)) {
                rewind($lock); ftruncate($lock, 0); fwrite($lock, strval(microtime(true))); fflush($lock);
            }
            return false;
        }
        $store($voice, $remote, $config);
        $metadata($voice, $remote, $snapshot['path'], true, $config);
        return $remote;
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

if (!function_exists('synth_voice_clone_root')) {
    function synth_voice_clone_root(?string $root = null): string
    {
        $root = trim(strval($root ?? ''));
        return $root !== '' ? rtrim($root, "\\/") : dirname(__DIR__);
    }
}

if (!function_exists('synth_voice_clone_normalize_key')) {
    function synth_voice_clone_normalize_key(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\.wav$/i', '', $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value);
        return strval($value);
    }
}

if (!function_exists('synth_voice_clone_candidate_names')) {
    function synth_voice_clone_candidate_names(array $values): array
    {
        $candidates = [];
        foreach ($values as $value) {
            $value = trim(strval($value));
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/\.wav$/i', '', $value);
            $simple = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $value);
            $underscore = preg_replace('/[^A-Za-z0-9]+/', '_', $simple);
            $compact = preg_replace('/[^A-Za-z0-9]+/', '', $simple);

            foreach ([$value, $simple, $underscore, $compact, strtolower($underscore), strtolower($compact)] as $candidate) {
                $candidate = trim(strval($candidate), " \t\n\r\0\x0B_.-");
                if ($candidate !== '') {
                    $candidates[$candidate . '.wav'] = true;
                }
            }
        }

        return array_keys($candidates);
    }
}

if (!function_exists('synth_voice_clone_dirs')) {
    function synth_voice_clone_dirs(?string $root = null): array
    {
        $root = synth_voice_clone_root($root);
        $dirs = [
            $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices',
        ];

        foreach (['SYNTH_VOICE_SAMPLE_DIR', 'VOICE_SAMPLE_DIR', 'VOICE_DIR'] as $globalKey) {
            $value = trim(strval($GLOBALS[$globalKey] ?? ''));
            if ($value !== '') {
                $dirs[] = $value;
            }
        }

        $result = [];
        foreach ($dirs as $dir) {
            $real = realpath($dir);
            if ($real !== false && is_dir($real)) {
                $result[$real] = true;
            }
        }

        return array_keys($result);
    }
}

if (!function_exists('synth_voice_clone_sample_name')) {
    function synth_voice_clone_sample_name(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\.wav$/i', '', $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_+-]+/', '_', $value);
        return trim(strval($value), '_');
    }
}

if (!function_exists('synth_voice_clone_ffmpeg_path')) {
    function synth_voice_clone_ffmpeg_path(): string
    {
        foreach ([
            getenv('FFMPEG_PATH') ?: '',
            'C:\\Program Files\\ShareX\\ffmpeg.exe',
            'ffmpeg',
        ] as $candidate) {
            $candidate = trim(strval($candidate));
            if ($candidate === '') {
                continue;
            }

            if ($candidate === 'ffmpeg' || is_file($candidate)) {
                return $candidate;
            }
        }

        return 'ffmpeg';
    }
}

if (!function_exists('synth_voice_clone_game_roots')) {
    function synth_voice_clone_game_roots(): array
    {
        $roots = [];
        foreach ([
            'SYNTH_FO4_VOICE_ROOTS',
            'SYNTH_GAME_VOICE_ROOTS',
            'FO4_VOICE_SAMPLE_ROOTS',
        ] as $globalKey) {
            $value = $GLOBALS[$globalKey] ?? '';
            $values = is_array($value) ? $value : preg_split('/[;|]/', strval($value));
            foreach ($values ?: [] as $root) {
                $root = trim(strval($root), " \t\n\r\0\x0B\"'");
                if ($root !== '') {
                    $roots[] = $root;
                }
            }
        }

        foreach ([
            'C:\\Modlists\\Fallout 4\\mods',
        ] as $root) {
            $roots[] = $root;
        }

        $result = [];
        foreach ($roots as $root) {
            $real = realpath($root);
            if ($real !== false && is_dir($real)) {
                $result[$real] = true;
            }
        }

        return array_keys($result);
    }
}

if (!function_exists('synth_voice_clone_find_game_sample')) {
    function synth_voice_clone_find_game_sample(string $voiceId, array $options = []): string
    {
        static $cache = [];

        $voiceKey = synth_voice_clone_normalize_key($voiceId);
        if ($voiceKey === '') {
            return '';
        }

        $roots = synth_voice_clone_game_roots();
        $cacheKey = $voiceKey . '|' . implode('|', $roots);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $bestPath = '';
        $bestSize = 0;
        foreach ($roots as $root) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
            } catch (Throwable $e) {
                continue;
            }

            foreach ($iterator as $entry) {
                if (!$entry->isDir()) {
                    continue;
                }

                $dirName = synth_voice_clone_normalize_key($entry->getBasename());
                if ($dirName !== $voiceKey) {
                    continue;
                }

                $path = $entry->getPathname();
                $normalizedPath = strtolower(str_replace('/', '\\', $path));
                if (strpos($normalizedPath, '\\sound\\voice\\') === false) {
                    continue;
                }

                $files = @scandir($path);
                if (!is_array($files)) {
                    continue;
                }

                foreach ($files as $fileName) {
                    if ($fileName === '.' || $fileName === '..') {
                        continue;
                    }

                    $samplePath = $path . DIRECTORY_SEPARATOR . $fileName;
                    if (!is_file($samplePath)) {
                        continue;
                    }

                    $extension = strtolower(pathinfo($samplePath, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['wav', 'ogg', 'xwm'], true)) {
                        continue;
                    }

                    $size = @filesize($samplePath);
                    if ($size > $bestSize) {
                        $bestSize = $size;
                        $bestPath = $samplePath;
                    }
                }
            }
        }

        $cache[$cacheKey] = $bestPath;
        return $bestPath;
    }
}

if (!function_exists('synth_voice_clone_materialize_game_sample')) {
    function synth_voice_clone_materialize_game_sample(string $voiceId, array $options = []): string
    {
        $root = synth_voice_clone_root($options['root'] ?? null);
        $sampleName = synth_voice_clone_sample_name($voiceId);
        if ($sampleName === '') {
            return '';
        }

        $voiceDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices';
        if (!is_dir($voiceDir)) {
            @mkdir($voiceDir, 0775, true);
        }

        $target = $voiceDir . DIRECTORY_SEPARATOR . $sampleName . '.wav';
        if (is_file($target) && @filesize($target) > 44) {
            return realpath($target) ?: $target;
        }

        $source = synth_voice_clone_find_game_sample($voiceId, $options);
        if ($source === '') {
            return '';
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if ($extension === 'wav') {
            @copy($source, $target);
        } else {
            $ffmpeg = synth_voice_clone_ffmpeg_path();
            $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
            $inputArgs = ($extension === 'xwm')
                ? '-f xwma -i ' . escapeshellarg($source)
                : '-i ' . escapeshellarg($source);
            $command = escapeshellarg($ffmpeg) . ' -v 0 -y ' . $inputArgs . ' -ar 22050 -ac 1 -sample_fmt s16 ' . escapeshellarg($target) . " >$nullDevice 2>$nullDevice";
            shell_exec($command);
        }

        if (is_file($target) && @filesize($target) > 44) {
            error_log("[SYNTH Voice] Materialized voice sample {$sampleName}.wav from {$source}");
            return realpath($target) ?: $target;
        }

        error_log("[SYNTH Voice] Failed to materialize voice sample {$sampleName}.wav from {$source}");
        return '';
    }
}

if (!function_exists('synth_voice_clone_wav_index')) {
    function synth_voice_clone_wav_index(?string $root = null): array
    {
        static $cache = [];
        $cacheKey = implode('|', synth_voice_clone_dirs($root));
        if (isset($cache[$cacheKey]) && microtime(true) - $cache[$cacheKey]['at'] < 1.0) {
            return $cache[$cacheKey]['index'];
        }

        $index = [];
        foreach (synth_voice_clone_dirs($root) as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'wav') {
                    continue;
                }

                $path = $file->getPathname();
                if (@filesize($path) <= 44) {
                    continue;
                }

                $key = synth_voice_clone_normalize_key($file->getBasename('.wav'));
                if ($key !== '' && !isset($index[$key])) {
                    $index[$key] = $path;
                }
            }
        }

        $cache[$cacheKey] = ['at' => microtime(true), 'index' => $index];
        return $index;
    }
}

if (!function_exists('synth_resolve_voice_clone_wav')) {
    function synth_voice_clone_maybe_sync_sample(string $voiceId, string $wavPath, array $options = []): void
    {
        if (array_key_exists('sync_sample', $options) && !$options['sync_sample']) {
            return;
        }

        synth_sync_voice_clone_sample($voiceId, $wavPath, $options);
    }

    function synth_resolve_voice_clone_wav($voiceId, array $options = []): string
    {
        // Imports can complete while the response worker is already running.
        clearstatcache();
        $root = $options['root'] ?? null;
        $values = [$voiceId];
        foreach (['voice_name', 'voice_formid', 'actor_name'] as $key) {
            if (!empty($options[$key])) {
                $values[] = $options[$key];
            }
        }

        foreach ($values as $value) {
            $value = trim(strval($value));
            if ($value !== '' && is_file($value) && strtolower(pathinfo($value, PATHINFO_EXTENSION)) === 'wav' && @filesize($value) > 44) {
                $resolved = realpath($value) ?: $value;
                synth_voice_clone_maybe_sync_sample(strval($voiceId), $resolved, $options);
                return $resolved;
            }
        }

        foreach (synth_voice_clone_dirs($root) as $dir) {
            foreach (synth_voice_clone_candidate_names($values) as $name) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path) && @filesize($path) > 44) {
                    $resolved = realpath($path) ?: $path;
                    synth_voice_clone_maybe_sync_sample(strval($voiceId), $resolved, $options);
                    return $resolved;
                }
            }
        }

        $index = synth_voice_clone_wav_index($root);
        foreach ($values as $value) {
            $key = synth_voice_clone_normalize_key(strval($value));
            if ($key !== '' && isset($index[$key])) {
                synth_voice_clone_maybe_sync_sample(strval($voiceId), $index[$key], $options);
                return $index[$key];
            }
        }

        $allowGameScan = !empty($options['allow_game_scan']);
        if ($allowGameScan) {
            foreach ($values as $value) {
                $materialized = synth_voice_clone_materialize_game_sample(strval($value), $options);
                if ($materialized !== '') {
                    synth_voice_clone_maybe_sync_sample(strval($voiceId), $materialized, $options);
                    return $materialized;
                }
            }
        }

        return '';
    }
}

if (!function_exists('synth_resolve_tts_voice_reference')) {
    function synth_resolve_tts_voice_reference($voiceId, array $options = []): string
    {
        $voiceId = trim(strval($voiceId));
        if ($voiceId === '') {
            return '';
        }

        $forceSample = !empty($options['force_sample']);
        if (!$forceSample && !synth_tts_active_provider_uses_voice_sample()) {
            return $voiceId;
        }

        $wav = synth_resolve_voice_clone_wav($voiceId, $options);
        return $wav !== '' ? $wav : $voiceId;
    }
}

if (!function_exists('synth_tts_active_provider_uses_voice_sample')) {
    function synth_tts_active_provider_uses_voice_sample(): bool
    {
        $driver = strtolower(trim(strval($GLOBALS['TTSFUNCTION'] ?? ($GLOBALS['TTS_FUNCTION'] ?? ''))));
        $driver = str_replace('_', '-', $driver);
        if ($driver === 'xtts' || $driver === 'xttsfastapi') {
            $driver = 'xtts-fastapi';
        }
        if ($driver === 'pockettts') {
            $driver = 'pockettts';
        }

        return in_array($driver, ['xtts-fastapi', 'chatterbox', 'pockettts'], true);
    }
}

?>
