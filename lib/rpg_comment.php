<?php

// Share one RPG cooldown across NPCs/events, but never across native save/player owners.
function synthRpgCommentCooldownKey($db, array $event): string
{
    if (($event['protocol_version'] ?? 1) === 1) return 'RPG_COMMENT_LAST_TIMESTAMP';
    if (($event['protocol_version'] ?? null) !== 2) {
        throw new DomainException('rpg_cooldown_protocol_invalid');
    }
    require_once __DIR__.'/synth_protocol_state.php';
    $context = synth_protocol_current_context($db, $event);
    $player = $context['player'] ?? [];
    $playthrough = $context['payload']['playthrough_id'] ?? '';
    if (!$context || ($event['session_id'] ?? '') === '' || ($event['generation'] ?? 0) < 1
        || $playthrough === '' || ($player['playthrough_id'] ?? '') !== $playthrough
        || ($player['form_id'] ?? '') === '' || ($player['origin_plugin'] ?? '') === '') {
        throw new DomainException('rpg_cooldown_context_required');
    }
    // Snapshot, turn, NPC and display name deliberately do not reset the 60-second window.
    return 'SYNTH_RPG_V2:'.hash('sha256', json_encode([
        $event['session_id'], $event['generation'], $playthrough,
        strtoupper($player['form_id']), strtolower($player['origin_plugin']),
    ], JSON_THROW_ON_ERROR));
}

// PostgreSQL arbitrates concurrent claims; a failed query never permits a provider request.
function synthClaimRpgCommentCooldown($db, array $event): bool
{
    $key = synthRpgCommentCooldownKey($db, $event);
    $result = $db->execQuery("INSERT INTO synth.conf_opts AS cooldown (id,value)
        VALUES ($1, floor(extract(epoch FROM clock_timestamp()))::bigint::text)
        ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value
        WHERE CASE WHEN cooldown.value ~ '^[0-9]{1,12}$'
            THEN cooldown.value::bigint <= EXCLUDED.value::bigint - 60
            ELSE true END
        RETURNING id", [$key]);
    if ($result === false) throw new RuntimeException('rpg_cooldown_claim_failed');
    return pg_num_rows($result) === 1;
}
