<?php

// One row of core_character_generation_revision as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractCharacterGenerationRevision;

/** One row of core_character_generation_revision. */
final class CharacterGenerationRevision extends AbstractCharacterGenerationRevision
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractCharacterGenerationRevision $mapped): self
    {
        return self::copyOf($mapped);
    }
}
