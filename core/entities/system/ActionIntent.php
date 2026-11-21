<?php

// One row of core_action_intent as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractActionIntent;

/** One row of core_action_intent. */
final class ActionIntent extends AbstractActionIntent
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractActionIntent $mapped): self
    {
        return self::copyOf($mapped);
    }
}
