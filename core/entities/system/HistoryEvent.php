<?php

// One row of core_history_event as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractHistoryEvent;

/** One row of core_history_event. */
final class HistoryEvent extends AbstractHistoryEvent
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractHistoryEvent $mapped): self
    {
        return self::copyOf($mapped);
    }
}
