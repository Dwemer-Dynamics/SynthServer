<?php

// One row of core_runtime_session as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRuntimeSession;

/** One row of core_runtime_session. */
final class RuntimeSession extends AbstractRuntimeSession
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRuntimeSession $mapped): self
    {
        return self::copyOf($mapped);
    }
}
