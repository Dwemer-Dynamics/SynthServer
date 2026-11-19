<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm;

use Synth\Core\LlmDriver;

require_once dirname(__DIR__, 2) . '/core/contracts/dialogue.php';
require_once __DIR__ . '/LlmDriverInterface.php';

/**
 * The single entry point application code uses for an LLM call.
 *
 * Two shapes live here:
 * - Typed drivers (OpenAiCompatibleDriver, PlayerRewriteDriver): the wrapper is itself the Synth\Core\LlmDriver the
 *   managers receive. It asks each driver in order whether it supports the stored connector row and streams through
 *   the first that does; none is "provider_unsupported", the same refusal a lone driver gave.
 * - Stateful providers: legacyConnector() validates the driver name and returns the provider driver from Drivers/
 *   (open/process/close/fast_request). connector/<name>.php are thin hooks that keep the legacy global class names;
 *   a provider file added to connector/ outside the built-in set still loads as before.
 */
final class LlmWrapper implements LlmDriver
{
    /** Files in connector/ that are helpers, not providers. */
    private const NOT_PROVIDERS = ['__jpd', 'parse_simple_yaml'];

    /** Provider name (the connector row's driver) => class in drivers/llm/Drivers/. */
    public const PROVIDER_DRIVERS = [
        'openaijson' => 'OpenAiJsonDriver',
        'openrouterjson' => 'OpenRouterJsonDriver',
        'groqjson' => 'GroqJsonDriver',
        'koboldcppjson' => 'KoboldCppJsonDriver',
        'player2json' => 'Player2JsonDriver',
        'google_openaijson' => 'GoogleOpenAiJsonDriver',
    ];

    /** @param list<LlmDriver> $drivers asked in order; the first one that supports a connector streams it */
    public function __construct(private readonly array $drivers)
    {
        if ($drivers === []) {
            throw new \InvalidArgumentException('LlmWrapper needs at least one driver.');
        }
    }

    public function supports(array $connector): bool
    {
        return $this->driverFor($connector) !== null;
    }

    public function stream(array $connector, array $messages, callable $onText): void
    {
        $driver = $this->driverFor($connector);
        if ($driver === null) {
            throw new \DomainException('provider_unsupported');
        }
        $driver->stream($connector, $messages, $onText);
    }

    /** Returns the first driver that supports the connector row, or null. */
    public function driverFor(array $connector): ?LlmDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($connector)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * True when connector/<driver>.php is a provider this wrapper may load.
     *
     * Only a plain name is accepted, never a path, and helper files in the folder are not providers.
     */
    public static function hasLegacyConnector(string $driver, ?string $root = null): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $driver) === 1
            && !in_array($driver, self::NOT_PROVIDERS, true)
            && is_file(self::connectorFile($driver, $root));
    }

    /**
     * Loads connector/<driver>.php and returns a new instance of its class.
     *
     * @throws \InvalidArgumentException When the name is not a provider present in connector/.
     */
    public static function legacyConnector(string $driver, ?string $root = null): object
    {
        if (!self::hasLegacyConnector($driver, $root)) {
            throw new \InvalidArgumentException("LLM connector \"{$driver}\" is not available.");
        }
        if (isset(self::PROVIDER_DRIVERS[$driver])) {
            $class = self::PROVIDER_DRIVERS[$driver];
            require_once __DIR__ . '/Drivers/' . $class . '.php';
            $class = __NAMESPACE__ . '\\Drivers\\' . $class;

            return new $class();
        }
        require_once self::connectorFile($driver, $root);

        return new $driver();
    }

    private static function connectorFile(string $driver, ?string $root): string
    {
        return rtrim($root ?? dirname(__DIR__, 2), '/\\') . DIRECTORY_SEPARATOR . 'connector' . DIRECTORY_SEPARATOR . $driver . '.php';
    }
}
