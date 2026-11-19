<?php

// A placed actor as managers see it: the plugin-local reference, the character it belongs to and its descriptive fields.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpcActor;


/** One row of core_npc_actor, copied out of the loaded entity so changing it never touches the database. */
final class NpcActor extends AbstractNpcActor
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpcActor $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The lower-case plugin file name that owns the reference. */
    public function plugin(): string
    {
        return $this->plugin;
    }

    /** The plugin-local reference id (hex, no load-order prefix). */
    public function refId(): string
    {
        return $this->refId;
    }

    /** The character (profile) this actor belongs to. */
    public function characterId(): int
    {
        return $this->characterId;
    }

    /** The actor's display name, if known. */
    public function name(): ?string
    {
        return $this->name;
    }
}
