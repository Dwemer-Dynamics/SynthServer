<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

use Synth\Workers\WorkerInterface;

// Handed to an extension's register(). A hook, route or worker the manifest does not list, or a hook of the wrong kind, stops
// the extension from loading, so the manifest always tells the user what an extension touches.
final class ExtensionRegistrar
{
    /** @var array<string, list<callable>> */
    private array $handlers = [];
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];
    /** @var array<string, WorkerInterface> */
    private array $workers = [];

    /** @param array{routes?: list<string>, workers?: list<string>} $provides */
    public function __construct(private ExtensionContext $context, private array $declaredHooks, private array $provides = []) {}

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

    /**
     * Answers /api/v1/ext/<id>/<pattern>. $pattern uses {name} segments ("notes/{name}"); the manifest must list
     * "METHOD pattern" in provides.routes. The handler gets an ExtensionRoute and returns an array body, or
     * {status: int, body: array}.
     */
    public function route(string $method, string $pattern, callable $handler): void
    {
        $key = strtoupper($method).' '.$pattern;
        if (!in_array($key, $this->provides['routes'] ?? [], true)) throw new \InvalidArgumentException("undeclared_route:{$key}");
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    // Adds a background worker run as `php bin/worker.php <id>:<name>`; its name must be listed in provides.workers.
    public function worker(WorkerInterface $worker): void
    {
        $name = $worker->name();
        if (!in_array($name, $this->provides['workers'] ?? [], true)) throw new \InvalidArgumentException("undeclared_worker:{$name}");
        $this->workers[$name] = $worker;
    }

    /** @return array<string, list<callable>> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /** @return list<array{method: string, pattern: string, handler: callable}> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @return array<string, WorkerInterface> */
    public function workers(): array
    {
        return $this->workers;
    }

    private function add(array $kind, string $hook, callable $handler): void
    {
        if (!in_array($hook, $kind, true)) throw new \InvalidArgumentException("unknown_hook:{$hook}");
        if (!in_array($hook, $this->declaredHooks, true)) throw new \InvalidArgumentException("undeclared_hook:{$hook}");
        $this->handlers[$hook][] = $handler;
    }
}
