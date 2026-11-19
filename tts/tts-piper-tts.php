<?php

// Thin hook: the PiperTts provider now lives in drivers/tts/Drivers/PiperTtsDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/PiperTtsDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\PiperTtsDriver());
