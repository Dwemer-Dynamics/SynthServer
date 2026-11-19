<?php

// A ITT connector (an image-to-text service) as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractIttConnector;


/** One row of core_itt_connector. */
final class IttConnector extends AbstractIttConnector
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractIttConnector $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The row id. */
    public function id(): int
    {
        return $this->id;
    }
}
