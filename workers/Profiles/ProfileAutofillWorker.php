<?php

/**
 * Registers `processor/profile_autofill_worker.php` as the on-demand "profile" worker.
 *
 * Profile autofill is started by a request, not on a timer: `synthSpawnProfileAutofillWorker`
 * writes the job file and runs `php bin/worker.php profile --job=<file>` detached. The run
 * does that one job in a fresh process and releases its lock, as the script always has.
 * Without `--job` there is nothing to do, so the supervisor lanes do not list this worker.
 */
declare(strict_types=1);

namespace Synth\Workers\Profiles;

use Synth\Workers\Legacy\LegacyDispatchWorker;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerReport;

final class ProfileAutofillWorker extends LegacyDispatchWorker
{
    public const NAME = 'profile';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Fills an NPC profile from recent events (on demand: bin/worker.php profile --job=<file>).';
    }

    /**
     * Returns 0: jobs are started by requests, there is no queue to count.
     */
    public function pendingUnits(): int
    {
        return 0;
    }

    public function run(WorkerLimits $limits): WorkerReport
    {
        if ($this->jobPath() === null) {
            return WorkerReport::idle(self::NAME, 'no job: pass --job=<job file in data/tmp/profile_autofill_jobs>');
        }

        return parent::run($limits);
    }

    protected function script(): string
    {
        return 'processor/profile_autofill_worker.php';
    }

    protected function arguments(): array
    {
        return [(string) $this->jobPath()];
    }

    /**
     * The script prints nothing on success, so a finished job is "done", not a counted unit.
     */
    protected function doneMarker(): string
    {
        return 'Profile autofill job completed.';
    }

    /**
     * Returns the job file named by --job when it lies in the autofill job directory, or null.
     */
    private function jobPath(): ?string
    {
        $job = $this->options['job'] ?? null;
        if (!is_string($job) || $job === '') {
            return null;
        }
        $directory = realpath(dirname(__DIR__, 2) . '/data/tmp/profile_autofill_jobs');
        $path = realpath($job);
        if ($directory === false || $path === false
            || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !str_ends_with($path, '.json')) {
            return null;
        }

        return $path;
    }
}
