<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// Handed to an extension's register(). A hook the manifest does not list, or one of the wrong kind, stops the
// extension from loading, so the manifest always tells the user what an extension touches.
final class ExtensionRegistrar
{
    /** @var array<string, list<callable>> */
    private array $handlers = [];

    public function __construct(private ExtensionContext $context, private array $declaredHooks) {}

    public function context(): ExtensionContext
    {
        return $this->context;
    }

    // Filter handlers take a payload array and return one; anything else leaves the value unchanged.
    public function filter(string $hook, callable $handler): void
    {
        $this->add(Hooks::FILTERS, $hook, $handler);
    }

    // Event handlers observe; their return value is ignored.
    public function on(string $hook, callable $handler): void
    {
        $this->add(Hooks::EVENTS, $hook, $handler);
    }

    /** @return array<string, list<callable>> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    private function add(array $kind, string $hook, callable $handler): void
    {
        if (!in_array($hook, $kind, true)) throw new \InvalidArgumentException("unknown_hook:{$hook}");
        if (!in_array($hook, $this->declaredHooks, true)) throw new \InvalidArgumentException("undeclared_hook:{$hook}");
        $this->handlers[$hook][] = $handler;
    }
}
