<?php

// One row of core_middleterm_result as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMiddletermResult;

/** One row of core_middleterm_result. */
final class MiddletermResult extends AbstractMiddletermResult
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMiddletermResult $mapped): self
    {
        return self::copyOf($mapped);
    }
}
