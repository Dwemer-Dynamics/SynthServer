<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// Seam for the future ExtensionEvents worker (see workers/). When a sink is installed, events are handed to it
// once per extension instead of running inline; the worker later calls ExtensionManager::deliver(). With no sink
// (the default) events run inline, error-isolated, right after the hook point.
interface DeferredEventSink
{
    public function defer(string $extensionId, string $hook, array $payload): void;
}
