<?php

require_once dirname(__DIR__) . '/drivers/tts/SpeechAudio.php';

// Thin delegates: the logic lives in drivers/tts/SpeechAudio.php.

// Usable synthesized speech: a valid WAV that is not digital silence. Silent output is treated as no audio,
// so the line stays text only instead of playing nothing.
function synth_speech_bytes_usable(string $bytes): bool
{
    return \Synth\Drivers\Tts\SpeechAudio::bytesUsable($bytes);
}

// A cache hit must be usable audio, not a truncated/error body that permanently prevents synthesis.
function synth_speech_cache_valid(string $path): bool
{
    return \Synth\Drivers\Tts\SpeechAudio::cacheValid($path);
}

// Publish an already valid WAV without re-encoding it or exposing a partial public file.
function synth_publish_speech_cache(string $bytes, string $target): bool
{
    return \Synth\Drivers\Tts\SpeechAudio::publish($bytes, $target);
}

// Internal final boundary shared by validated direct WAV and completed converter output.
function synth_commit_speech_cache_file(string $output, string $target): bool
{
    return \Synth\Drivers\Tts\SpeechAudio::commitFile($output, $target);
}

// Convert outside the public cache, cancel the direct child promptly, and publish only under current ownership.
function synth_transcode_speech_cache(string $bytes, string $target, string $filter, ?string $ffmpeg = null, int $timeoutMs = 30000, ?array $pcmInput = null): bool
{
    return \Synth\Drivers\Tts\SpeechAudio::transcode($bytes, $target, $filter, $ffmpeg, $timeoutMs, $pcmInput);
}
