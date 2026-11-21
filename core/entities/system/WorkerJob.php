<?php

// One row of core_worker_job as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractWorkerJob;

/** One row of core_worker_job. */
final class WorkerJob extends AbstractWorkerJob
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractWorkerJob $mapped): self
    {
        return self::copyOf($mapped);
    }
}
