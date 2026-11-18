<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/npc/add: an actor profile record, handled by gamedata.php.
LegacyEntry::requireGameDataType(['actor_profile']);
require LegacyEntry::script('gamedata.php');
