<?php

// One row of core_rechat_continuation as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRechatContinuation;

/** One row of core_rechat_continuation. */
final class RechatContinuation extends AbstractRechatContinuation
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRechatContinuation $mapped): self
    {
        return self::copyOf($mapped);
    }
}
