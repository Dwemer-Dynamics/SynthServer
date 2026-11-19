<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the XttsFastApi provider now lives in drivers/tts/Drivers/XttsFastApiDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/XttsFastApiDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\XttsFastApiDriver());
