<?php

/**
 * Registers the native memory dispatch (`service/memory_dispatch.php`) as the "memory" worker.
 */
declare(strict_types=1);

namespace Synth\Workers\Memory;

use Synth\Workers\Legacy\LegacyDispatchWorker;

final class MemoryWorker extends LegacyDispatchWorker
{
    public const NAME = 'memory';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Creates NPC memory summaries from completed dialogue turns.';
    }

    protected function script(): string
    {
        return 'service/memory_dispatch.php';
    }

    protected function doneMarker(): string
    {
        return 'Native memory dispatch completed.';
    }
}
