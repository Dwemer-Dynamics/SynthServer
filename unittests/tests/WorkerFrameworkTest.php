<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Synth\Workers\Memory\MemoryWorker;
use Synth\Workers\Memory\MiddleTermWorker;
use Synth\Workers\Profiles\ProfileAutofillWorker;
use Synth\Workers\Relationships\RelationshipWorker;
use Synth\Workers\Speech\SpeechWorker;
use Synth\Workers\Supervisor\WorkerScheduler;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerRegistry;
use Synth\Workers\WorkerReport;

foreach (['WorkerInterface', 'WorkerLimits', 'WorkerReport', 'WorkerRegistry', 'Legacy/LegacyDispatchWorker', 'Legacy/LegacyTaskWorker',
    'Memory/MemoryWorker', 'Memory/MiddleTermWorker', 'Profiles/DynamicProfileWorker', 'Profiles/ProfileAutofillWorker',
    'Player2/Player2HealthWorker', 'Relationships/RelationshipWorker', 'ExtensionEvents/ExtensionEventsWorker', 'Speech/SpeechWorker',
    'Supervisor/WorkerScheduler'] as $file) {
    require_once dirname(__DIR__, 2) . '/workers/' . $file . '.php';
}

// Database-free: registry, limits, report status and the legacy dispatch adapter with a fake launcher.
final class WorkerFrameworkTest extends TestCase
{
    public function testRegistryListsAndCreatesWorkersAndRejectsUnknownNames(): void
    {
        $this->assertSame(['memory', 'relationship', 'middleterm', 'dynamicprofile', 'player2health', 'tts', 'profile', 'extension-events'], WorkerRegistry::names());
        $this->assertInstanceOf(MemoryWorker::class, WorkerRegistry::create('memory'));
        $this->assertSame('relationship', WorkerRegistry::create('relationship')->name());
        $this->expectException(InvalidArgumentException::class);
        WorkerRegistry::create('nope');
    }

    public function testEveryLaneWorkerIsRegisteredAndHasAFixedCadence(): void
    {
        $this->assertSame(['general', 'speech'], array_keys(WorkerRegistry::LANES));
        $this->assertNull(WorkerRegistry::lane('nope'));
        foreach (WorkerRegistry::LANES as $workers) {
            foreach ($workers as $name) {
                $this->assertContains($name, WorkerRegistry::names());
                $this->assertArrayHasKey($name, WorkerRegistry::FIXED_SECONDS);
            }
        }
        // The on-demand profile worker is in no lane, so no supervisor polls it.
        $this->assertSame([], array_intersect(['profile'], array_merge(...array_values(WorkerRegistry::LANES))));
        $this->assertSame(1, WorkerRegistry::FIXED_SECONDS['tts']);
    }

    public function testSpeechWorkerLaunchesOneBoundedDispatchWindow(): void
    {
        $calls = [];
        $worker = new SpeechWorker(function (string $script, array $arguments) use (&$calls): array {
            $calls[] = [basename($script), $arguments];
            return [0, ''];
        });
        $report = $worker->run(new WorkerLimits(5, 10));
        $this->assertSame([['tts_dispatch.php', ['--loop', '--max-seconds=10']]], $calls);
        $this->assertSame(WorkerReport::DONE, $report->status());

        $failed = (new SpeechWorker(static fn (string $script, array $arguments): array => [1, '']))->run(new WorkerLimits(1, 10));
        $this->assertSame(WorkerReport::FAILED, $failed->status());
    }

    public function testTaskWorkerPassesItsTaskNameToTheTaskDispatcher(): void
    {
        $calls = [];
        $worker = new MiddleTermWorker(function (string $script, array $arguments) use (&$calls): array {
            $calls[] = [basename($script), $arguments];
            return [0, "Legacy task completed.\n"];
        });
        $report = $worker->run(new WorkerLimits(1, 60));
        $this->assertSame([['task_dispatch.php', ['middleterm']]], $calls);
        $this->assertSame(1, $report->unitsDone);
    }

