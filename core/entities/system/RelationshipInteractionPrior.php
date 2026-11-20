<?php

// One row of core_relationship_interaction_prior as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionPrior;

/** One row of core_relationship_interaction_prior. */
final class RelationshipInteractionPrior extends AbstractRelationshipInteractionPrior
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipInteractionPrior $mapped): self
    {
        return self::copyOf($mapped);
    }
}
