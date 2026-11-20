<?php

// One row of core_actor_activation as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractActorActivation;

/** One row of core_actor_activation. */
final class ActorActivation extends AbstractActorActivation
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractActorActivation $mapped): self
    {
        return self::copyOf($mapped);
    }
}
