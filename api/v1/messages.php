<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// GET /api/v1/messages/<id>: delivery state of one queued NPC line. SYNTH identifies it by the TTS cache key,
// so the id goes to tts_status.php, which validates it and answers as it does for a root request.
$_GET['cache_key'] = $messageIdText;
require LegacyEntry::script('tts_status.php');
