<?php

/**
 * Runs one background worker once and reports the result; meant to be launched by a supervisor.
 *
 *   php bin/worker.php list
 *   php bin/worker.php <name> --check
 *   php bin/worker.php <name> [--max-units=N] [--max-seconds=S] [--loop] [--sleep=S]
 *
 * "list" prints the workers. "--job=<file>" is the one option a worker may take of its own
 * (the on-demand "profile" worker). "--check" counts the pending work without calling
 * a provider or writing anything. Otherwise the worker does at most N units
 * (default 1) or S seconds (default 120) of work. Every run prints one JSON
 * line and exits with: 0 done, 3 more work pending, 4 nothing could be done
 * now, 1 the run failed. "--loop" repeats the run every --sleep seconds
 * (default 30) until stopped, printing one JSON line per run.
 */
declare(strict_types=1);

use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerRegistry;
use Synth\Workers\WorkerReport;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$arguments = array_slice($argv, 1);
$name = $arguments[0] ?? '';
$options = [];
foreach (array_slice($arguments, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) !== 1) {
        fwrite(STDERR, "Unknown argument '{$argument}'." . PHP_EOL);
        exit(1);
    }
    $options[$match[1]] = $match[2] ?? true;
}

if ($name === '' || $name === 'list') {
    foreach (WorkerRegistry::names() as $workerName) {
        echo json_encode(['worker' => $workerName, 'description' => WorkerRegistry::create($workerName)->description()], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    exit(0);
}

try {
    $worker = WorkerRegistry::create($name, $options);
    if (isset($options['check'])) {
        $pending = $worker->pendingUnits();
        echo json_encode(['worker' => $name, 'units_pending' => $pending], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($pending === 0 ? 0 : 3);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

$loop = isset($options['loop']);
$sleep = max(1, (int) ($options['sleep'] ?? 30));
do {
    $startedAt = microtime(true);
    try {
        $report = $worker->run(new WorkerLimits((int) ($options['max-units'] ?? 1), (int) ($options['max-seconds'] ?? 120)));
    } catch (Throwable $exception) {
        // Exception text may carry provider details; keep the printed report generic.
        $report = WorkerReport::failed($name, get_class($exception));
    }
    echo json_encode($report->toArray((int) round((microtime(true) - $startedAt) * 1000)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if ($loop && $report->status() !== WorkerReport::MORE_PENDING) {
        sleep($sleep);
    }
} while ($loop);

exit($report->exitCode());
