<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/save/created: the player made a save. The client announces it with a `save_created` event, which main.php
// routes to api/v2 and records through SessionManager and SaveState.
LegacyEntry::requireEventType(['save_created']);
require LegacyEntry::script('main.php');
