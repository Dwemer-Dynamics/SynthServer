<?php

// One row of core_catalog_version as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractCatalogVersion;

/** One row of core_catalog_version. */
final class CatalogVersion extends AbstractCatalogVersion
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractCatalogVersion $mapped): self
    {
        return self::copyOf($mapped);
    }
}
