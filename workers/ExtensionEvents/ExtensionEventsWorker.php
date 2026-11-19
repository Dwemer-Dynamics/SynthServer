<?php

/**
 * Delivers deferred extension events: drains the file queue ExtensionManager writes when "deferred_events" is on.
 */
declare(strict_types=1);

namespace Synth\Workers\ExtensionEvents;

require_once dirname(__DIR__, 2) . '/managers/ExtensionManager/ExtensionManager.php';

use Synth\Managers\ExtensionManager\ExtensionManager;
use Synth\Managers\ExtensionManager\FileEventQueue;
use Synth\Workers\WorkerInterface;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerReport;

final class ExtensionEventsWorker implements WorkerInterface
{
    public const NAME = 'extension-events';

    private readonly string $root;
    private readonly FileEventQueue $queue;

    /**
     * @param string|null $root Server root; tests pass a throwaway one.
     */
    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2);
        $this->queue = new FileEventQueue($this->root . '/data/extensions/events');
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Delivers queued game and dialogue events to enabled extensions.';
    }

    public function pendingUnits(): int
    {
        return $this->queue->count();
    }

    public function run(WorkerLimits $limits): WorkerReport
    {
        $report = new WorkerReport($this->name());
        // A fresh manager per run, so an extension switched on or off since the last run is seen.
        $manager = new ExtensionManager($this->root);
        $attempted = 0;
        // Each claimed event is delivered once. A handler failure is isolated and counted by ExtensionManager
        // (repeated failures switch the extension off); it never fails the run or puts the event back.
        while (!$limits->reached($attempted)) {
            $events = $this->queue->claim(1);
            if ($events === []) {
                break;
            }
            $attempted++;
            $event = $events[0];
            try {
                if ($manager->deliver($event['extension'], $event['hook'], $event['payload'])) {
                    $report->unitsDone++;
                } else {
                    $report->unitsSkipped++;
                }
            } catch (\Throwable) {
                $report->addFailure($event['extension'], 'delivery_failed');
            }
            $this->queue->acknowledge($event['claim']);
        }

        return $report->finish($this->queue->count());
    }
}
