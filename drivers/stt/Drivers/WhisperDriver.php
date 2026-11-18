<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

/** OpenAI Whisper API ("whisper"). */
final class WhisperDriver extends OpenAiCompatibleSttDriver
{
    public const NAME = 'whisper';
    public const URL = 'https://api.openai.com/v1/audio/transcriptions';

    public function __construct(array $config)
    {
        parent::__construct(self::NAME, $config + ['url' => self::URL]);
    }
}
