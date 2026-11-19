<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the ElevenLabs provider now lives in drivers/tts/Drivers/ElevenLabsDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/ElevenLabsDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\ElevenLabsDriver());
