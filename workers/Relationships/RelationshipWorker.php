<?php

/**
 * Registers the native relationship dispatch (`service/relationship_dispatch.php`) as the "relationship" worker.
 */
declare(strict_types=1);

namespace Synth\Workers\Relationships;

use Synth\Workers\Legacy\LegacyDispatchWorker;

final class RelationshipWorker extends LegacyDispatchWorker
{
    public const NAME = 'relationship';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Evaluates player and NPC-pair relationships from recent interactions.';
    }

    protected function script(): string
    {
        return 'service/relationship_dispatch.php';
    }

    protected function doneMarker(): string
    {
        return 'Native relationship dispatch completed.';
    }
}
