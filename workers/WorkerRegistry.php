<?php

/**
 * Lists the background workers by their command-line name.
 */
declare(strict_types=1);

namespace Synth\Workers;

use InvalidArgumentException;
use Synth\Workers\Memory\MemoryWorker;
use Synth\Workers\Relationships\RelationshipWorker;

final class WorkerRegistry
{
    // Every worker, by name. A new worker is added here and nowhere else.
    private const WORKERS = [
        MemoryWorker::NAME => MemoryWorker::class,
        RelationshipWorker::NAME => RelationshipWorker::class,
    ];

    /**
     * Returns the names of every worker.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::WORKERS);
    }

    /**
     * Creates the worker with this name, with its default settings.
     *
     * @throws InvalidArgumentException When no worker has this name.
     */
    public static function create(string $name): WorkerInterface
    {
        if (!isset(self::WORKERS[$name])) {
            throw new InvalidArgumentException(
                "Unknown worker '{$name}'. Known workers: " . implode(', ', self::names()) . '.'
            );
        }
        $class = self::WORKERS[$name];

        return new $class();
    }
}
