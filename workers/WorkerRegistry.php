<?php

/**
 * Lists the background workers by their command-line name.
 */
declare(strict_types=1);

namespace Synth\Workers;

use InvalidArgumentException;
use Synth\Workers\ExtensionEvents\ExtensionEventsWorker;
use Synth\Workers\Memory\MemoryWorker;
use Synth\Workers\Memory\MiddleTermWorker;
use Synth\Workers\Player2\Player2HealthWorker;
use Synth\Workers\Profiles\DynamicProfileWorker;
use Synth\Workers\Profiles\ProfileAutofillWorker;
use Synth\Workers\Relationships\RelationshipWorker;
use Synth\Workers\Speech\SpeechWorker;

final class WorkerRegistry
{
    // Every worker, by name. A new worker is added here and nowhere else.
    private const WORKERS = [
        MemoryWorker::NAME => MemoryWorker::class,
        RelationshipWorker::NAME => RelationshipWorker::class,
        MiddleTermWorker::NAME => MiddleTermWorker::class,
        DynamicProfileWorker::NAME => DynamicProfileWorker::class,
        Player2HealthWorker::NAME => Player2HealthWorker::class,
        SpeechWorker::NAME => SpeechWorker::class,
        ProfileAutofillWorker::NAME => ProfileAutofillWorker::class,
        ExtensionEventsWorker::NAME => ExtensionEventsWorker::class,
    ];

    // The workers each supervisor runs, in the order they feed each other. A lane is its own supervisor
    // process, so slow work in one lane never delays another. Speech has a lane of its own because its
    // deadlines are short. A worker in no lane runs only on demand (`bin/worker.php <name>`), like "profile".
    public const LANES = [
        'general' => [
            MemoryWorker::NAME,
            RelationshipWorker::NAME,
            MiddleTermWorker::NAME,
            DynamicProfileWorker::NAME,
            Player2HealthWorker::NAME,
            ExtensionEventsWorker::NAME,
        ],
        'speech' => [SpeechWorker::NAME],
    ];

    // Seconds between the end of one run and the start of the next, for workers that always ran on a timer:
    // the legacy manager ticked 5 seconds after each pass and the speech loop restarted after 1 second.
    // The extension-events queue is polled on the same 5-second tick.
    public const FIXED_SECONDS = [
        MemoryWorker::NAME => 5,
        RelationshipWorker::NAME => 5,
        MiddleTermWorker::NAME => 5,
        DynamicProfileWorker::NAME => 5,
        Player2HealthWorker::NAME => 5,
        SpeechWorker::NAME => 1,
        ExtensionEventsWorker::NAME => 5,
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
     * Returns the names of the workers a lane runs, or null when there is no such lane.
     *
     * @return list<string>|null
     */
    public static function lane(string $lane): ?array
    {
        return self::LANES[$lane] ?? null;
    }

    /**
     * Creates the worker with this name, with its default settings.
     *
     * @param array<string, string|true> $options Command-line options for workers that take them, such as the "job" of the profile worker.
     * @throws InvalidArgumentException When no worker has this name.
     */
    public static function create(string $name, array $options = []): WorkerInterface
    {
        if (!isset(self::WORKERS[$name])) {
            throw new InvalidArgumentException(
                "Unknown worker '{$name}'. Known workers: " . implode(', ', self::names()) . '.'
            );
        }
        $class = self::WORKERS[$name];

        return new $class(null, $options);
    }
}
