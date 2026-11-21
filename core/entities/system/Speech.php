<?php

// One row of core_speech as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractSpeech;

/** One row of core_speech. */
final class Speech extends AbstractSpeech
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractSpeech $mapped): self
    {
        return self::copyOf($mapped);
    }
}
