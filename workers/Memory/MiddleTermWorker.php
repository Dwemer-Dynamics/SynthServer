<?php

/**
 * Registers the legacy manager task "middleterm" as the "middleterm" worker.
 */
declare(strict_types=1);

namespace Synth\Workers\Memory;

use Synth\Workers\Legacy\LegacyTaskWorker;

final class MiddleTermWorker extends LegacyTaskWorker
{
    public const NAME = 'middleterm';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Writes middle-term narratives and compacts old events into memory summaries.';
    }

    protected function task(): string
    {
        return 'middleterm';
    }
}
