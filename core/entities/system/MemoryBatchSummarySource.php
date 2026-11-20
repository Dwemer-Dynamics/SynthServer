<?php

// One row of core_memory_batch_summary_source as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMemoryBatchSummarySource;

/** One row of core_memory_batch_summary_source. */
final class MemoryBatchSummarySource extends AbstractMemoryBatchSummarySource
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMemoryBatchSummarySource $mapped): self
    {
        return self::copyOf($mapped);
    }
}
