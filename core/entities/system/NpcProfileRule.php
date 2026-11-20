<?php

// One row of core_npc_profile_rule as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpcProfileRule;

/** One row of core_npc_profile_rule. */
final class NpcProfileRule extends AbstractNpcProfileRule
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpcProfileRule $mapped): self
    {
        return self::copyOf($mapped);
    }
}
