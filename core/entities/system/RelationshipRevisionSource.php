<?php

// One row of core_relationship_revision_source as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipRevisionSource;

/** One row of core_relationship_revision_source. */
final class RelationshipRevisionSource extends AbstractRelationshipRevisionSource
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipRevisionSource $mapped): self
    {
        return self::copyOf($mapped);
    }
}
