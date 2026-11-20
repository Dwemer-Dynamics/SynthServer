<?php

// One row of core_npc as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpc;

/** One row of core_npc. */
final class Npc extends AbstractNpc
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpc $mapped): self
    {
        return self::copyOf($mapped);
    }
}
