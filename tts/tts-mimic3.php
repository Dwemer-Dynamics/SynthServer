<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the Mimic3 provider now lives in drivers/tts/Drivers/Mimic3Driver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/Mimic3Driver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\Mimic3Driver());
