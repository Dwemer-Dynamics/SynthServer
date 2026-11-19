<?php

/**
 * Registers the legacy manager task "player2health" as the "player2health" worker.
 */
declare(strict_types=1);

namespace Synth\Workers\Player2;

use Synth\Workers\Legacy\LegacyTaskWorker;

final class Player2HealthWorker extends LegacyTaskWorker
{
    public const NAME = 'player2health';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Checks that the Player2 connector is reachable and keeps its health state current.';
    }

    protected function task(): string
    {
        return 'player2health';
    }
}
