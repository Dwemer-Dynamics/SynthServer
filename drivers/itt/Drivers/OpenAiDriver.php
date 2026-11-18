<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

/** OpenAI vision ("openai"). */
final class OpenAiDriver extends OpenAiCompatibleIttDriver
{
    public const NAME = 'openai';

    public function __construct(array $config)
    {
        parent::__construct(self::NAME, $config);
    }
}
