<?php

// One row of core_narrator_context_state as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNarratorContextState;

/** One row of core_narrator_context_state. */
final class NarratorContextState extends AbstractNarratorContextState
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNarratorContextState $mapped): self
    {
        return self::copyOf($mapped);
    }
}
