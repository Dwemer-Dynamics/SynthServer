<?php

// Thin hook: the Azure provider now lives in drivers/tts/Drivers/AzureDriver.php.
require_once dirname(__DIR__) . '/drivers/tts/Drivers/AzureDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\AzureDriver());
