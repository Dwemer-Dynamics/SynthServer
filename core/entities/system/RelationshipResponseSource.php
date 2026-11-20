<?php

// One row of core_relationship_response_source as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipResponseSource;

/** One row of core_relationship_response_source. */
final class RelationshipResponseSource extends AbstractRelationshipResponseSource
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipResponseSource $mapped): self
    {
        return self::copyOf($mapped);
    }
}
