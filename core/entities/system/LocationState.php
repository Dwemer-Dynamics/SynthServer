<?php

// What one playthrough has observed about a location as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractLocationState;

require_once __DIR__.'/CopiesMappedEntity.php';
require_once dirname(__DIR__).'/base/AbstractLocationState.php';

/** One row of core_location_state. */
final class LocationState extends AbstractLocationState
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractLocationState $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The playthrough id. */
    public function playthroughId(): string
    {
        return $this->playthroughId;
    }

    /** The location id. */
    public function locationId(): int
    {
        return $this->locationId;
    }
}
