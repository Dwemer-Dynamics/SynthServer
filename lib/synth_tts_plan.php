<?php

require_once __DIR__ . '/voice_clone_resolver.php';
require_once __DIR__ . '/synth_tts.php';

// Only speech inputs may cross the worker boundary; never restore request ownership or a database handle.
const SYNTH_TTS_PLAN_GLOBALS = [
    'TTSFUNCTION', 'TTS_FUNCTION', 'TTS', 'SYNTH_NAME', 'SYNTH_SPEECH_ROLE', 'SYNTH_CORE_CURRENT_NPC_DATA',
    'SYNTH_CORE_CURRENT_PROFILE_DATA', 'SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID',
    'PATCH_OVERRIDE_VOICE', 'PATCH_OVERRIDE_VOICE_ID', 'PATCH_OVERRIDE_TTS_LANGUAGE',
    'PATCH_OVERRIDE_TTS_OPTIONS', 'TTS_NPC_ORIGINAL_VOICE', 'TTS_NPC_FALLBACK_VOICE',
    'TTS_NPC_RESOLVED_VOICE', 'TTS_NPC_RESOLVED_VOICE_SAMPLE', 'TTS_FFMPEG_FILTERS',
    'LANG_LLM_XTTS', 'LLM_LANG', 'AVOID_TTS_CACHE', 'DEVELOP_STORE_AUDIO_FOR_TRANING',
    'FORCE_MOOD', 'LAST_LLM_RESPONSE', 'TTS_INJECT_NONVERBAL_VOCALIZATION',
    'INWORLD_AUDIO_FORMAT', 'use_emotions_expression', 'MELOTTS_PRONUNCIATION_ADJUST',
    'MELOTTS_PRONUNCIATION_DEBUG',
];

