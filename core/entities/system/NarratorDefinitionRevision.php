<?php

// One row of core_narrator_definition_revision as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNarratorDefinitionRevision;

/** One row of core_narrator_definition_revision. */
final class NarratorDefinitionRevision extends AbstractNarratorDefinitionRevision
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNarratorDefinitionRevision $mapped): self
    {
        return self::copyOf($mapped);
    }
}
