<?php

// Thin hook: the Chatterbox provider now lives in drivers/tts/Drivers/ChatterboxDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/ChatterboxDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\ChatterboxDriver());
