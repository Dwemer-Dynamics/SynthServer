<?php

// One row of core_response_log as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractResponseLog;

/** One row of core_response_log. */
final class ResponseLog extends AbstractResponseLog
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractResponseLog $mapped): self
    {
        return self::copyOf($mapped);
    }
}
