<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

/** Local Parakeet service ("parakeet"); speaks the OpenAI transcription wire format. */
final class ParakeetDriver extends OpenAiCompatibleSttDriver
{
    public const NAME = 'parakeet';
    public const URL = 'http://localhost:8022/v1/audio/transcriptions';

    public function __construct(array $config)
    {
        parent::__construct(self::NAME, $config + ['url' => self::URL]);
    }
}
