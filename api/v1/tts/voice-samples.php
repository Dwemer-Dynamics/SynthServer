<?php

declare(strict_types=1);

// POST /api/v1/tts/voice-samples: read-only list of the stored voice samples (metadata only).
// Same handler as the dashboard route /api/v1/voice/samples.php; this entry point only gives it the chevo route name.
require dirname(__DIR__) . '/voice/samples.php';
