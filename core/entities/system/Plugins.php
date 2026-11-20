<?php

// One row of core_plugins as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractPlugins;

/** One row of core_plugins. */
final class Plugins extends AbstractPlugins
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractPlugins $mapped): self
    {
        return self::copyOf($mapped);
    }
}
