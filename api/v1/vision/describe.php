<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;

// POST /api/v1/vision/describe: a visual context capture, handled by itt.php, which validates and caps the body.
require LegacyEntry::script('itt.php');
