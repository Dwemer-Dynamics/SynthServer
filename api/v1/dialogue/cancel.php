<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/dialogue/cancel: cancels a turn, handled by main.php.
LegacyEntry::requireEventType(['cancel']);
require LegacyEntry::script('main.php');
