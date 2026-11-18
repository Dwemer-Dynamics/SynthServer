<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt;

/** The transcript, plus what the provider reported besides it. */
final class SttResult
{
    /** @param array<string,mixed> $diagnostic provider details; may carry 'tone' for drivers that hear it */
    public function __construct(public readonly string $text, public readonly array $diagnostic = [])
    {
    }
}
