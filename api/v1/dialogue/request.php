<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/dialogue/request: an NPC turn request (activate, trigger, diary, action result, social task, halt), handled by main.php.
LegacyEntry::requireEventType(['activate', 'trigger', 'diary_request', 'action_result', 'social_task_request', 'social_task_progress', 'halt']);
require LegacyEntry::script('main.php');
