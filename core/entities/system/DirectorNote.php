<?php

// One row of core_director_note as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractDirectorNote;

/** One row of core_director_note. */
final class DirectorNote extends AbstractDirectorNote
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractDirectorNote $mapped): self
    {
        return self::copyOf($mapped);
    }
}
