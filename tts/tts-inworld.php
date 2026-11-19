<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the Inworld provider now lives in drivers/tts/Drivers/InworldDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/InworldDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\InworldDriver());
