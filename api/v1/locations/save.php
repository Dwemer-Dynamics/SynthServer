<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/locations/save: a location catalog export, handled by main.php (api/v2 location_catalog).
LegacyEntry::requireEventType(['location_catalog']);
require LegacyEntry::script('main.php');
