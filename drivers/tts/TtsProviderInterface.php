<?php

declare(strict_types=1);

namespace Synth\Drivers\Tts;

/**
 * One speech provider (XTTS, PocketTTS, Cartesia and so on): turns one line of text into a cached audio file.
 *
 * Providers read their own block of the legacy TTS settings globals and write the speech cache, so a provider is
 * only ever invoked through TtsProviderRegistry (the tts/tts-*.php hooks), never constructed by managers. Managers
 * use TtsWrapper.
 */
interface TtsProviderInterface
{
    /** Connector key, the TTSFUNCTION value that selects this provider (for example "pockettts"). */
    public function key(): string;

    /**
     * @param string $textString line to speak
     * @param string $mood emotion label or "default"
     * @param string $stringforhash cache seed; the audio is stored under md5(trim($stringforhash))
     * @return string|false cache-relative or absolute path of the audio, or false when the provider failed
     */
    public function synthesize($textString, $mood, $stringforhash);
}
