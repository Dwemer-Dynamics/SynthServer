<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

use Synth\Workers\WorkerInterface;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerReport;

// An extension's worker as the supervisor sees it: named <id>:<name>, and an exception inside it counts as a failure of the
// extension (five in a row switch it off) instead of failing the supervisor. Failures end the run as a generic failed report.
final class GuardedWorker implements WorkerInterface
{
    /** @param \Closure(string):void $onFailure called with the failure class name */
    public function __construct(private string $id, private WorkerInterface $inner, private \Closure $onFailure, private \Closure $onSuccess) {}

    public function name(): string
    {
        return $this->id.':'.$this->inner->name();
    }

    public function description(): string
    {
        try { return mb_substr($this->inner->description(), 0, 200); }
        catch (\Throwable) { return ''; }
    }

    public function pendingUnits(): int
    {
        try { return max(0, $this->inner->pendingUnits()); }
        catch (\Throwable $error) { ($this->onFailure)($error::class); return 0; }
    }

    public function run(WorkerLimits $limits): WorkerReport
    {
        try {
            $report = $this->inner->run($limits);
            ($this->onSuccess)();
            return $report;
        } catch (\Throwable $error) {
            ($this->onFailure)($error::class);
            return WorkerReport::failed($this->name(), $error::class);
        }
    }
}
