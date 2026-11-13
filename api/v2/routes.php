<?php

// v2 native dialogue routes over the validated wire event. Explicit routes only: capability alone never selects
// native dialogue, and unmarked typed text stays on the legacy pipeline.
function synth_api_v2_route(array $event): ?string
{
    if (($event['protocol_version'] ?? null) !== 2) return null;
    if (($event['type'] ?? '') === 'speech_receipt') return 'speech_receipt';
    if (in_array($event['type'] ?? '', ['narrator_command', 'narrator_turn', 'narrator_receipt'], true)) return 'narrator';
    if (($event['type'] ?? '') === 'input_text' && is_array($event['payload'] ?? null)
        && ($event['payload']['intent'] ?? null) === 'conversation') return 'conversation';
    return null;
}
