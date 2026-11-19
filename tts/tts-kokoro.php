<?php

// Thin hook: the Kokoro provider now lives in drivers/tts/Drivers/KokoroDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/TtsProviderRegistry.php';
require_once dirname(__DIR__) . '/drivers/tts/Drivers/KokoroDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\KokoroDriver());
