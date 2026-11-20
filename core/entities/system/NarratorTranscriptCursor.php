<?php

// One row of core_narrator_transcript_cursor as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNarratorTranscriptCursor;

/** One row of core_narrator_transcript_cursor. */
final class NarratorTranscriptCursor extends AbstractNarratorTranscriptCursor
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNarratorTranscriptCursor $mapped): self
    {
        return self::copyOf($mapped);
    }
}
