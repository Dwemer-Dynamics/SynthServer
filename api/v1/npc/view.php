<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;
use Synth\Managers\CharacterManager\NpcViewRead;

require_once dirname(__DIR__, 3) . '/lib/RequestBody.php';
require_once dirname(__DIR__, 3) . '/managers/CharacterManager/NpcViewRead.php';

// POST /api/v1/npc/view: a bounded read of one NPC, without registering or updating it.
// Body {"type":"npc.view.read","playthrough_id","session_id","character_id","rid"?} (rid: a runtime actor id).
header('Cache-Control: no-store');
try {
    [$input, $db] = LegacyEntry::readSmallRequest('npc.view.read');
    $data = NpcViewRead::read($db, $input['playthrough_id'] ?? null, $input['session_id'] ?? null, $input['rid'] ?? null, $input['character_id'] ?? null);
    if ($data === null) {
        synthApiRefuse(404, 'NPC information is unavailable', 'not_found');
        return;
    }
    synthApiSend(200, ['ok' => true, 'type' => 'npc.view.result', 'character_id' => $data['character_id'], 'data' => $data]);
} catch (\InvalidArgumentException | \JsonException) {
    synthApiRefuse(400, 'Invalid NPC information request', 'invalid_request');
} catch (\DomainException) {
    synthApiRefuse(409, 'The loaded game is no longer active', 'session_not_active');
} catch (\Throwable $error) {
    error_log('[npc-view] read failed: ' . get_class($error));
    synthApiRefuse(503, 'NPC information is unavailable', 'unavailable');
}
