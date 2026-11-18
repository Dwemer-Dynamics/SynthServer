<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/save/loaded: the game announces a loaded save; SYNTH takes that as the session init event, handled by main.php.
LegacyEntry::requireEventType(['init']);
require LegacyEntry::script('main.php');
