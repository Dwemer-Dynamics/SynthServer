<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/plugins/manifest: the loaded plugin list, handled by gamedata.php.
LegacyEntry::requireGameDataType(['loaded_plugins']);
require LegacyEntry::script('gamedata.php');
