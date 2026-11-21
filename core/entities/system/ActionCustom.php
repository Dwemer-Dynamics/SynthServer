<?php

// One row of core_action_custom as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractActionCustom;

/** One row of core_action_custom. */
final class ActionCustom extends AbstractActionCustom
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractActionCustom $mapped): self
    {
        return self::copyOf($mapped);
    }
}
