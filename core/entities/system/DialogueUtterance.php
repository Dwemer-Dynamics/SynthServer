<?php

// One row of core_dialogue_utterance as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractDialogueUtterance;

/** One row of core_dialogue_utterance. */
final class DialogueUtterance extends AbstractDialogueUtterance
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractDialogueUtterance $mapped): self
    {
        return self::copyOf($mapped);
    }
}
