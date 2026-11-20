<?php


// Thin delegates: the logic lives in drivers/tts/SpeechStorage.php.

function synth_npc_tts_status_key(string $cacheKey): string
{
    return \Synth\Drivers\Tts\SpeechStorage::statusKey($cacheKey);
}

function synth_npc_tts_status_dir(?string $root = null): string
{
    return \Synth\Drivers\Tts\SpeechStorage::statusDir($root);
}

function synth_write_npc_tts_status(
    string $cacheKey,
    string $status,
    array $metadata = [],
    ?string $root = null
): bool
{
    return \Synth\Drivers\Tts\SpeechStorage::writeStatus($cacheKey, $status, $metadata, $root);
}

function synth_read_npc_tts_status(string $cacheKey, ?string $root = null): array
{
    return \Synth\Drivers\Tts\SpeechStorage::readStatus($cacheKey, $root);
}

// Resolve existing immutable audio; only the generation path opts into publication.
function synth_resolve_npc_tts_media(string $cacheKey, ?string $root = null, bool $publish = false): ?array
{
    return \Synth\Drivers\Tts\SpeechStorage::resolveMedia($cacheKey, $root, $publish);
}

// Publishes and registers ready TTS audio so status=ready always names deliverable media.
function synth_register_npc_tts_media(
    string $cacheKey,
    $db,
    array $correlation = [],
    ?string $root = null,
    array $statusMetadata = []
): ?array
{
    return \Synth\Drivers\Tts\SpeechStorage::registerMedia($cacheKey, $db, $correlation, $root, $statusMetadata);
}
