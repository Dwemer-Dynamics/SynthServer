<?php

/**
 * Tells the supervisor what one worker run did, as a JSON line and an exit code.
 */
declare(strict_types=1);

namespace Synth\Workers;

final class WorkerReport
{
    // Finished and nothing is left.
    public const DONE = 'done';
    // Finished a batch and more work is waiting: run again soon.
    public const MORE_PENDING = 'more_pending';
    // Nothing could be done now: disabled, locked, or waiting to retry.
    public const IDLE = 'idle';
    // The run itself failed, for example the database is down.
    public const FAILED = 'failed';

    private const EXIT_CODES = [
        self::DONE => 0,
        self::FAILED => 1,
        self::MORE_PENDING => 3,
        self::IDLE => 4,
    ];

    public int $unitsDone = 0;
    public int $unitsSkipped = 0;
    public int $unitsFailed = 0;
    public int $unitsPending = 0;

    /** @var array<int, string> */
    public array $errors = [];

    private string $status = self::DONE;
    private ?string $reason = null;

    /**
     * Starts an empty report for a worker.
     */
    public function __construct(public readonly string $worker)
    {
    }

    /**
     * Returns a report for a run that could not start its work, with the reason.
     */
    public static function idle(string $worker, string $reason): self
    {
        $report = new self($worker);
        $report->status = self::IDLE;
        $report->reason = $reason;

        return $report;
    }

    /**
     * Returns a report for a run that failed as a whole.
     */
    public static function failed(string $worker, string $error): self
    {
        $report = new self($worker);
        $report->status = self::FAILED;
        $report->errors[] = $error;

        return $report;
    }

    /**
     * Records a unit that failed and why; it will be tried again on a later run.
     */
    public function addFailure(string $unit, string $error): void
    {
        $this->unitsFailed++;
        $this->errors[] = "{$unit}: {$error}";
    }

    /**
     * Sets what is still pending after the run and the status that follows from it.
     *
     * A run whose only outcome was failures is failed. With nothing pending
     * the run is done. With work pending it is "more_pending" when this run
     * made progress, and idle when it could not, so the supervisor does not
     * relaunch it in a tight loop.
     */
    public function finish(int $pending): self
    {
        $this->unitsPending = $pending;
        $this->status = match (true) {
            $this->unitsFailed > 0 && $this->unitsDone === 0 => self::FAILED,
            $pending === 0 => self::DONE,
            $this->unitsDone === 0 => self::IDLE,
            default => self::MORE_PENDING,
        };

        return $this;
    }

    /**
     * Returns the status of the run.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * Returns the process exit code for the status.
     */
    public function exitCode(): int
    {
        return self::EXIT_CODES[$this->status];
    }

    /**
     * Builds the JSON object printed by bin/worker.php.
     *
     * @return array<string, mixed>
     */
    public function toArray(int $durationMs): array
    {
        $result = [
            'worker' => $this->worker,
            'status' => $this->status,
            'units_done' => $this->unitsDone,
            'units_skipped' => $this->unitsSkipped,
            'units_failed' => $this->unitsFailed,
            'units_pending' => $this->unitsPending,
            'duration_ms' => $durationMs,
            'errors' => $this->errors,
        ];

        return $this->reason === null ? $result : $result + ['reason' => $this->reason];
    }
}
