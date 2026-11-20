<?php

// One row of core_npc_biography_template_custom as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpcBiographyTemplateCustom;

/** One row of core_npc_biography_template_custom. */
final class NpcBiographyTemplateCustom extends AbstractNpcBiographyTemplateCustom
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpcBiographyTemplateCustom $mapped): self
    {
        return self::copyOf($mapped);
    }
}
