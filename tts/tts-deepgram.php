<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the Deepgram provider now lives in drivers/tts/Drivers/DeepgramDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/DeepgramDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\DeepgramDriver());
