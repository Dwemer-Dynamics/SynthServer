<?php

declare(strict_types=1);

namespace Synth\Drivers\Stt;

use Synth\Drivers\Stt\Drivers\AzureDriver;
use Synth\Drivers\Stt\Drivers\DeepgramDriver;
use Synth\Drivers\Stt\Drivers\GeminiDriver;
use Synth\Drivers\Stt\Drivers\InworldDriver;
use Synth\Drivers\Stt\Drivers\LocalWhisperDriver;
use Synth\Drivers\Stt\Drivers\NoneDriver;
use Synth\Drivers\Stt\Drivers\ParakeetDriver;
use Synth\Drivers\Stt\Drivers\WhisperDriver;
use Synth\Drivers\Stt\Exception\SttConfigurationException;
use Synth\Drivers\Stt\Exception\SttException;

// Plain require_once so the legacy hooks work without the Composer autoloader (the unit-test fixtures copy this folder).
require_once __DIR__ . '/../../lib/classes/Logger.php';
foreach (['Exception/SttException', 'Exception/SttConfigurationException', 'SttRequest', 'SttResult', 'SttDriverInterface',
    'Drivers/SttHttp', 'Drivers/OpenAiCompatibleSttDriver', 'Drivers/WhisperDriver', 'Drivers/ParakeetDriver', 'Drivers/NoneDriver',
    'Drivers/AzureDriver', 'Drivers/DeepgramDriver', 'Drivers/GeminiDriver', 'Drivers/InworldDriver',
    'Drivers/LocalWhisperDriver'] as $file) {
    require_once __DIR__ . '/' . $file . '.php';
}

/**
 * The single entry point for turning a recording into text.
 *
 * The caller resolves the connector's settings and API key (through ApiBadge and the shared key store) and passes
 * them in as plain config; drivers never read globals, settings or the database.
 */
final class SttWrapper
{
    /** @var array<string, class-string<SttDriverInterface>> */
    private const DRIVERS = [
        NoneDriver::NAME => NoneDriver::class,
        WhisperDriver::NAME => WhisperDriver::class,
        ParakeetDriver::NAME => ParakeetDriver::class,
        LocalWhisperDriver::NAME => LocalWhisperDriver::class,
        AzureDriver::NAME => AzureDriver::class,
        DeepgramDriver::NAME => DeepgramDriver::class,
        GeminiDriver::NAME => GeminiDriver::class,
        InworldDriver::NAME => InworldDriver::class,
    ];

    /** @return list<string> */
    public static function driverNames(): array
    {
        return array_keys(self::DRIVERS);
    }

    /**
     * Creates the driver for a connector's "driver" value.
     *
     * @param array<string,mixed> $config provider settings; see each driver's constructor
     * @throws SttConfigurationException When the driver is unknown or its settings cannot be used.
     */
    public static function create(string $driver, array $config = []): SttDriverInterface
    {
        $name = strtolower(trim($driver));
        $class = self::DRIVERS[$name] ?? null;
        if ($class === null) {
            throw new SttConfigurationException("Unknown STT driver \"{$name}\"; supported drivers are " . implode(', ', self::driverNames()) . '.');
        }

        return $name === NoneDriver::NAME ? new NoneDriver() : new $class($config);
    }

    /**
     * Returns the recording's words, trimmed.
     *
     * @param array<string,mixed> $config
     * @throws SttException When the provider is not configured or fails.
     */
    public static function transcribe(string $driver, array $config, SttRequest $request): SttResult
    {
        return self::create($driver, $config)->transcribe($request);
    }

    /**
     * The call the legacy stt-*.php hooks make: returns '' instead of throwing, logs the reason, and hands the
     * provider diagnostic back for the speech pipeline.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed>|null $diagnostic
     */
    public static function transcribeOrEmpty(string $driver, array $config, SttRequest $request, ?array &$diagnostic = null): string
    {
        $diagnostic = [];
        try {
            $result = self::transcribe($driver, $config, $request);
            $diagnostic = $result->diagnostic;

            return $result->text;
        } catch (SttException $e) {
            $diagnostic = $e->diagnostic();
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'SttWrapper', 'STT ' . $driver . ': ' . $e->getMessage());

            return '';
        }
    }
}
