<?php

// One row of core_rechat_chain as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRechatChain;

/** One row of core_rechat_chain. */
final class RechatChain extends AbstractRechatChain
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRechatChain $mapped): self
    {
        return self::copyOf($mapped);
    }
}
