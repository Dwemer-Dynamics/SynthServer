<?php

require_once __DIR__ . '/voice_sample.php';
require_once __DIR__ . '/provider_stream.php';

if (!function_exists('synth_voice_clone_sync_root')) {
    function synth_voice_clone_sync_root(?string $root = null): string
    {
        $root = trim(strval($root ?? ''));
        return $root !== '' ? rtrim($root, "\\/") : dirname(__DIR__);
    }
}

if (!function_exists('synth_voice_clone_sync_log')) {
    function synth_voice_clone_sync_log(string $level, string $message): void
    {
        $message = "[SYNTH Voice Sync] " . $message;
        if (class_exists('Logger') && method_exists('Logger', $level)) {
            Logger::$level($message);
            return;
        }

        error_log($message);
    }
}

if (!function_exists('synth_voice_clone_sync_normalize_driver')) {
    function synth_voice_clone_sync_normalize_driver($driver): string
    {
        $driver = strtolower(trim(strval($driver)));
        $driver = str_replace('_', '-', $driver);
        if ($driver === 'xtts' || $driver === 'xttsfastapi') {
            return 'xtts-fastapi';
        }
        return $driver;
    }
}

if (!function_exists('synth_voice_clone_sync_provider_key')) {
    function synth_voice_clone_sync_provider_key(string $driver): string
    {
        return [
            'pockettts' => 'POCKETTTS',
            'chatterbox' => 'CHATTERBOX',
            'xtts-fastapi' => 'XTTSFASTAPI',
        ][$driver] ?? '';
    }
}

if (!function_exists('synth_voice_clone_sync_supported_driver')) {
    function synth_voice_clone_sync_supported_driver(string $driver): bool
    {
        return in_array(synth_voice_clone_sync_normalize_driver($driver), ['pockettts', 'chatterbox', 'xtts-fastapi'], true);
    }
}

if (!function_exists('synth_voice_clone_sync_endpoint_from_globals')) {
    function synth_voice_clone_sync_endpoint_from_globals(string $driver): string
    {
        $driver = synth_voice_clone_sync_normalize_driver($driver);
        $providerKey = synth_voice_clone_sync_provider_key($driver);
        $config = ($providerKey !== '' && isset($GLOBALS['TTS'][$providerKey]) && is_array($GLOBALS['TTS'][$providerKey]))
            ? $GLOBALS['TTS'][$providerKey]
            : [];

        $endpoint = trim(strval($config['endpoint'] ?? $config['url'] ?? $config['URL'] ?? ''));
        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }

        return [
            'pockettts' => 'http://127.0.0.1:8024',
            'chatterbox' => 'http://127.0.0.1:8023',
            'xtts-fastapi' => 'http://127.0.0.1:8020',
        ][$driver] ?? '';
    }
}

