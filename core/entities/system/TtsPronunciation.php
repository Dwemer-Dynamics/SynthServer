<?php

// One row of core_tts_pronunciation as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractTtsPronunciation;

/** One row of core_tts_pronunciation. */
final class TtsPronunciation extends AbstractTtsPronunciation
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractTtsPronunciation $mapped): self
    {
        return self::copyOf($mapped);
    }
}
