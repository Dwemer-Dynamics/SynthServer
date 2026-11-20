<?php

// One row of core_relationship_interaction_result_prior as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionResultPrior;

/** One row of core_relationship_interaction_result_prior. */
final class RelationshipInteractionResultPrior extends AbstractRelationshipInteractionResultPrior
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipInteractionResultPrior $mapped): self
    {
        return self::copyOf($mapped);
    }
}
