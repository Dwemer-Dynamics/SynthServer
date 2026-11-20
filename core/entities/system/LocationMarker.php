<?php

// A reference the game associates with a location as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractLocationMarker;

/** One row of core_location_marker. */
final class LocationMarker extends AbstractLocationMarker
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractLocationMarker $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The marker id. */
    public function markerId(): int
    {
        return $this->markerId;
    }
}