if (!function_exists('synth_voice_clone_sync_runtime')) {
    function synth_voice_clone_sync_runtime(array $options = []): array
    {
        $root = synth_voice_clone_sync_root($options['root'] ?? null);
        $actorName = trim(strval($options['actor_name'] ?? ''));
        $driver = synth_voice_clone_sync_normalize_driver(
            $options['driver'] ?? ($GLOBALS['TTSFUNCTION'] ?? ($GLOBALS['TTS_FUNCTION'] ?? 'pockettts'))
        );
        $endpoint = trim(strval($options['endpoint'] ?? ''));

        if ($actorName !== '') {
            try {
                foreach ([
                    'lib/core/tts_connector.class.php',
                    'lib/core/npc_master.class.php',
                    'lib/core/core_profiles.class.php',
                ] as $relative) {
                    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    if (is_file($path)) {
                        require_once $path;
                    }
                }

                if (class_exists('TTSConnector') && class_exists('NpcMaster') && class_exists('CoreProfile')) {
                    $ttsConnector = new TTSConnector();
                    $npcMaster = new NpcMaster();
                    $profile = new CoreProfile();
                    $npcData = $npcMaster->getByName($actorName);
                    $profileData = null;

                    if ($npcData) {
                        $profileId = intval($npcData['profile_id'] ?? 0);
                        $profileData = $profileId > 0 ? $profile->getById($profileId) : $profile->getDefaultNpc();
                    } elseif (strcasecmp($actorName, 'The Narrator') === 0) {
                        $narratorPath = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'narrator.class.php';
                        if (is_file($narratorPath)) {
                            require_once $narratorPath;
                        }
                        if (class_exists('Narrator')) {
                            $narrator = new Narrator();
                            $profileId = intval($narrator->getProfileId() ?? 0);
                            if ($profileId > 0) {
                                $profileData = $profile->getById($profileId);
                            }
                        }
                    }

                    if ($profileData) {
                        $connectorData = $ttsConnector->ensureConnectorForProfile($profileData);
                        $profileDriver = synth_voice_clone_sync_normalize_driver($connectorData['driver'] ?? '');
                        if ($connectorData) {
                            $driver = $profileDriver;
                            if (method_exists($ttsConnector, 'resolveConnectorUrl')) {
                                $endpoint = trim(strval($ttsConnector->resolveConnectorUrl($connectorData)));
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                synth_voice_clone_sync_log('warn', "Profile runtime lookup failed for {$actorName}: " . $e->getMessage());
            }
        }

        if (!synth_voice_clone_sync_supported_driver($driver)) {
            return [
                'driver' => $driver,
                'provider_key' => '',
                'endpoint' => '',
                'supported' => false,
            ];
        }

        if ($endpoint === '') {
            $endpoint = synth_voice_clone_sync_endpoint_from_globals($driver);
        }

        return [
            'driver' => $driver,
            'provider_key' => synth_voice_clone_sync_provider_key($driver),
            'endpoint' => rtrim($endpoint, '/'),
            'supported' => true,
        ];
    }
}

if (!function_exists('synth_voice_clone_sync_status_path')) {
    function synth_voice_clone_sync_status_path(string $root): string
    {
        return synth_voice_clone_sync_root($root) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voice_sync_status.json';
    }
}

if (!function_exists('synth_voice_clone_sync_read_status')) {
    function synth_voice_clone_sync_read_status(string $root): array
    {
        $path = synth_voice_clone_sync_status_path($root);
        $decoded = is_file($path) ? json_decode(strval(@file_get_contents($path)), true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('synth_voice_clone_sync_write_status')) {
    function synth_voice_clone_sync_write_status(string $root, array $status): void
    {
        $path = synth_voice_clone_sync_status_path($root);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('synth_voice_clone_sync_http_json')) {
    function synth_voice_clone_sync_http_json(string $url, int $timeoutSeconds = 4): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'data' => null, 'error' => 'curl unavailable'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => ['accept: application/json'],
        ]);
        try {
            $body = synth_speech_curl_exec($ch, $timeoutSeconds * 1000, $diagnostic);
            $error = $diagnostic['error'];
            $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        } finally {
            curl_close($ch);
        }

        $data = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        return [
            'ok' => $error === '' && $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'data' => $data,
            'error' => $error,
        ];
    }
}

if (!function_exists('synth_voice_clone_sync_fetch_speakers')) {
    function synth_voice_clone_sync_fetch_speakers(string $endpoint): array
    {
        $response = synth_voice_clone_sync_http_json(rtrim($endpoint, '/') . '/speakers_list');
        if (!$response['ok']) {
            return [];
        }

        $data = $response['data'];
        if (is_array($data) && array_key_exists('value', $data) && is_array($data['value'])) {
            $data = $data['value'];
        }
        if (!is_array($data)) {
            return [];
        }

        $speakers = [];
        foreach ($data as $speaker) {
            if (is_scalar($speaker)) {
                $speakers[strtolower(trim(strval($speaker)))] = true;
            }
        }
        return $speakers;
    }
}

if (!function_exists('synth_voice_clone_sync_upload')) {
    function synth_voice_clone_sync_upload(string $endpoint, string $voiceId, string $wavPath): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'curl unavailable'];
        }

        $url = rtrim($endpoint, '/') . '/upload_sample';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POSTFIELDS => [
                'wavFile' => new CURLFile($wavPath, 'audio/wav', $voiceId . '.wav'),
            ],
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'Content-Type: multipart/form-data',
            ],
        ]);

        try {
            $body = synth_speech_curl_exec($ch, 20000, $diagnostic);
            $error = $diagnostic['error'];
            $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        } finally {
            curl_close($ch);
        }

        $alreadyExists = $httpCode === 400 && is_string($body) && stripos($body, 'already exists') !== false;
        return [
            'ok' => ($error === '' && (($httpCode >= 200 && $httpCode < 300) || $alreadyExists)),
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }
}

