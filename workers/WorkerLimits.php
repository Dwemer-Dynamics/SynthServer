<?php

/**
 * Holds how much work one run may do: a number of units and a number of seconds.
 */
declare(strict_types=1);

namespace Synth\Workers;

use InvalidArgumentException;

final class WorkerLimits
{
    private readonly float $startedAt;

    /**
     * Starts the clock of the run.
     *
     * @throws InvalidArgumentException When a limit is not positive.
     */
    public function __construct(public readonly int $maxUnits = 1, public readonly int $maxSeconds = 120)
    {
        if ($maxUnits < 1 || $maxSeconds < 1) {
            throw new InvalidArgumentException('Worker limits must be positive.');
        }
        $this->startedAt = microtime(true);
    }

    /**
     * Tells whether the run must stop after having attempted $unitsAttempted units.
     *
     * A unit already started is always finished; the time limit only
     * prevents starting the next one.
     */
    public function reached(int $unitsAttempted): bool
    {
        return $unitsAttempted >= $this->maxUnits || microtime(true) - $this->startedAt >= $this->maxSeconds;
    }

    /**
     * Returns the milliseconds since the run started.
     */
    public function elapsedMilliseconds(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
