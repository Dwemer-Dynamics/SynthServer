<?php

// A runtime actor as managers see it. The runtime actor id is the only lookup key; the form id is a hint and the name is
// display data (docs/RUNTIME-ACTORS.md, invariants 4 and 5).

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractNpcRuntimeActor;


/** One row of core_npc_runtime_actor, copied out of the loaded entity so changing it never touches the database. */
final class NpcRuntimeActor extends AbstractNpcRuntimeActor
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractNpcRuntimeActor $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The playthrough that owns the actor. */
    public function playthroughId(): string
    {
        return $this->playthroughId;
    }

    /** The client-minted runtime actor id. */
    public function runtimeActorId(): string
    {
        return $this->runtimeActorId;
    }

    /** The character (profile) this actor uses. */
    public function characterId(): int
    {
        return $this->characterId;
    }

    /** The last form id the client reported (a hint, FFxxxxxx). */
    public function lastFormId(): string
    {
        return $this->lastFormId;
    }

    /** The display name. */
    public function name(): string
    {
        return $this->name;
    }

    /** 'active', 'not_in_save', 'retired' or 'replaced'. */
    public function status(): string
    {
        return $this->status;
    }
}
