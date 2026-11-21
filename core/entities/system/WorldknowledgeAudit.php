<?php

// One row of core_worldknowledge_audit as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractWorldknowledgeAudit;

/** One row of core_worldknowledge_audit. */
final class WorldknowledgeAudit extends AbstractWorldknowledgeAudit
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractWorldknowledgeAudit $mapped): self
    {
        return self::copyOf($mapped);
    }
}
