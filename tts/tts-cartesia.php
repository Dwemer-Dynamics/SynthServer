<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the Cartesia provider now lives in drivers/tts/Drivers/CartesiaDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/CartesiaDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\CartesiaDriver());
