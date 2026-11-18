<?php

declare(strict_types=1);

namespace Synth\Drivers\Itt;

use Synth\Drivers\Itt\Drivers\CustomDriver;
use Synth\Drivers\Itt\Drivers\GoogleOpenAiDriver;
use Synth\Drivers\Itt\Drivers\LlamaCppDriver;
use Synth\Drivers\Itt\Drivers\OpenAiDriver;
use Synth\Drivers\Itt\Drivers\OpenRouterDriver;
use Synth\Drivers\Itt\Exception\IttConfigurationException;
use Synth\Drivers\Itt\Exception\IttException;

// Plain require_once so the legacy hooks work without the Composer autoloader.
foreach (['Exception/IttException', 'Exception/IttConfigurationException', 'IttRequest', 'IttDriverInterface',
    'Drivers/IttHttp', 'Drivers/OpenAiCompatibleIttDriver', 'Drivers/OpenAiDriver', 'Drivers/OpenRouterDriver',
    'Drivers/GoogleOpenAiDriver', 'Drivers/CustomDriver', 'Drivers/LlamaCppDriver'] as $file) {
    require_once __DIR__ . '/' . $file . '.php';
}

/**
 * The single entry point for turning an image into a text description.
 *
 * The caller resolves the connector's settings and API key (through ApiBadge and the shared key store) and passes
 * them in as plain config; drivers never read globals, settings or the database.
 */
final class IttWrapper
{
    /** @var array<string, class-string<IttDriverInterface>> */
    private const DRIVERS = [
        OpenAiDriver::NAME => OpenAiDriver::class,
        OpenRouterDriver::NAME => OpenRouterDriver::class,
        GoogleOpenAiDriver::NAME => GoogleOpenAiDriver::class,
        CustomDriver::NAME => CustomDriver::class,
        LlamaCppDriver::NAME => LlamaCppDriver::class,
    ];

    /** @return list<string> */
    public static function driverNames(): array
    {
        return array_keys(self::DRIVERS);
    }

    /**
     * @param array<string,mixed> $config provider settings; see each driver's constructor
     * @throws IttConfigurationException When the driver is unknown or its settings cannot be used.
     */
    public static function create(string $driver, array $config = []): IttDriverInterface
    {
        $name = strtolower(trim($driver));
        $class = self::DRIVERS[$name] ?? null;
        if ($class === null) {
            throw new IttConfigurationException("Unknown ITT driver \"{$name}\"; supported drivers are " . implode(', ', self::driverNames()) . '.');
        }

        return new $class($config);
    }

    /**
     * Returns the provider's description of the image.
     *
     * @param array<string,mixed> $config
     * @throws IttException When the provider is not configured or fails.
     */
    public static function describe(string $driver, array $config, IttRequest $request): string
    {
        return self::create($driver, $config)->describe($request);
    }
}