// Capture after NPC voice resolution, before choosing a cache key or emitting the reply.
function synth_capture_tts_plan(string $root, string $speaker, string $cacheText, string $mood, ?string $text = null): array
{
    $inputs = [];
    foreach (SYNTH_TTS_PLAN_GLOBALS as $key) {
        if (array_key_exists($key, $GLOBALS)) $inputs[$key] = $GLOBALS[$key];
    }
    $inputs['SYNTH_NAME'] = $speaker;
    require_once __DIR__.'/synth_protocol_state.php';
    $inputs['SYNTH_SPEECH_ROLE'] = synth_protocol_current_speech_role($speaker);
    // Deep-copy plain data and reject accidental objects/resources instead of silently serializing them.
    array_walk_recursive($inputs, static function ($value): void {
        if (is_object($value) || is_resource($value)) throw new RuntimeException('Nonserializable speech settings');
    });
    $inputs = json_decode(json_encode($inputs, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
    $samples = [];
    $driver = synth_voice_clone_sync_normalize_driver($inputs['TTSFUNCTION'] ?? '');
    $provider = synth_voice_clone_sync_provider_key($driver);
    $cloud = in_array($driver, ['cartesia', 'inworld'], true);
    if ($cloud) $provider = strtoupper($driver);
    if ($provider !== '') {
        $voice = trim(strval($inputs['PATCH_OVERRIDE_VOICE'] ?? $inputs['TTS']['FORCED_VOICE_DEV']
            ?? $inputs['TTS'][$provider]['voiceid'] ?? ''));
        if ($cloud) {
            // Resolve the cloud adapter's legacy name fallback from owned inputs, never a worker-time NPC query.
            if ($voice === '' && !isset($inputs['PATCH_OVERRIDE_VOICE'])) {
                $npc = $inputs['SYNTH_CORE_CURRENT_NPC_DATA'] ?? [];
                if (strcasecmp(trim(strval($npc['npc_name'] ?? '')), $speaker) === 0) {
                    $voice = trim(strval($npc['voiceid'] ?? ''));
                }
                if ($voice === '') $voice = preg_replace('/[^a-zA-Z0-9_+]/u', '',
                    str_replace([" ", "'"], ['_', '+'], mb_strtolower($speaker, 'UTF-8')));
            }
            $inputs['PATCH_OVERRIDE_VOICE'] = $voice;
        } elseif ($voice === '') $voice = trim(strval($inputs['TTS'][$provider]['voiceid'] ?? ''));
        // Snapshot both candidates now. An upload or replacement after enqueue must not change this reply.
        foreach (array_unique([$voice, trim(strval($inputs['TTS_NPC_FALLBACK_VOICE'] ?? ''))]) as $candidate) {
            if ($candidate === '') continue;
            $sample = synth_resolve_voice_clone_wav($candidate, [
                'root' => $root, 'sync_sample' => false, 'allow_game_scan' => false,
            ]);
            $endpoint = strval($inputs['TTS']['POCKETTTS']['endpoint'] ?? '');
            if ($driver === 'pockettts' && $sample === '' && (preg_match('/\:8086(?:\/|$)/', $endpoint) || str_contains($endpoint, '/v1/audio/speech'))) {
                // Preserve audio.cpp's existing backend-local speaker search at capture time, not worker time.
                foreach (['/home/dwemer/pocket-tts/speakers/', '/home/dwemer/audio.cpp/speakers/'] as $directory) {
                    $candidatePath = $directory . basename($candidate, '.wav') . '.wav';
                    if (is_readable($candidatePath)) { $sample = $candidatePath; break; }
                }
            }
            $snapshot = $sample === '' ? null : synth_voice_clone_snapshot($sample, $root);
            if ($sample !== '' && $snapshot === null) throw new RuntimeException('Unable to pin speech voice sample');
            // An explicit null pins a backend preset too: a later local import cannot replace it.
            $samples[$candidate] = $snapshot;
        }
    }
    $plan = [
        'version' => 1, 'speaker' => $speaker, 'cache_text' => trim($cacheText),
        'text' => trim($text ?? $cacheText), 'mood' => $mood,
        // Preserve sample-sensitive cache identity for adapters whose immutable handoff is still pending.
        'voice_seed' => synth_tts_cache_seed($root, $speaker, $cacheText),
        'inputs' => $inputs, 'samples' => $samples,
    ];
    // Callables are process-local. Preserve them for the in-process queue, never serialize closures to JSON.
    $callbacks = [
        'text_hooks' => $GLOBALS['HOOKS']['XTTS_TEXTMODIFIER'] ?? [],
        'omni_text_hooks' => $driver === 'omnivoice' ? ($GLOBALS['HOOKS']['OMNIVOICE_TEXTMODIFIER'] ?? []) : [],
        'fallback' => $GLOBALS['TTS_FALLBACK_FNCT'] ?? null,
    ];
    $plan['local_callbacks'] = $callbacks;
    $plan['portable'] = empty($callbacks['text_hooks']) && empty($callbacks['omni_text_hooks']) && $callbacks['fallback'] === null;
    $plan['callback_scope'] = $plan['portable'] ? '' : bin2hex(random_bytes(16));
    $plan['cache_seed'] = synth_tts_plan_seed($plan);
    if ($cloud) {
        // Resolve remote-only names before enqueue. Local sample clones remain deferred and versioned.
        $plan['remote_voices'] = synth_with_tts_plan($plan, $plan['cache_seed'], $speaker, $root,
            static function () use ($driver, $samples): array {
                require_once dirname(__DIR__) . '/tts/tts-' . $driver . '.php';
                $title = $driver === 'cartesia' ? 'Cartesia' : 'Inworld';
                $getter = 'get' . $title . 'ActiveConfig';
                $cached = 'getCached' . $title . 'VoiceId';
                $find = 'getExisting' . $title . 'VoiceIdByName';
                $config = $getter(); $ids = [];
                $previousDeadline = $GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE'] ?? null;
                $GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE'] = microtime(true) + 10;
                try {
                    foreach ($samples as $voice => $snapshot) {
                        if ($snapshot !== null) continue;
                        $id = '';
                        if (trim(strval($config['api_key'] ?? '')) !== '') {
                            if (is_object($GLOBALS['db'] ?? null)) $id = $cached($voice, $config);
                            if ($id === '') $id = $find($voice, $config);
                        }
                        // Empty is a captured failure, not permission to resolve another voice later.
                        $ids[$voice] = $id;
                    }
                } finally {
                    unset($GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE']);
                    if ($previousDeadline !== null) $GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE'] = $previousDeadline;
                }
                return $ids;
            });
        $plan['cache_seed'] = synth_tts_plan_seed($plan);
    }
    return $plan;
}

// The wire/cache identity contains only a digest, never endpoint credentials or private sample paths.
function synth_tts_plan_seed(array $plan): string
{
    $identity = $plan;
    unset($identity['cache_seed'], $identity['local_callbacks']);
    return 'synth.tts.v6' . "\n" . hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
}

function synth_validate_tts_plan(array $plan, string $seed, string $speaker, string $root): void
{
    if (($plan['version'] ?? null) !== 1 || ($plan['speaker'] ?? null) !== $speaker
        || !is_array($plan['inputs'] ?? null) || !is_array($plan['samples'] ?? null)
        || ($plan['inputs']['SYNTH_NAME'] ?? null) !== $speaker
        || (isset($plan['inputs']['SYNTH_SPEECH_ROLE'])
            && !in_array($plan['inputs']['SYNTH_SPEECH_ROLE'],['npc','player','narrator'],true))
        || array_diff(array_keys($plan['inputs']), SYNTH_TTS_PLAN_GLOBALS)
        || !hash_equals($seed, strval($plan['cache_seed'] ?? ''))
        || !hash_equals($seed, synth_tts_plan_seed($plan))) {
        throw new RuntimeException('Invalid queued speech plan');
    }
    $directory = realpath($root . '/data/tmp/voice_uploads');
    require_once __DIR__ . '/voice_catalog.php';
    foreach ($plan['samples'] as $voice => $snapshot) {
        synth_assert_curated_voice_snapshot($root, strval($voice), $snapshot);
        if ($snapshot === null) continue;
        $hash = strval($snapshot['sha256'] ?? '');
        $path = strval($snapshot['path'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || $directory === false
            || $path !== $directory . DIRECTORY_SEPARATOR . $hash . '.wav'
            || is_link($path) || !is_file($path)
            || !hash_equals($hash, strval(@hash_file('sha256', $path)))) {
            throw new RuntimeException('Queued speech sample is missing or changed');
        }
    }
}

// Restore every input even on cancellation/failure; consecutive queued speakers must not share settings.
function synth_with_tts_plan(array $plan, string $seed, string $speaker, string $root, callable $generate): mixed
{
    synth_validate_tts_plan($plan, $seed, $speaker, $root);
    $keys = array_merge(SYNTH_TTS_PLAN_GLOBALS, ['SYNTH_ACTIVE_TTS_PLAN', 'HOOKS', 'TTS_FALLBACK_FNCT', 'TTS_IN_USE']);
    $previous = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $GLOBALS)) $previous[$key] = $GLOBALS[$key];
    }
    try {
        foreach (SYNTH_TTS_PLAN_GLOBALS as $key) {
            unset($GLOBALS[$key]);
            if (array_key_exists($key, $plan['inputs'])) $GLOBALS[$key] = $plan['inputs'][$key];
        }
        $GLOBALS['SYNTH_ACTIVE_TTS_PLAN'] = $plan;
        $GLOBALS['HOOKS']['XTTS_TEXTMODIFIER'] = $plan['local_callbacks']['text_hooks'] ?? [];
        $GLOBALS['HOOKS']['OMNIVOICE_TEXTMODIFIER'] = $plan['local_callbacks']['omni_text_hooks'] ?? [];
        unset($GLOBALS['TTS_FALLBACK_FNCT']);
        if (isset($plan['local_callbacks']['fallback'])) {
            $GLOBALS['TTS_FALLBACK_FNCT'] = $plan['local_callbacks']['fallback'];
        }
        return $generate();
    } finally {
        foreach ($keys as $key) {
            unset($GLOBALS[$key]);
            if (array_key_exists($key, $previous)) $GLOBALS[$key] = $previous[$key];
        }
    }
}
