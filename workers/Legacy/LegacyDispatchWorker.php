<?php

/**
 * Runs a legacy `service/*_dispatch.php` script as a registered worker.
 *
 * Each dispatch script already finds, reserves and runs one unit of work, and
 * must run in a fresh CLI process (it opens its own database connection). This
 * adapter only launches that script repeatedly within the run's limits and
 * reads its one-line result. It owns no logic and touches no storage, so moving
 * the logic into a worker class replaces the subclass, not the contract.
 */
declare(strict_types=1);

namespace Synth\Workers\Legacy;

use Closure;
use Synth\Workers\WorkerInterface;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerReport;

abstract class LegacyDispatchWorker implements WorkerInterface
{
    /** @var Closure(string, list<string>): array{0: int, 1: string} Runs a script in a fresh process; returns its exit code and standard output. */
    private readonly Closure $launcher;

    /**
     * @param Closure(string, list<string>): array{0: int, 1: string}|null $launcher Replaces the process launcher; used by tests.
     * @param array<string, string|true> $options Options of the command line (`bin/worker.php <name> --job=...`); most workers take none.
     */
    public function __construct(?Closure $launcher = null, protected readonly array $options = [])
    {
        $this->launcher = $launcher ?? self::launchProcess(...);
    }

    /**
     * Returns the dispatch script path relative to the server root, such as "service/memory_dispatch.php".
     */
    abstract protected function script(): string;

    /**
     * Returns the text the script prints when it did a unit of work.
     */
    abstract protected function doneMarker(): string;

    /**
     * Returns the command-line arguments passed to the script; none unless a worker overrides this.
     *
     * @return list<string>
     */
    protected function arguments(): array
    {
        return [];
    }

    /**
     * Returns 1: a dispatch script cannot count without reserving work, so one pass is always worth trying.
     */
    public function pendingUnits(): int
    {
        return 1;
    }

    public function run(WorkerLimits $limits): WorkerReport
    {
        $report = new WorkerReport($this->name());
        $script = dirname(__DIR__, 2) . '/' . $this->script();
        $attempted = 0;
        $limitReached = false;
        while (true) {
            if ($limits->reached($attempted)) {
                $limitReached = true;
                break;
            }
            $attempted++;
            [$exitCode, $output] = ($this->launcher)($script, $this->arguments());
            if ($exitCode !== 0) {
                // The script's own messages are generic by design; nothing from a provider is relayed.
                $report->addFailure($this->script(), "exit code {$exitCode}");
                break;
            }
            if (!str_contains($output, $this->doneMarker())) {
                // A pass that found nothing: the queue is empty for now.
                break;
            }
            $report->unitsDone++;
        }

        // Work remains only when a limit stopped a run that was still finding some.
        return $report->finish($limitReached && $report->unitsDone > 0 ? 1 : 0);
    }

    /**
     * Starts the script with the current PHP binary in a fresh process; its error output is discarded.
     *
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private static function launchProcess(string $script, array $arguments = []): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open([PHP_BINARY, $script, ...$arguments], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($process)) {
            return [1, ''];
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
