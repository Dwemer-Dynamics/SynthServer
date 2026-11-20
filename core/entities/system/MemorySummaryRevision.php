<?php

// One row of core_memory_summary_revision as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMemorySummaryRevision;

/** One row of core_memory_summary_revision. */
final class MemorySummaryRevision extends AbstractMemorySummaryRevision
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMemorySummaryRevision $mapped): self
    {
        return self::copyOf($mapped);
    }
}
