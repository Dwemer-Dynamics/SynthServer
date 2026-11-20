<?php

// One row of core_middleterm_regeneration_approval as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractMiddletermRegenerationApproval;

/** One row of core_middleterm_regeneration_approval. */
final class MiddletermRegenerationApproval extends AbstractMiddletermRegenerationApproval
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMiddletermRegenerationApproval $mapped): self
    {
        return self::copyOf($mapped);
    }
}
