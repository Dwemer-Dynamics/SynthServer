<?php

// A TTS connector (a voice service) as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractTtsConnector;


/** One row of core_tts_connector. */
final class TtsConnector extends AbstractTtsConnector
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractTtsConnector $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The row id. */
    public function id(): int
    {
        return $this->id;
    }
}
