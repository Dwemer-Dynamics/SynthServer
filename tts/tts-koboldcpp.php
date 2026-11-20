<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the KoboldCpp provider now lives in drivers/tts/Drivers/KoboldCppDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/KoboldCppDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\KoboldCppDriver());
