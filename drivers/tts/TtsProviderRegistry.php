<?php

declare(strict_types=1);

namespace Synth\Drivers\Tts;


/**
 * Registers a provider driver as the legacy speech callable.
 *
 * The speech plan (lib/synth_tts_plan.php) and lib/chat_helper_functions.php still look a provider up through
 * $GLOBALS['SYNTH_TTS_DRIVERS'][<key>] and $GLOBALS['TTS_IN_USE']; each tts/tts-*.php hook calls register() so
 * those callers keep working unchanged.
 */
final class TtsProviderRegistry
{
    /** @var array<string, TtsProviderInterface> */
    private static array $providers = [];

    public static function register(TtsProviderInterface $provider): void
    {
        self::$providers[$provider->key()] = $provider;
        $callable = static fn($textString, $mood = 'default', $stringforhash = '') => $provider->synthesize($textString, $mood, $stringforhash);
        $GLOBALS['SYNTH_TTS_DRIVERS'][$provider->key()] = $callable;
        $GLOBALS['TTS_IN_USE'] = $callable;
    }

    public static function get(string $key): ?TtsProviderInterface
    {
        return self::$providers[$key] ?? null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::$providers);
    }
}
