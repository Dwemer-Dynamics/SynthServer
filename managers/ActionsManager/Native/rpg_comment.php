<?php

// Share one RPG cooldown across NPCs/events, but never across native save/player owners.
function synthRpgCommentCooldownKey($db, array $event): string
{
    if (($event['protocol_version'] ?? 1) === 1) return 'RPG_COMMENT_LAST_TIMESTAMP';
    if (($event['protocol_version'] ?? null) !== 2) {
        throw new DomainException('rpg_cooldown_protocol_invalid');
    }
    require_once dirname(__DIR__, 3).'/lib'.'/synth_protocol_state.php';
    $context = synth_protocol_current_context($db, $event);
    $player = $context['player'] ?? [];
    $playthrough = $context['payload']['playthrough_id'] ?? '';
    if (!$context || ($event['session_id'] ?? '') === '' || ($event['generation'] ?? 0) < 1
        || $playthrough === '' || ($player['playthrough_id'] ?? '') !== $playthrough
        || ($player['form_id'] ?? '') === '' || ($player['origin_plugin'] ?? '') === '') {
        throw new DomainException('rpg_cooldown_context_required');
    }
    // Snapshot, turn, NPC and display name deliberately do not reset the window. Companion remarks share their own key.
    return (synthRpgCommentCompanion($event) ? 'SYNTH_COMPANION_COMBAT_V2:' : 'SYNTH_RPG_V2:').hash('sha256', json_encode([
        $event['session_id'], $event['generation'], $playthrough,
        strtoupper($player['form_id']), strtolower($player['origin_plugin']),
    ], JSON_THROW_ON_ERROR));
}

function synthRpgCommentCompanion(array $event): bool
{
    return ($event['protocol_version'] ?? null) === 2 && ($event['type'] ?? '') === 'trigger'
        && ($event['payload']['kind'] ?? '') === 'companion_combat_comment';
}

// PostgreSQL arbitrates concurrent claims; a failed query never permits a provider request.
function synthClaimRpgCommentCooldown($db, array $event): bool
{
    $key = synthRpgCommentCooldownKey($db, $event);
    $window = synthRpgCommentCompanion($event) ? 30 : 60;
    $result = $db->execQuery("INSERT INTO public.core_config_setting AS cooldown (scope, config_key, config_value)
        VALUES ('conf', $1, floor(extract(epoch FROM clock_timestamp()))::bigint::text)
        ON CONFLICT (scope, config_key) DO UPDATE SET config_value=EXCLUDED.config_value
        WHERE CASE WHEN cooldown.config_value ~ '^[0-9]{1,12}$'
            THEN cooldown.config_value::bigint <= EXCLUDED.config_value::bigint - $2::bigint
            ELSE true END
        RETURNING config_key", [$key, $window]);
    if ($result === false) throw new RuntimeException('rpg_cooldown_claim_failed');
    return pg_num_rows($result) === 1;
}
