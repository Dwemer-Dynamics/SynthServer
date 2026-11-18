<?php

/**
 * Keeps the background workers running: it starts `bin/worker.php <name>` whenever
 * WorkerScheduler says a worker is due, one run at a time, and writes a heartbeat file.
 *
 * Each run is its own PHP process, so a crash or leak never takes the supervisor
 * down and every run starts with fresh settings and a fresh database connection.
 * The supervisor itself never opens the database. One supervisor runs per
 * installation: a second one finds the lock file held and exits.
 */
declare(strict_types=1);

namespace Synth\Workers\Supervisor;

use RuntimeException;
use Synth\Workers\WorkerReport;

final class WorkerSupervisor
{
    public const HEARTBEAT_FILE = __DIR__ . '/../../log/worker-supervisor.json';
    public const LOCK_FILE = __DIR__ . '/../../log/worker-supervisor.lock';

    private const WORKER_SCRIPT = __DIR__ . '/../../bin/worker.php';
    private const HEARTBEAT_SECONDS = 30;

    private bool $stopRequested = false;

    /** @var array<string, array<string, mixed>> */
    private array $lastRuns = [];

    /** @var resource|null */
    private $lock = null;

    private float $startedAt;

    /**
     * @param list<string> $workers
     */
    public function __construct(
        private readonly WorkerScheduler $scheduler,
        private readonly array $workers,
        private readonly int $maxSeconds = 120,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Runs workers until SIGTERM or SIGINT arrives; returns false when another supervisor already runs.
     */
    public function run(): bool
    {
        if (!$this->acquireLock()) {
            return false;
        }

        if (function_exists('pcntl_async_signals')) {
            // Stop between runs; a run in progress finishes its unit.
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopRequested = true);
            pcntl_signal(SIGINT, fn () => $this->stopRequested = true);
        }

        self::log('Started with workers: ' . implode(', ', $this->workers));
        $lastHeartbeat = 0.0;
        while (!$this->stopRequested) {
            $now = microtime(true);
            $worker = $this->scheduler->next($now);
            if ($worker !== null) {
                $this->runWorker($worker);
                $this->writeHeartbeat();
                $lastHeartbeat = microtime(true);
                continue;
            }
            if ($now - $lastHeartbeat >= self::HEARTBEAT_SECONDS) {
                $this->writeHeartbeat();
                $lastHeartbeat = $now;
            }
            // Short sleeps so a stop signal is noticed quickly.
            usleep((int) (min(1.0, $this->scheduler->secondsUntilNext($now)) * 1_000_000) + 1000);
        }

        self::log('Stopped.');
        @unlink(self::HEARTBEAT_FILE);
        flock($this->lock, LOCK_UN);

        return true;
    }

    /**
     * Runs one worker as a child process, reads the JSON line it prints and plans its next run.
     */
    private function runWorker(string $worker): void
    {
        $startedAt = microtime(true);
        $errorFile = tmpfile();
        $process = proc_open(
            [PHP_BINARY, self::WORKER_SCRIPT, $worker, '--max-seconds=' . $this->maxSeconds],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $errorFile],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Unable to start bin/worker.php {$worker}.");
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        rewind($errorFile);
        $errors = self::lastLines((string) stream_get_contents($errorFile));
        fclose($errorFile);

        $report = self::lastJsonLine($output);
        if ($report === null) {
            // The run died before printing its report (a fatal error, out of memory, a kill).
            $report = WorkerReport::failed($worker, $errors !== '' ? $errors : "exit code {$exitCode}, no report")
                ->toArray((int) round((microtime(true) - $startedAt) * 1000));
        }
        $status = is_string($report['status'] ?? null) ? $report['status'] : WorkerReport::FAILED;
        $unitsDone = is_int($report['units_done'] ?? null) ? $report['units_done'] : 0;

        $finishedAt = microtime(true);
        $this->scheduler->record($worker, $status, $unitsDone, $finishedAt);
        $this->lastRuns[$worker] = [
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $finishedAt),
            'next_run_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->scheduler->dueAt($worker)),
            'report' => $report,
        ];

        // Quiet runs are not logged, or an idle server would fill the log.
        if ($unitsDone > 0 || $status === WorkerReport::FAILED || ($report['units_failed'] ?? 0) > 0) {
            self::log(json_encode($report, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Writes what the supervisor is doing, replacing the file in one step.
     */
    private function writeHeartbeat(): void
    {
        $now = microtime(true);
        $runs = [];
        foreach ($this->workers as $worker) {
            $runs[$worker] = $this->lastRuns[$worker]
                ?? ['next_run_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->scheduler->dueAt($worker))];
        }
        $heartbeat = [
            'pid' => getmypid(),
            'started_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->startedAt),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $now),
            'updated_at_unix' => (int) $now,
            'workers' => $runs,
        ];

        $temporary = self::HEARTBEAT_FILE . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, json_encode($heartbeat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            self::log("Unable to write {$temporary}.");
            return;
        }
        rename($temporary, self::HEARTBEAT_FILE);
    }

    /**
     * Takes the installation-wide lock that keeps a second supervisor from starting.
     */
    private function acquireLock(): bool
    {
        $lock = fopen(self::LOCK_FILE, 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open ' . self::LOCK_FILE . '.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        $this->lock = $lock;

        return true;
    }

    /**
     * Returns the last line of a run's output that is a JSON object naming a worker, which is its report.
     *
     * @return array<string, mixed>|null
     */
    public static function lastJsonLine(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['worker'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Keeps the end of a run's error output, where PHP prints the fatal error that stopped it.
     */
    private static function lastLines(string $errors): string
    {
        $errors = trim($errors);

        return strlen($errors) > 1000 ? '...' . substr($errors, -1000) : $errors;
    }

    /**
     * Prints one timestamped line; the launcher redirects standard output to its service log.
     */
    private static function log(string $message): void
    {
        echo '[WORKER-SUPERVISOR] ' . gmdate('Y-m-d\TH:i:s\Z') . ' ' . $message . PHP_EOL;
    }
}
