<?php

// PhpUnit speech provider (TTS key "phpunit"). Test fixture; tests/Fixtures/tts-phpunit.php registers it.
// The provider logic is unchanged: it reads the connector's own TTS settings from the legacy globals and writes
// the speech cache exactly as before.

namespace {
    if (!defined('SYNTH_TTS_SCRIPT_DIR')) define('SYNTH_TTS_SCRIPT_DIR', dirname(__DIR__, 2) . '/tts');
}

namespace Synth\Drivers\Tts\Drivers {


    use Synth\Drivers\Tts\TtsProviderInterface;

    final class PhpUnitDriver implements TtsProviderInterface
    {
        public const KEY = 'phpunit';

        public function key(): string
        {
            return self::KEY;
        }

        /** @return string|false cache path of the synthesized speech, or false when the provider failed */
        public function synthesize($textString, $mood, $stringforhash)
        {
    return "soundcache/" . md5(trim($stringforhash)) . ".wav";
        }
    }
}