if (!function_exists('synth_sync_voice_clone_sample')) {
    function synth_sync_voice_clone_sample(string $voiceId, string $wavPath, array $options = []): bool
    {
        static $requestCache = [];

        if (is_file($voiceId)) {
            $voiceId = pathinfo($voiceId, PATHINFO_FILENAME);
        }
        if (trim($voiceId) === '') {
            $voiceId = pathinfo($wavPath, PATHINFO_FILENAME);
        }

        $voiceId = strtolower(trim(preg_replace('/\.wav$/i', '', $voiceId)));
        $voiceId = trim(preg_replace('/[^a-z0-9_+-]+/', '_', $voiceId), '_');
        $root = synth_voice_clone_sync_root($options['root'] ?? null);

        if ($voiceId === '' || !is_file($wavPath) || @filesize($wavPath) <= 44) {
            return false;
        }

        $runtime = synth_voice_clone_sync_runtime($options + ['root' => $root]);
        if (empty($runtime['supported']) || trim(strval($runtime['endpoint'] ?? '')) === '') {
            return false;
        }

        $endpoint = rtrim(strval($runtime['endpoint']), '/');
        $driver = strval($runtime['driver']);
        if ($driver === 'pockettts') {
            // audio.cpp consumes local WAV paths; it has no Python upload_sample API.
            // Restrict fallback discovery and path sharing to the same loopback runtime.
            $base = preg_replace('#/(?:v1/audio/speech|tts_to_audio)/?$#', '', $endpoint);
            $models = synth_voice_clone_sync_http_json($base . '/v1/models', 2);
            $audioCpp = false;
            foreach (($models['data']['data'] ?? []) as $model) {
                if (($model['id'] ?? '') === 'pocket-tts' || ($model['family'] ?? '') === 'pocket_tts') $audioCpp = true;
            }
            if (!$audioCpp && !$models['ok'] && synth_voice_sample_local_endpoint($base)
                && in_array(intval(parse_url($base, PHP_URL_PORT)), [8020, 8024], true)) {
                $standard = synth_voice_clone_sync_http_json($base . '/speakers_list', 2);
                if (!$standard['ok']) {
                    $fallback = 'http://127.0.0.1:8086';
                    $models = synth_voice_clone_sync_http_json($fallback . '/v1/models', 2);
                    foreach (($models['data']['data'] ?? []) as $model) {
                        if (($model['id'] ?? '') === 'pocket-tts' || ($model['family'] ?? '') === 'pocket_tts') {
                            $audioCpp = true;
                            $base = $fallback;
                        }
                    }
                }
            }
            if ($audioCpp) return synth_voice_sample_local_endpoint($base) && is_readable($wavPath);
        }
        $forceUpload = !empty($options['force_upload']);
        // Runtime synthesis rechecks the backend; a local receipt can outlive a restart or outage.
        $verifyRemote = !empty($options['verify_remote']);
        $sampleHash = hash_file('sha256', $wavPath) ?: '';
        $signature = implode('|', [$driver, $endpoint, $voiceId, $sampleHash, $forceUpload ? 'force' : 'normal']);
        $statusKey = sha1($signature);

        if (!$verifyRemote && isset($requestCache[$statusKey])) {
            return boolval($requestCache[$statusKey]);
        }

        $status = synth_voice_clone_sync_read_status($root);
        $existing = is_array($status[$statusKey] ?? null) ? $status[$statusKey] : [];
        if (!$verifyRemote && !$forceUpload && ($existing['status'] ?? '') === 'uploaded') {
            $requestCache[$statusKey] = true;
            return true;
        }
        if (!$verifyRemote && !$forceUpload && ($existing['status'] ?? '') === 'failed' && intval($existing['last_attempt'] ?? 0) > (time() - 300)) {
            $requestCache[$statusKey] = false;
            return false;
        }

        $speakers = $forceUpload ? [] : synth_voice_clone_sync_fetch_speakers($endpoint);
        if (!$forceUpload && isset($speakers[strtolower($voiceId)])) {
            $status[$statusKey] = [
                'status' => 'uploaded',
                'driver' => $driver,
                'endpoint' => $endpoint,
                'voiceid' => $voiceId,
                'path' => $wavPath,
                'last_ok' => time(),
                'source' => 'speakers_list',
            ];
            synth_voice_clone_sync_write_status($root, $status);
            $requestCache[$statusKey] = true;
            return true;
        }

        $upload = synth_voice_clone_sync_upload($endpoint, $voiceId, $wavPath);
        $ok = !empty($upload['ok']);
        $status[$statusKey] = [
            'status' => $ok ? 'uploaded' : 'failed',
            'driver' => $driver,
            'endpoint' => $endpoint,
            'voiceid' => $voiceId,
            'path' => $wavPath,
            'http_code' => intval($upload['http_code'] ?? 0),
            'response' => substr(strval($upload['body'] ?? ''), 0, 500),
            'error' => strval($upload['error'] ?? ''),
            'last_attempt' => time(),
        ];
        if ($ok) {
            $status[$statusKey]['last_ok'] = time();
            synth_voice_clone_sync_log('info', "Uploaded {$voiceId}.wav to {$driver} at {$endpoint}");
        } else {
            synth_voice_clone_sync_log('warn', "Failed to upload {$voiceId}.wav to {$driver} at {$endpoint}; HTTP {$status[$statusKey]['http_code']} {$status[$statusKey]['error']}");
        }

        synth_voice_clone_sync_write_status($root, $status);
        $requestCache[$statusKey] = $ok;
        return $ok;
    }
}

?>
