<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the PhpUnit provider now lives in drivers/tts/Drivers/PhpUnitDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/PhpUnitDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\PhpUnitDriver());
