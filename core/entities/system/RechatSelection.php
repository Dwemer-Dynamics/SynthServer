<?php

// One row of core_rechat_selection as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRechatSelection;

/** One row of core_rechat_selection. */
final class RechatSelection extends AbstractRechatSelection
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRechatSelection $mapped): self
    {
        return self::copyOf($mapped);
    }
}
