<?php

// One row of core_diary_input_source as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractDiaryInputSource;

/** One row of core_diary_input_source. */
final class DiaryInputSource extends AbstractDiaryInputSource
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractDiaryInputSource $mapped): self
    {
        return self::copyOf($mapped);
    }
}
