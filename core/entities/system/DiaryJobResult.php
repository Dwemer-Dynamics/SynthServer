<?php

// One row of core_diary_job_result as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractDiaryJobResult;

/** One row of core_diary_job_result. */
final class DiaryJobResult extends AbstractDiaryJobResult
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractDiaryJobResult $mapped): self
    {
        return self::copyOf($mapped);
    }
}
