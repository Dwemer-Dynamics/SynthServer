<?php

// One row of core_protocol_event as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractProtocolEvent;

/** One row of core_protocol_event. */
final class ProtocolEvent extends AbstractProtocolEvent
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractProtocolEvent $mapped): self
    {
        return self::copyOf($mapped);
    }
}
