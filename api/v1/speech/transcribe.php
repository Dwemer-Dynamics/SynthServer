<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/speech/transcribe: multipart audio and metadata, handled by stt.php, which answers its own
// method, metadata and missing-file refusals.
require LegacyEntry::script('stt.php');
