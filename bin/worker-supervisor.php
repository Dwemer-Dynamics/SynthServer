<?php

/**
 * Starts the worker supervisor: a long-running process that keeps running the
 * registered workers with bin/worker.php.
 *
 *   php bin/worker-supervisor.php [--workers=a,b] [--idle-seconds=30] [--max-idle-seconds=300] [--max-seconds=120]
 *
 * Without --workers it runs every registered worker. It stops on SIGTERM or
 * Ctrl+C after the run in progress, and exits with code 2 when another
 * supervisor is already running. It is not started by default: service/start.sh
 * and service/manager.php keep their current behaviour.
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

$known = WorkerRegistry::names();
$workers = isset($options['workers'])
    ? array_values(array_filter(array_map('trim', explode(',', $options['workers']))))
    : $known;
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
        (int) ($options['max-idle-seconds'] ?? 300)
    );
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
$supervisor = new WorkerSupervisor($scheduler, $workers, (int) ($options['max-seconds'] ?? 120));
if (!$supervisor->run()) {
    fwrite(STDERR, 'Another worker supervisor is already running.' . PHP_EOL);
    exit(2);
}
exit(0);
