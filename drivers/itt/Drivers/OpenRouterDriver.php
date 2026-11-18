<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt\Drivers;

/** OpenRouter vision ("openrouter"); identifies the app with its referer and title headers. */
final class OpenRouterDriver extends OpenAiCompatibleIttDriver
{
    public const NAME = 'openrouter';
    protected const EXTRA_HEADERS = ['HTTP-Referer: https://dwemerdynamics.com/', 'X-Title: SYNTH PipVision'];

    public function __construct(array $config)
    {
        parent::__construct(self::NAME, $config);
    }
}
