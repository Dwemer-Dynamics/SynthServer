<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/context/event: a context event, handled by main.php exactly as a root post of it.
LegacyEntry::requireEventType(['context']);
require LegacyEntry::script('main.php');
