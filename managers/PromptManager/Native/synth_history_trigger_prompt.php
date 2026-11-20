<?php

// Project an already visibility-scoped native trigger into prompt context.
// The immutable row retains its complete capture; a trigger is not dialogue or
// evidence that every inventory/quest/environment field was spoken or observed.
function synth_history_trigger_prompt_data(array $row, bool $nativeHistory): string
{
    $data = strval($row['data'] ?? '');
    if (!$nativeHistory || intval($row['history_event_id'] ?? 0) < 1
        || intval($row['reader_identity_id'] ?? 0) < 1
        || !in_array($row['type'] ?? '', ['infoaction', 'combatbark', 'bored', 'auto_greeting'], true)
        || strlen($data) > 1048576) return $data;
    $json = $data;
    $suffix = '';
    if (preg_match('/\A(\{.*\})( \([^\r\n]* shouts during combat\))\z/sD', $data, $match)) {
        $json = $match[1];
        $suffix = $match[2];
    }
    $payload = json_decode($json, true);
    if (!is_array($payload) || ($payload['schema'] ?? null) !== 'synth.trigger.v1'
        || ($payload['game'] ?? null) !== 'fo4'
        || !is_string($payload['npc'] ?? null) || trim($payload['npc']) === ''
        || strlen($payload['npc']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $payload['npc'])
        || !is_string($payload['npc_id'] ?? null) || !preg_match('/\A0x[0-9a-fA-F]{8}\z/D', $payload['npc_id'])
        || !is_string($payload['npc_plugin'] ?? null) || trim($payload['npc_plugin']) === ''
        || !is_string($payload['playthrough_id'] ?? null) || trim($payload['playthrough_id']) === '') return $data;
    $kind = $payload['kind'] ?? null;
    $expectedType = match ($kind) {
        'combat_bark' => 'combatbark', 'bored' => 'bored', 'auto_greeting' => 'auto_greeting', default => null,
    };
    if ($expectedType === null || !in_array($row['type'], ['infoaction', $expectedType], true)
        || ($suffix !== '' && ($kind !== 'combat_bark'
            || $suffix !== ' (' . $payload['npc'] . ' shouts during combat)'))) return $data;
    return match ($kind) {
        'combat_bark' => 'A combat dialogue was requested for ' . $payload['npc']
            . '. This trigger does not identify an opponent or establish a spoken reply, action, or outcome.',
        'bored' => 'An ambient dialogue was requested for ' . $payload['npc']
            . '. This trigger does not establish that anyone spoke or heard a reply.',
        'auto_greeting' => 'A greeting was requested for ' . $payload['npc']
            . '. This trigger does not establish that anyone spoke or heard a reply.',
    };
}
