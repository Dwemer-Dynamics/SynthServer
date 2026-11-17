<?php

declare(strict_types=1);

// A voice preview failure that carries the HTTP status and fixed client-facing text for the route to answer with.

namespace Synth\Managers\VoiceSample;

use RuntimeException;

final class VoicePreviewException extends RuntimeException
{
    /**
     * Builds a failure the route can send as is: $status is the HTTP status, $reason a stable code, $message fixed text.
     */
    public function __construct(public readonly int $status, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
