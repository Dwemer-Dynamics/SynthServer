<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/narrator/checkpoint: the private narrator's checkpoint. A command (`state`, `select`, `cancel`, `transcript`)
// reads or moves the lane's state, and a receipt settles a reply against its parent checkpoint. Both are handled by
// main.php (api/v2 narrator route, PrivateNarratorManager). A generated turn (`narrator_turn`) is not a checkpoint call.
LegacyEntry::requireEventType(['narrator_command', 'narrator_receipt']);
require LegacyEntry::script('main.php');
