<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;
use Synth\Managers\SaveState\HistoryRead;

require_once dirname(__DIR__, 3) . '/lib/RequestBody.php';
require_once dirname(__DIR__, 3) . '/managers/SaveState/HistoryRead.php';

// POST /api/v1/context/history: the conversation history of the active load, as the current target NPC may read it.
// Body {"type":"history.read","playthrough_id","session_id","limit"?}. Read-only: nothing is written.
header('Cache-Control: no-store');
try {
    [$input, $db] = LegacyEntry::readSmallRequest('history.read');
    synthApiSend(200, ['ok' => true] + HistoryRead::read($db, $input['playthrough_id'] ?? null, $input['session_id'] ?? null, $input['limit'] ?? null));
} catch (\InvalidArgumentException | \JsonException) {
    synthApiRefuse(400, 'Invalid history request', 'invalid_request');
} catch (\DomainException) {
    synthApiRefuse(409, 'The loaded game is no longer active', 'session_not_active');
} catch (\Throwable $error) {
    error_log('[history] read failed: ' . get_class($error));
    synthApiRefuse(503, 'History is unavailable', 'unavailable');
}
