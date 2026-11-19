<?php

// A game location of the catalog as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractLocation;

require_once __DIR__.'/CopiesMappedEntity.php';
require_once dirname(__DIR__).'/base/AbstractLocation.php';

/** One row of core_location. */
final class Location extends AbstractLocation
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractLocation $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The location id. */
    public function locationId(): int
    {
        return $this->locationId;
    }
}
