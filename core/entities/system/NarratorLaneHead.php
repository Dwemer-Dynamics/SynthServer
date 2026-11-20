<?php

// One row of core_narrator_lane_head as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNarratorLaneHead;

/** One row of core_narrator_lane_head. */
final class NarratorLaneHead extends AbstractNarratorLaneHead
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNarratorLaneHead $mapped): self
    {
        return self::copyOf($mapped);
    }
}
