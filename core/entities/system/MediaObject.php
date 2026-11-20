<?php

// One row of core_media_object as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMediaObject;

/** One row of core_media_object. */
final class MediaObject extends AbstractMediaObject
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMediaObject $mapped): self
    {
        return self::copyOf($mapped);
    }
}
