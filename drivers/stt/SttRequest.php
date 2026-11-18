<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt;

/** One transcription request: a WAV file on disk plus the optional hints the caller already gathered. */
final class SttRequest
{
    /**
     * @param list<string> $keywords proper nouns that may be spoken, most recent first
     * @param string $hint the ready-made prompt text for providers that take one string (Whisper style)
     */
    public function __construct(
        public readonly string $audioPath,
        public readonly array $keywords = [],
        public readonly string $hint = '',
    ) {
    }
}
