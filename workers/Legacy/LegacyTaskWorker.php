<?php

/**
 * Runs one task of the legacy manager (`service/processors/<task>/entrypoint.php`) as a registered worker.
 *
 * `service/task_dispatch.php <task>` loads the same entry point the manager loads and runs its
 * function once in a fresh process, so the task body is not duplicated or changed. This adapter
 * only launches that script and reads its completion line.
 */
declare(strict_types=1);

namespace Synth\Workers\Legacy;

abstract class LegacyTaskWorker extends LegacyDispatchWorker
{
    /**
     * Returns the manager task name, the key of `\Synth\Bootstrap::config('TASKS')`, such as "middleterm".
     */
    abstract protected function task(): string;

    protected function script(): string
    {
        return 'service/task_dispatch.php';
    }

    protected function arguments(): array
    {
        return [$this->task()];
    }

    protected function doneMarker(): string
    {
        return 'Legacy task completed.';
    }
}
