<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Drivers;

use Synth\Drivers\Stt\SttDriverInterface;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttResult;

/** Speech input disabled ("none"): hears nothing. */
final class NoneDriver implements SttDriverInterface
{
    public const NAME = 'none';

    public function getName(): string
    {
        return self::NAME;
    }

    public function transcribe(SttRequest $request): SttResult
    {
        return new SttResult('');
    }
}
