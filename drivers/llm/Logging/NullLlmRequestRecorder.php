<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

/** Records nothing: for callers that must not log (the replay of a request that is being inspected, tests). */
final class NullLlmRequestRecorder implements LlmRequestRecorder
{
    public function begin(array $entry): ?int
    {
        return null;
    }

    public function end(?int $id, array $result): void
    {
    }
}
