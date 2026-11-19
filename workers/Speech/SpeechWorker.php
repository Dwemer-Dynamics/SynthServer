<?php

/**
 * Registers the durable speech dispatcher (`service/tts_dispatch.php`) as the "tts" worker.
 *
 * One run is one bounded dispatch window: the dispatcher polls for due speech jobs every half
 * second and launches `service/tts_worker.php <job-id>` for each, as it always has, then
 * exits when the window ends. Speech deadlines are short, so the "speech" lane runs this
 * worker alone and the supervisor starts the next window a second later.
 */
declare(strict_types=1);

namespace Synth\Workers\Speech;

use Synth\Workers\Legacy\LegacyDispatchWorker;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerReport;

final class SpeechWorker extends LegacyDispatchWorker
{
    public const NAME = 'tts';

    private int $windowSeconds = 10;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Dispatches due speech jobs to the TTS job runner.';
    }

    public function run(WorkerLimits $limits): WorkerReport
    {
        $this->windowSeconds = $limits->maxSeconds;

        return parent::run($limits);
    }

    protected function script(): string
    {
        return 'service/tts_dispatch.php';
    }

    protected function arguments(): array
    {
        return ['--loop', '--max-seconds=' . $this->windowSeconds];
    }

    /**
     * The dispatcher prints nothing, so a window that ended normally is "done", not a counted unit.
     */
    protected function doneMarker(): string
    {
        return 'Speech dispatch window completed.';
    }
}
