<?php

// One row of core_social_task_claim as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractSocialTaskClaim;

/** One row of core_social_task_claim. */
final class SocialTaskClaim extends AbstractSocialTaskClaim
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractSocialTaskClaim $mapped): self
    {
        return self::copyOf($mapped);
    }
}
