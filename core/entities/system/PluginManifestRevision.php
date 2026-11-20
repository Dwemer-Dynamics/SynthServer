<?php

// One recorded load-order manifest of a playthrough.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractPluginManifestRevision;


/** One row of core_plugin_manifest_revision, copied out of the loaded entity. */
final class PluginManifestRevision extends AbstractPluginManifestRevision
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractPluginManifestRevision $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The revision id. */
    public function id(): int
    {
        return (int) $this->id;
    }

    /** The manifest hash (64 lower-case hex). */
    public function manifestSha256(): string
    {
        return $this->manifestSha256;
    }

    /** The ordered plugin list as stored. @return list<array<string, mixed>> */
    public function plugins(): array
    {
        return $this->plugins;
    }
}
