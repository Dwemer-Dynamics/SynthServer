<?php

// One row of core_narrator_transcript_entry as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNarratorTranscriptEntry;

/** One row of core_narrator_transcript_entry. */
final class NarratorTranscriptEntry extends AbstractNarratorTranscriptEntry
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNarratorTranscriptEntry $mapped): self
    {
        return self::copyOf($mapped);
    }
}
