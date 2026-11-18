<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

/** Any other OpenAI-compatible vision endpoint ("custom"). */
final class CustomDriver extends OpenAiCompatibleIttDriver
{
    public const NAME = 'custom';

    public function __construct(array $config)
    {
        parent::__construct(self::NAME, $config);
    }
}
