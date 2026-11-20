<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/dialogue/observe: first-observed vanilla NPC dialogue (context.observed_dialogue). It is a context
// event whose payload carries `observed_dialogue`, admitted and stored by main.php as a root post of it; a context
// event without that evidence belongs to context/event, not here.
LegacyEntry::requireEventType(['context'], 'observed_dialogue');
require LegacyEntry::script('main.php');
