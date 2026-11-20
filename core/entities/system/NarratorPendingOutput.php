<?php

// One row of core_narrator_pending_output as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNarratorPendingOutput;

/** One row of core_narrator_pending_output. */
final class NarratorPendingOutput extends AbstractNarratorPendingOutput
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNarratorPendingOutput $mapped): self
    {
        return self::copyOf($mapped);
    }
}
