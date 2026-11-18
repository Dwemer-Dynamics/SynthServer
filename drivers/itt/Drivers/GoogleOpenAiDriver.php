<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

/** Google's OpenAI-compatible Gemini endpoint ("google_openai"). */
final class GoogleOpenAiDriver extends OpenAiCompatibleIttDriver
{
    public const NAME = 'google_openai';

    public function __construct(array $config)
    {
        parent::__construct(self::NAME, $config);
    }
}
