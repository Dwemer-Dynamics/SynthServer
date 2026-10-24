<?php

// Request-local prosody only; voice identity, cloning and owned worker state stay elsewhere.
function synth_cartesia_modern_model(string $model): bool
{
    return $model === 'sonic-latest'
        || preg_match('/^sonic-3(?:\.[0-9]+)?(?:-[0-9]{4}-[0-9]{2}-[0-9]{2})?$/D', $model) === 1;
}

function synth_cartesia_generation_speed(mixed $speed): float
{
    if (is_int($speed) || is_float($speed) || (is_string($speed) && is_numeric(trim($speed)))) {
        $value = floatval($speed);
        return is_finite($value) ? max(0.6, min(1.5, $value)) : 1.0;
    }
    return ['slowest' => 0.6, 'slow' => 0.85, 'normal' => 1.0, 'fast' => 1.15, 'fastest' => 1.5]
        [is_string($speed) ? strtolower(trim($speed)) : 'normal'] ?? 1.0;
}

function synth_cartesia_generation_emotion(mixed $mood): ?string
{
    if (!is_string($mood) || strlen($mood) > 128) return null;
    $primary = strtolower(trim(explode('|', $mood, 2)[0]));
    $aliases = ['furious'=>'angry', 'enraged'=>'angry', 'irritated'=>'frustrated', 'annoyed'=>'frustrated',
        'cheerful'=>'happy', 'joyful'=>'happy', 'playful'=>'happy', 'amused'=>'happy', 'joking'=>'happy',
        'comedic'=>'happy', 'joking/comedic'=>'happy', 'melancholy'=>'sad', 'gloomy'=>'sad',
        'depressed'=>'disappointed', 'sorrowful'=>'hurt', 'shocked'=>'surprised', 'astonished'=>'surprised',
        'inquisitive'=>'curious', 'interested'=>'curious', 'afraid'=>'scared', 'fearful'=>'scared', 'worried'=>'anxious'];
    $primary = $aliases[$primary] ?? $primary;
    // Keep this allowlist aligned with the provider's documented English emotions.
    $allowed = ['neutral','happy','excited','enthusiastic','elated','euphoric','triumphant','amazed','surprised',
        'flirtatious','curious','content','peaceful','serene','calm','grateful','affectionate','trust','sympathetic',
        'anticipation','mysterious','angry','mad','outraged','frustrated','agitated','threatened','disgusted','contempt',
        'envious','sarcastic','ironic','sad','dejected','melancholic','disappointed','hurt','guilty','bored','tired',
        'rejected','nostalgic','wistful','apologetic','hesitant','insecure','confused','resigned','anxious','panicked',
        'alarmed','scared','proud','confident','distant','skeptical','contemplative','determined'];
    return in_array($primary, $allowed, true) ? $primary : null;
}

function synth_cartesia_generation_request(string $text, string $voiceId, mixed $mood, array $settings, string $language): array
{
    $model = strval($settings['model_id'] ?? 'sonic-3');
    $modern = synth_cartesia_modern_model($model);
    $body = ['model_id'=>$model, 'transcript'=>$text,
        'voice'=>$modern ? $voiceId : ['mode'=>'id', 'id'=>$voiceId], 'language'=>$language,
        'output_format'=>['container'=>'wav', 'encoding'=>'pcm_s16le', 'sample_rate'=>22050]];
    if ($modern) {
        $body['generation_config'] = ['speed'=>synth_cartesia_generation_speed($settings['speed'] ?? 'normal')];
        $emotion = synth_cartesia_generation_emotion($mood);
        $enabled = filter_var($settings['use_emotions'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($enabled && preg_match('/^en(?:[-_][a-z0-9]+)*$/iD', $language) === 1 && $emotion !== null) {
            $body['generation_config']['emotion'] = $emotion;
        }
    } else {
        // Preserve earlier-model wire behavior rather than send unsupported Sonic-3 controls.
        $body['speed'] = $settings['speed'] ?? 'normal';
    }
    return ['body'=>$body, 'api_version'=>$modern ? '2026-08-14' : '2024-11-13', 'modern'=>$modern];
}
