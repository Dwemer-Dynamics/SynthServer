<?php

/**
 * Decides which worker the supervisor runs next, and when.
 *
 * It does no input or output and reads no clock: the caller passes the time in.
 *
 * - A run that left work (`more_pending`) is repeated after `pendingSeconds`.
 * - Any other run waits `idleSeconds`, doubling each time a run does nothing,
 *   up to `maxIdleSeconds`; a run that did work resets the wait.
 * - Workers listed after one that did work become due at once, because they
 *   may work on what it produced.
 */
declare(strict_types=1);

namespace Synth\Workers\Supervisor;

use InvalidArgumentException;
use Synth\Workers\WorkerReport;

final class WorkerScheduler
{
    /** @var array<string, float> */
    private array $dueAt = [];

    /** @var array<string, int> */
    private array $idleWait = [];

    /**
     * @param list<string> $workers In the order they feed each other; all are due at once.
     */
    public function __construct(
        private readonly array $workers,
        private readonly int $pendingSeconds = 2,
        private readonly int $idleSeconds = 30,
        private readonly int $maxIdleSeconds = 300,
    ) {
        if ($workers === [] || $pendingSeconds < 0 || $idleSeconds < 1 || $maxIdleSeconds < $idleSeconds) {
            throw new InvalidArgumentException('The scheduler needs at least one worker and idle <= max idle.');
        }
        foreach ($workers as $worker) {
            $this->dueAt[$worker] = 0.0;
            $this->idleWait[$worker] = $idleSeconds;
        }
    }

    /**
     * Returns the due worker that has waited longest, the earlier one in the list on a tie, or null.
     */
    public function next(float $now): ?string
    {
        $chosen = null;
        foreach ($this->workers as $worker) {
            if ($this->dueAt[$worker] <= $now && ($chosen === null || $this->dueAt[$worker] < $this->dueAt[$chosen])) {
                $chosen = $worker;
            }
        }

        return $chosen;
    }

    /**
     * Returns the seconds until some worker is due, 0 when one already is.
     */
    public function secondsUntilNext(float $now): float
    {
        return max(0.0, min($this->dueAt) - $now);
    }

    /**
     * Returns when a worker runs next, in the same seconds as the clock passed in.
     */
    public function dueAt(string $worker): float
    {
        return $this->dueAt[$worker] ?? throw new InvalidArgumentException("Unknown worker '{$worker}'.");
    }

    /**
     * Plans a worker's next run from the status its last run ended with and the units it completed.
     */
    public function record(string $worker, string $status, int $unitsDone, float $now): void
    {
        if (!isset($this->dueAt[$worker])) {
            throw new InvalidArgumentException("Unknown worker '{$worker}'.");
        }

        if ($status === WorkerReport::MORE_PENDING) {
            $this->dueAt[$worker] = $now + $this->pendingSeconds;
            $this->idleWait[$worker] = $this->idleSeconds;
        } elseif ($unitsDone > 0) {
            $this->dueAt[$worker] = $now + $this->idleSeconds;
            $this->idleWait[$worker] = $this->idleSeconds;
        } else {
            $this->dueAt[$worker] = $now + $this->idleWait[$worker];
            $this->idleWait[$worker] = min($this->idleWait[$worker] * 2, $this->maxIdleSeconds);
        }

        if ($unitsDone > 0) {
            foreach (array_slice($this->workers, (int) array_search($worker, $this->workers, true) + 1) as $later) {
                $this->dueAt[$later] = min($this->dueAt[$later], $now);
                $this->idleWait[$later] = $this->idleSeconds;
            }
        }
    }
}
