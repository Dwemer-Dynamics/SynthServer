<?php
require_once dirname(__DIR__) . '/lib/autoload.php';

// Thin hook: the OpenAi provider now lives in drivers/tts/Drivers/OpenAiDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/OpenAiDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\OpenAiDriver());
