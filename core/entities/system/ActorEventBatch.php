<?php

// One row of core_actor_event_batch as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractActorEventBatch;

/** One row of core_actor_event_batch. */
final class ActorEventBatch extends AbstractActorEventBatch
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractActorEventBatch $mapped): self
    {
        return self::copyOf($mapped);
    }
}
