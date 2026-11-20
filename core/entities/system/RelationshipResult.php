<?php

// One row of core_relationship_result as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipResult;

/** One row of core_relationship_result. */
final class RelationshipResult extends AbstractRelationshipResult
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipResult $mapped): self
    {
        return self::copyOf($mapped);
    }
}
