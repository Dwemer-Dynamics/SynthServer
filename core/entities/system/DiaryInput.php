<?php

// One row of core_diary_input as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractDiaryInput;

/** One row of core_diary_input. */
final class DiaryInput extends AbstractDiaryInput
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractDiaryInput $mapped): self
    {
        return self::copyOf($mapped);
    }
}
