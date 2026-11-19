<?php

// Thin hook: the ZonosGradio provider now lives in drivers/tts/Drivers/ZonosGradioDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/TtsProviderRegistry.php';
require_once dirname(__DIR__) . '/drivers/tts/Drivers/ZonosGradioDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\ZonosGradioDriver());
