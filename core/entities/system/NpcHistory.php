<?php

// One row of core_npc_history as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpcHistory;

/** One row of core_npc_history. */
final class NpcHistory extends AbstractNpcHistory
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpcHistory $mapped): self
    {
        return self::copyOf($mapped);
    }
}
