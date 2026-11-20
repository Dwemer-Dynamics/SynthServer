<?php

// One row of core_character_generation_field as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractCharacterGenerationField;

/** One row of core_character_generation_field. */
final class CharacterGenerationField extends AbstractCharacterGenerationField
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractCharacterGenerationField $mapped): self
    {
        return self::copyOf($mapped);
    }
}
