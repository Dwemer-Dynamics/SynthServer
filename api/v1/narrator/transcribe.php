<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/narrator/transcribe: the private narrator's push-to-talk recording, transcribed by stt.php like
// speech/transcribe (same multipart contract, same recording size limit) but kept a route of its own, so the narrator
// lane's audio never shares the NPC speech route. The answer is never cached.
header('Cache-Control: no-store');
require LegacyEntry::script('stt.php');