    public function testProfileWorkerNeedsAJobInsideTheJobDirectory(): void
    {
        $launched = false;
        $launcher = function () use (&$launched): array {
            $launched = true;
            return [0, ''];
        };
        $this->assertSame(WorkerReport::IDLE, (new ProfileAutofillWorker($launcher))->run(new WorkerLimits(1, 60))->status());
        $outside = tempnam(sys_get_temp_dir(), 'job') ?: '';
        try {
            $report = (new ProfileAutofillWorker($launcher, ['job' => $outside]))->run(new WorkerLimits(1, 60));
        } finally {
            @unlink($outside);
        }
        $this->assertSame(WorkerReport::IDLE, $report->status());
        $this->assertFalse($launched);
        $this->assertSame(0, (new ProfileAutofillWorker())->pendingUnits());
    }

    public function testProfileWorkerRunsAJobFileFromTheJobDirectory(): void
    {
        $directory = dirname(__DIR__, 2) . '/data/tmp/profile_autofill_jobs';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->markTestSkipped('Job directory unavailable.');
        }
        $job = $directory . '/workerframeworktest-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($job, '{}');
        $calls = [];
        try {
            $report = (new ProfileAutofillWorker(function (string $script, array $arguments) use (&$calls): array {
                $calls[] = [basename($script), $arguments];
                return [0, ''];
            }, ['job' => $job]))->run(new WorkerLimits(1, 60));
        } finally {
            @unlink($job);
        }
        $this->assertSame(WorkerReport::DONE, $report->status());
        $this->assertSame('profile_autofill_worker.php', $calls[0][0]);
        $this->assertSame(realpath(dirname($job)) . DIRECTORY_SEPARATOR . basename($job), $calls[0][1][0]);
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

    public function testSchedulerKeepsAFixedCadenceWhateverTheRunDid(): void
    {
        $scheduler = new WorkerScheduler(['a', 'b'], 2, 30, 120, ['a' => 5, 'b' => 1]);
        $scheduler->record('b', WorkerReport::DONE, 0, 100.0);
        $this->assertSame(101.0, $scheduler->dueAt('b'));
        $scheduler->record('b', WorkerReport::DONE, 0, 101.0);
        $this->assertSame(102.0, $scheduler->dueAt('b'));
        $scheduler->record('a', WorkerReport::MORE_PENDING, 3, 110.0);
        $this->assertSame(115.0, $scheduler->dueAt('a'));
        // Doing work does not wake a later worker that has its own cadence.
        $this->assertSame(102.0, $scheduler->dueAt('b'));
        $scheduler->record('a', WorkerReport::FAILED, 0, 120.0);
        $this->assertSame(125.0, $scheduler->dueAt('a'));
    }

    public function testCommandLineEntryPointsListWorkersAndRejectAnUnknownLane(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/vendor/autoload.php')) {
            $this->markTestSkipped('Composer autoloader unavailable.');
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $run = static function (array $command) use ($null): array {
            $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            return [proc_close($process), $output];
        };
        [$code, $output] = $run([PHP_BINARY, $root . '/bin/worker.php', 'list']);
        $this->assertSame(0, $code);
        $this->assertCount(count(WorkerRegistry::names()), array_filter(explode("\n", trim($output))));
        $this->assertStringContainsString('"worker":"profile"', $output);
        [$code] = $run([PHP_BINARY, $root . '/bin/worker-supervisor.php', '--lane=nope']);
        $this->assertSame(1, $code);
        [$code] = $run([PHP_BINARY, $root . '/service/task_dispatch.php', 'rolemaster']);
        $this->assertSame(2, $code);
    }

    public function testStartScriptDefaultsToTheSupervisorAndKeepsTheManagerBehindAFlag(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/service/start.sh');
        $this->assertStringContainsString('bin/worker-supervisor.php" --lane="$lane"', $script);
        $this->assertStringContainsString('run_worker_lane speech 1 --max-seconds=10', $script);
        $this->assertStringContainsString('run_worker_lane general 5', $script);
        $this->assertStringContainsString('SYNTH_LEGACY_MANAGER', $script);
        $this->assertStringContainsString('service/manager.php', $script);
        $this->assertStringContainsString('nc -lk -s 127.0.0.1', $script);
    }

    public function testSchedulerMakesLaterWorkersDueAfterAnEarlierOneDidWork(): void
    {
        $scheduler = new WorkerScheduler(['a', 'b'], 2, 30, 120);
        $scheduler->record('b', WorkerReport::DONE, 0, 100.0);
        $scheduler->record('a', WorkerReport::DONE, 1, 110.0);
        $this->assertSame(110.0, $scheduler->dueAt('b'));
    }
}
