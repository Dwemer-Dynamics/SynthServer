<?php

// One row of core_memory_batch as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMemoryBatch;

/** One row of core_memory_batch. */
final class MemoryBatch extends AbstractMemoryBatch
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMemoryBatch $mapped): self
    {
        return self::copyOf($mapped);
    }
}
