<?php

// One row of core_middleterm_input as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMiddletermInput;

/** One row of core_middleterm_input. */
final class MiddletermInput extends AbstractMiddletermInput
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMiddletermInput $mapped): self
    {
        return self::copyOf($mapped);
    }
}
