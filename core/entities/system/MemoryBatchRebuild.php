<?php

// One row of core_memory_batch_rebuild as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMemoryBatchRebuild;

/** One row of core_memory_batch_rebuild. */
final class MemoryBatchRebuild extends AbstractMemoryBatchRebuild
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMemoryBatchRebuild $mapped): self
    {
        return self::copyOf($mapped);
    }
}
