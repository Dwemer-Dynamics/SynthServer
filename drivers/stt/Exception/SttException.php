<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt\Exception;

/** Base class for every failure raised by the speech-to-text driver layer. */
class SttException extends \RuntimeException
{
    /** @param array<string,mixed> $diagnostic provider-neutral details the legacy hook may publish */
    public function __construct(string $message, private array $diagnostic = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
