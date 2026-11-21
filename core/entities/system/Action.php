<?php

// One row of core_action as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractAction;

/** One row of core_action. */
final class Action extends AbstractAction
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractAction $mapped): self
    {
        return self::copyOf($mapped);
    }
}
