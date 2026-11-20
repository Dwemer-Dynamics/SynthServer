<?php

// One row of core_actor_identity as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractActorIdentity;

/** One row of core_actor_identity. */
final class ActorIdentity extends AbstractActorIdentity
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractActorIdentity $mapped): self
    {
        return self::copyOf($mapped);
    }
}
