<?php

/**
 * Describes a background worker: a job that runs once, does a bounded amount of work, and exits.
 *
 * A worker keeps no cursor of its own and does not know where its progress is
 * stored. The supervisor decides when to run it again.
 */
declare(strict_types=1);

namespace Synth\Workers;

interface WorkerInterface
{
    /**
     * Returns the name used on the command line and in logs, such as "memory".
     */
    public function name(): string;

    /**
     * Returns one line describing what the worker does.
     */
    public function description(): string;

    /**
     * Counts the units of work waiting now, without calling a provider or writing anything.
     */
    public function pendingUnits(): int;

    /**
     * Does pending work until nothing is left or a limit is reached, and reports what happened.
     */
    public function run(WorkerLimits $limits): WorkerReport;
}
