<?php

// One row of core_dialogue_origin_policy as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractDialogueOriginPolicy;

/** One row of core_dialogue_origin_policy. */
final class DialogueOriginPolicy extends AbstractDialogueOriginPolicy
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractDialogueOriginPolicy $mapped): self
    {
        return self::copyOf($mapped);
    }
}
