<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/dialogue/spoken: a speech receipt, handled by main.php (native dispatch in api/v2).
LegacyEntry::requireEventType(['speech_receipt']);
require LegacyEntry::script('main.php');
