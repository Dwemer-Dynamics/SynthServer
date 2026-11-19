<?php

// Thin hook: the OmniVoice provider now lives in drivers/tts/Drivers/OmniVoiceDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/TtsProviderRegistry.php';
require_once dirname(__DIR__) . '/drivers/tts/Drivers/OmniVoiceDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\OmniVoiceDriver());
