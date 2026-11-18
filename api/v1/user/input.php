<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/user/input: typed or recorded player input, handled by main.php (native conversation dispatch in api/v2).
LegacyEntry::requireEventType(['input_text', 'input_audio']);
require LegacyEntry::script('main.php');
