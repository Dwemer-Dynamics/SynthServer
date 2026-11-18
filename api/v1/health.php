<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// GET /api/v1/health[/<check>]: the readiness answer of health.php, or one of its granular checks.
$synthApiHealthChecks = [
    'health/live' => 'live',
    'health/db' => 'db',
    'health/api-keys-configured' => 'api-keys',
    'health/npcs-available' => 'npcs',
    'health/llm' => 'llm',
];
if (isset($synthApiHealthChecks[$route])) {
    $_GET['check'] = $synthApiHealthChecks[$route];
}
require LegacyEntry::script('health.php');
