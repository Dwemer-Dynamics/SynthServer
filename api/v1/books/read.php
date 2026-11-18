<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/books/read: a context event carrying a reading_event, handled by main.php.
LegacyEntry::requireEventType(['context'], 'reading_event');
require LegacyEntry::script('main.php');
