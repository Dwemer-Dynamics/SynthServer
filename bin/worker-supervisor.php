<?php

/**
 * Starts the worker supervisor: a long-running process that keeps running the
 * registered workers with bin/worker.php.
 *
 *   php bin/worker-supervisor.php [--lane=general|speech] [--workers=a,b] [--idle-seconds=30] [--max-idle-seconds=300] [--max-seconds=120]
 *
 * This is the default background runtime: service/start.sh starts one supervisor per lane
 * (WorkerRegistry::LANES). Without --workers it runs the workers of its lane, each on the
 * fixed cadence in WorkerRegistry::FIXED_SECONDS; workers named with --workers that have no
 * fixed cadence use the idle back-off options. It stops on SIGTERM or Ctrl+C after the run in
 * progress, and exits with code 2 when another supervisor of the same lane is already running.
 * service/manager.php is only started by service/start.sh when SYNTH_LEGACY_MANAGER=1.
 */
declare(strict_types=1);

use Synth\Workers\Supervisor\WorkerScheduler;
use Synth\Workers\Supervisor\WorkerSupervisor;
use Synth\Workers\WorkerRegistry;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.+)$/', $argument, $match) !== 1) {
        fwrite(STDERR, "Unknown argument '{$argument}'." . PHP_EOL);
        exit(1);
    }
    $options[$match[1]] = $match[2];
}

$lane = $options['lane'] ?? WorkerSupervisor::DEFAULT_LANE;
$laneWorkers = WorkerRegistry::lane($lane);
if ($laneWorkers === null) {
    fwrite(STDERR, "Unknown lane '{$lane}'. Known lanes: " . implode(', ', array_keys(WorkerRegistry::LANES)) . '.' . PHP_EOL);
    exit(1);
}
$known = WorkerRegistry::names();
$workers = isset($options['workers'])
    ? array_values(array_filter(array_map('trim', explode(',', $options['workers']))))
    : $laneWorkers;
$unknown = array_diff($workers, $known);
if ($unknown !== [] || $workers === []) {
    fwrite(STDERR, 'Unknown or no workers: ' . implode(', ', $unknown) . '. Known workers: ' . implode(', ', $known) . '.' . PHP_EOL);
    exit(1);
}

try {
    $scheduler = new WorkerScheduler(
        $workers,
        2,
        (int) ($options['idle-seconds'] ?? 30),
        (int) ($options['max-idle-seconds'] ?? 300),
        array_intersect_key(WorkerRegistry::FIXED_SECONDS, array_flip($workers))
    );
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
$supervisor = new WorkerSupervisor($scheduler, $workers, (int) ($options['max-seconds'] ?? 120), $lane);
if (!$supervisor->run()) {
    fwrite(STDERR, "Another worker supervisor is already running for lane '{$lane}'." . PHP_EOL);
    exit(2);
}
exit(0);
