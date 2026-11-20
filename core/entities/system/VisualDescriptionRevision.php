<?php

// One row of core_visual_description_revision as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractVisualDescriptionRevision;

/** One row of core_visual_description_revision. */
final class VisualDescriptionRevision extends AbstractVisualDescriptionRevision
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractVisualDescriptionRevision $mapped): self
    {
        return self::copyOf($mapped);
    }
}
