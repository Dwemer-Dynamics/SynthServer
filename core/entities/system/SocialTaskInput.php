<?php

// One row of core_social_task_input as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractSocialTaskInput;

/** One row of core_social_task_input. */
final class SocialTaskInput extends AbstractSocialTaskInput
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractSocialTaskInput $mapped): self
    {
        return self::copyOf($mapped);
    }
}
