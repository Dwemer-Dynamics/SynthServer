<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Synth\Workers\Memory\MemoryWorker;
use Synth\Workers\Relationships\RelationshipWorker;
use Synth\Workers\Supervisor\WorkerScheduler;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerRegistry;
use Synth\Workers\WorkerReport;

foreach (['WorkerInterface', 'WorkerLimits', 'WorkerReport', 'WorkerRegistry', 'Legacy/LegacyDispatchWorker', 'Memory/MemoryWorker',
    'Relationships/RelationshipWorker', 'ExtensionEvents/ExtensionEventsWorker', 'Supervisor/WorkerScheduler'] as $file) {
    require_once dirname(__DIR__, 2) . '/workers/' . $file . '.php';
}

// Database-free: registry, limits, report status and the legacy dispatch adapter with a fake launcher.
final class WorkerFrameworkTest extends TestCase
{
    public function testRegistryListsAndCreatesWorkersAndRejectsUnknownNames(): void
    {
        $this->assertSame(['memory', 'relationship', 'extension-events'], WorkerRegistry::names());
        $this->assertInstanceOf(MemoryWorker::class, WorkerRegistry::create('memory'));
        $this->assertSame('relationship', WorkerRegistry::create('relationship')->name());
        $this->expectException(InvalidArgumentException::class);
        WorkerRegistry::create('nope');
    }

    public function testLimitsRejectNonPositiveValuesAndStopAfterMaxUnits(): void
    {
        $limits = new WorkerLimits(2, 60);
        $this->assertFalse($limits->reached(1));
        $this->assertTrue($limits->reached(2));
        $this->expectException(InvalidArgumentException::class);
        new WorkerLimits(0, 60);
    }

    public function testReportStatusFollowsPendingWorkAndFailures(): void
    {
        $done = new WorkerReport('w');
        $this->assertSame(WorkerReport::DONE, $done->finish(0)->status());

        $more = new WorkerReport('w');
        $more->unitsDone = 1;
        $this->assertSame(WorkerReport::MORE_PENDING, $more->finish(1)->status());
        $this->assertSame(3, $more->exitCode());

        $this->assertSame(WorkerReport::IDLE, (new WorkerReport('w'))->finish(2)->status());

        $failed = new WorkerReport('w');
        $failed->addFailure('unit', 'boom');
        $this->assertSame(WorkerReport::FAILED, $failed->finish(0)->status());
        $this->assertSame(1, $failed->exitCode());
    }

    public function testLegacyAdapterRunsPassesUntilTheQueueIsEmpty(): void
    {
        $outputs = ["Native memory dispatch completed.\n", "Native memory dispatch completed.\n", ''];
        $calls = [];
        $worker = new MemoryWorker(function (string $script) use (&$outputs, &$calls): array {
            $calls[] = basename($script);
            return [0, array_shift($outputs)];
        });
        $report = $worker->run(new WorkerLimits(10, 60));
        $this->assertSame(2, $report->unitsDone);
        $this->assertSame(WorkerReport::DONE, $report->status());
        $this->assertSame(array_fill(0, 3, 'memory_dispatch.php'), $calls);
    }

    public function testLegacyAdapterStopsAtTheUnitLimitWithWorkPending(): void
    {
        $worker = new RelationshipWorker(static fn (string $script): array => [0, "Native relationship dispatch completed.\n"]);
        $report = $worker->run(new WorkerLimits(3, 60));
        $this->assertSame(3, $report->unitsDone);
        $this->assertSame(WorkerReport::MORE_PENDING, $report->status());
    }

    public function testLegacyAdapterReportsAFailedPassWithoutRelayingItsOutput(): void
    {
        $worker = new MemoryWorker(static fn (string $script): array => [1, 'secret provider body']);
        $report = $worker->run(new WorkerLimits(3, 60));
        $this->assertSame(WorkerReport::FAILED, $report->status());
        $this->assertStringNotContainsString('secret', implode(' ', $report->errors));
    }

    public function testSchedulerRepeatsPendingWorkAndBacksOffWhenIdle(): void
    {
        $scheduler = new WorkerScheduler(['a', 'b'], 2, 30, 120);
        $this->assertSame('a', $scheduler->next(0.0));
        $scheduler->record('a', WorkerReport::MORE_PENDING, 1, 100.0);
        $this->assertSame(102.0, $scheduler->dueAt('a'));
        $this->assertSame(0.0, $scheduler->dueAt('b'));

        $scheduler->record('b', WorkerReport::DONE, 0, 100.0);
        $this->assertSame(130.0, $scheduler->dueAt('b'));
        $scheduler->record('b', WorkerReport::DONE, 0, 130.0);
        $this->assertSame(190.0, $scheduler->dueAt('b'));
        $scheduler->record('b', WorkerReport::DONE, 0, 190.0);
        $scheduler->record('b', WorkerReport::DONE, 0, 310.0);
        $this->assertSame(430.0, $scheduler->dueAt('b'));
    }

    public function testSchedulerMakesLaterWorkersDueAfterAnEarlierOneDidWork(): void
    {
        $scheduler = new WorkerScheduler(['a', 'b'], 2, 30, 120);
        $scheduler->record('b', WorkerReport::DONE, 0, 100.0);
        $scheduler->record('a', WorkerReport::DONE, 1, 110.0);
        $this->assertSame(110.0, $scheduler->dueAt('b'));
    }
}
