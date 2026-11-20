<?php

// One row of core_middleterm_control_revision as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMiddletermControlRevision;

/** One row of core_middleterm_control_revision. */
final class MiddletermControlRevision extends AbstractMiddletermControlRevision
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMiddletermControlRevision $mapped): self
    {
        return self::copyOf($mapped);
    }
}
