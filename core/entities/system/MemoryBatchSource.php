<?php

// One row of core_memory_batch_source as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMemoryBatchSource;

/** One row of core_memory_batch_source. */
final class MemoryBatchSource extends AbstractMemoryBatchSource
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMemoryBatchSource $mapped): self
    {
        return self::copyOf($mapped);
    }
}
