<?php

// One row of core_item_description_custom as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractItemDescriptionCustom;

/** One row of core_item_description_custom. */
final class ItemDescriptionCustom extends AbstractItemDescriptionCustom
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractItemDescriptionCustom $mapped): self
    {
        return self::copyOf($mapped);
    }
}
