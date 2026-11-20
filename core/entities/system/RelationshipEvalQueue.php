<?php

// One row of core_relationship_eval_queue as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipEvalQueue;

/** One row of core_relationship_eval_queue. */
final class RelationshipEvalQueue extends AbstractRelationshipEvalQueue
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipEvalQueue $mapped): self
    {
        return self::copyOf($mapped);
    }
}
