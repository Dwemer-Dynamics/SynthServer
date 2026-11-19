<?php

// A worldspace (the coordinate space of a map) as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractWorldspace;

require_once __DIR__.'/CopiesMappedEntity.php';
require_once dirname(__DIR__).'/base/AbstractWorldspace.php';

/** One row of core_worldspace. */
final class Worldspace extends AbstractWorldspace
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractWorldspace $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The worldspace id. */
    public function worldspaceId(): int
    {
        return $this->worldspaceId;
    }
}
