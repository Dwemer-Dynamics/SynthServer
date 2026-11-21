<?php

// One row of core_action_issued as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractActionIssued;

/** One row of core_action_issued. */
final class ActionIssued extends AbstractActionIssued
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractActionIssued $mapped): self
    {
        return self::copyOf($mapped);
    }
}
