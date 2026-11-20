<?php

// One row of core_relationship_actor_control_event as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipActorControlEvent;

/** One row of core_relationship_actor_control_event. */
final class RelationshipActorControlEvent extends AbstractRelationshipActorControlEvent
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipActorControlEvent $mapped): self
    {
        return self::copyOf($mapped);
    }
}
