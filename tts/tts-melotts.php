<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the MeloTts provider now lives in drivers/tts/Drivers/MeloTtsDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/MeloTtsDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\MeloTtsDriver());
