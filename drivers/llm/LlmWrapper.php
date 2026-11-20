<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm;

use Synth\Core\LlmDriver;
use Synth\Drivers\Llm\Logging\DatabaseLlmRequestRecorder;
use Synth\Drivers\Llm\Logging\LlmCallLog;
use Synth\Drivers\Llm\Logging\LlmRequestRecorder;
use Synth\Drivers\Llm\Logging\LoggedLegacyConnector;

require_once dirname(__DIR__, 2) . '/core/contracts/dialogue.php';

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
 *
 * Both shapes are logged to core_llm_request_log (Logging/): stream() here, and the stateful drivers through
 * LoggedLegacyConnector. The log is redacted and bounded, never breaks the request, and complements the per-request files
 * in log/ (which stay).
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
    public function __construct(private readonly array $drivers, private readonly ?LlmRequestRecorder $recorder = null)
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
        $log = new LlmCallLog($this->recorder ?? new DatabaseLlmRequestRecorder());
        $reply = '';
        $name = method_exists($driver, 'getName') ? (string) $driver->getName() : $driver::class;
        $log->begin('stream', $name, (string) ($connector['model'] ?? ''), isset($connector['id']) ? (int) $connector['id'] : null,
            'dialogue', ['call' => 'stream', 'connector' => self::connectorSummary($connector), 'messages' => $messages]);
        try {
            $driver->stream($connector, $messages, static function ($text) use ($onText, &$reply): void {
                $reply .= (string) $text;
                $onText($text);
            });
        } catch (\Throwable $error) {
            $log->end(LlmCallLog::statusFor($error), $reply, $error);
            throw $error;
        }
        $log->end('completed', $reply);
    }

    /**
     * The connector fields a replay needs to find its connector again, without any key.
     *
     * @return array<string, mixed>
     */
    private static function connectorSummary(array $connector): array
    {
        $summary = [];
        foreach (['id', 'driver', 'model', 'url', 'label'] as $field) {
            if (isset($connector[$field]) && is_scalar($connector[$field])) {
                $summary[$field] = $connector[$field];
            }
        }

        return $summary;
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
     * Wrapped in LoggedLegacyConnector unless $logged is false (the replay tool's own control calls); $connectorRow is the
     * stored connector row, which tells the log the connector id and model.
     *
     * @throws \InvalidArgumentException When the name is not a provider present in connector/.
     */
    public static function legacyConnector(string $driver, ?string $root = null, ?array $connectorRow = null, bool $logged = true): object
    {
        $provider = self::loadLegacyConnector($driver, $root);

        return $logged ? new LoggedLegacyConnector($provider, $driver, $connectorRow) : $provider;
    }

    private static function loadLegacyConnector(string $driver, ?string $root): object
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
