<?php

// Thin hook: the PocketTts provider now lives in drivers/tts/Drivers/PocketTtsDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/PocketTtsDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\PocketTtsDriver());
