<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// The entry class named by an extension's manifest. register() only attaches handlers: it must not read the
// database, call services or print output.
interface ExtensionInterface
{
    public function register(ExtensionRegistrar $registrar): void;
}
