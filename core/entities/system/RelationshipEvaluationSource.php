<?php

// One row of core_relationship_evaluation_source as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipEvaluationSource;

/** One row of core_relationship_evaluation_source. */
final class RelationshipEvaluationSource extends AbstractRelationshipEvaluationSource
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipEvaluationSource $mapped): self
    {
        return self::copyOf($mapped);
    }
}
