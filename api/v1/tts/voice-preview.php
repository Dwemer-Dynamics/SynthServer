<?php

declare(strict_types=1);

// POST /api/v1/tts/voice-preview: speaks one short line with an NPC's saved voice and answers audio/wav.
// Same handler as the dashboard route /api/v1/voice/preview.php (management guard, dashboard token, VoicePreviewManager);
// this entry point only gives it the chevo route name.
require dirname(__DIR__) . '/voice/preview.php';
