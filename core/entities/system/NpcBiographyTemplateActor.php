<?php

// One row of core_npc_biography_template_actor as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpcBiographyTemplateActor;

/** One row of core_npc_biography_template_actor. */
final class NpcBiographyTemplateActor extends AbstractNpcBiographyTemplateActor
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpcBiographyTemplateActor $mapped): self
    {
        return self::copyOf($mapped);
    }
}
