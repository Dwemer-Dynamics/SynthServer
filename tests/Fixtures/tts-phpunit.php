<?php

// Test fixture hook: registers the PhpUnit speech provider (TTS key "phpunit") that the test configuration selects.
// It lives here, not in tts/, so no stub driver ships in production code.
require_once dirname(__DIR__, 2) . '/drivers/tts/TtsProviderRegistry.php';
require_once __DIR__ . '/PhpUnitDriver.php';

\Synth\Drivers\Tts\TtsProviderRegistry::register(new \Synth\Drivers\Tts\Drivers\PhpUnitDriver());
