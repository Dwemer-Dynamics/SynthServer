<?php

// Authored relationship configuration for one directed pair in one playthrough as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractRelationshipConfig;

require_once __DIR__.'/CopiesMappedEntity.php';
require_once dirname(__DIR__).'/base/AbstractRelationshipConfig.php';

/** One row of core_relationship_config. */
final class RelationshipConfig extends AbstractRelationshipConfig
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractRelationshipConfig $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The playthrough id. */
    public function playthroughId(): string
    {
        return $this->playthroughId;
    }

    /** The source character id. */
    public function sourceCharacterId(): int
    {
        return $this->sourceCharacterId;
    }

    /** The target character id. */
    public function targetCharacterId(): int
    {
        return $this->targetCharacterId;
    }
}
