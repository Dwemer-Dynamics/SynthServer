<?php

/**
 * Registers the legacy manager task "dynamicprofile" as the "dynamicprofile" worker.
 */
declare(strict_types=1);

namespace Synth\Workers\Profiles;

use Synth\Workers\Legacy\LegacyTaskWorker;

final class DynamicProfileWorker extends LegacyTaskWorker
{
    public const NAME = 'dynamicprofile';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Updates dynamic NPC profiles and runs the diary jobs.';
    }

    protected function task(): string
    {
        return 'dynamicprofile';
    }
}
