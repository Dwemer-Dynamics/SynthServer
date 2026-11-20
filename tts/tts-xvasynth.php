<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the XvaSynth provider now lives in drivers/tts/Drivers/XvaSynthDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/XvaSynthDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\XvaSynthDriver());
