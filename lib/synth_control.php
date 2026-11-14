<?php

// Closed native control vocabulary. No generic setconf, console or script proxy is accepted.
function synth_control_valid_change($setting, $value): bool
{
    if (!is_string($setting) || !is_string($value)) return false;
    return match ($setting) {
        'status' => $value === '',
        'mode' => in_array($value, ['STANDARD','WHISPER','CLOSE','SHOUT','NARRATOR','DIRECTOR','INJECTION_LOG','INJECTION_CHAT','CHEATMODE'], true),
        'model_slot' => in_array($value, ['1','2','3','4'], true),
        'audience' => in_array($value, ['normal','everyone'], true),
        default => false,
    };
}

// Everyone is negotiated per init: v2, the control menu and the explicit capability. Older clients get nothing new.
function synth_control_everyone_supported(array $event): bool
{
    return ($event['protocol_version'] ?? 1) === 2
        && in_array('dialogue.everyone', $event['capabilities'] ?? [], true)
        && in_array('control.menu', $event['capabilities'] ?? [], true);
}

// An explicit public-audience turn. Ingress has already required its epoch, mode and context.
function synth_control_everyone_input(array $event): bool
{
    return synth_control_everyone_supported($event)
        && in_array($event['type'] ?? '', ['input_text', 'input_audio'], true)
        && ($event['payload']['audience_intent'] ?? null) === 'everyone';
}

// Audience gate a claim must take before its session lock: true exclusive (global mode writer), false shared
// (Everyone input or audience preference), null none. Ordinary Normal input and other controls take no gate.
function synth_control_audience_gate(array $event): ?bool
{
    if (($event['type'] ?? '') === 'control' && ($event['payload']['setting'] ?? '') === 'mode') return true;
    if (($event['type'] ?? '') === 'control' && ($event['payload']['setting'] ?? '') === 'audience') return false;
    return synth_control_everyone_input($event) ? false : null;
}

// Runs inside the existing session claim transaction, so stale generations cannot change preferences. Mode and
// audience writes use the claim's raw connection, which already holds the audience gate the claim took first.
function synth_control_apply($db, array $event, ?\PgSql\Connection $connection = null): void
{
    $setting = $event['payload']['setting'];
    $value = $event['payload']['value'];
    if (!synth_control_valid_change($setting, $value)) throw new DomainException('invalid_control');
    if ($setting === 'status') return;
    if ($setting === 'mode' || $setting === 'audience') {
        require_once __DIR__.'/synth_audience_control.php';
        if ($connection === null) throw new LogicException('audience_gate_connection_required');
        if ($setting === 'mode') synth_audience_set_global_mode($connection, $value);
        else synth_audience_set($connection, $event['session_id'], intval($event['generation']), $value);
        return;
    }
    if ($setting === 'model_slot') {
        $column = [1=>'llm_primary_id',2=>'llm_secondary_id',3=>'llm_tertiary_id',4=>'llm_quaternary_id'][intval($value)];
        if (!$db->fetchOne("SELECT id FROM core_profiles WHERE {$column} IS NOT NULL AND {$column}>0 LIMIT 1"))
            throw new DomainException('model_slot_unconfigured');
    }
    $literal = $db->escapeLiteral($value);
    if ($db->execQuery("INSERT INTO conf_opts (id,value) VALUES ('synth_profile_model',{$literal}) ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value") === false)
        throw new RuntimeException('control_write_failed');
}

// Legacy (non-claim) global mode writers: one bounded management transaction that takes the gate exclusively and
// resets incompatible audiences atomically with the conf_opts write. Callers commit before any service call.
function synth_control_write_global_mode($db, string $mode): void
{
    require_once __DIR__.'/synth_audience_control.php';
    $db->withManagementTransaction(static fn($connection) => synth_audience_set_global_mode($connection, $mode));
}

// Negotiated control_audience for a control_state ACK, read after the claim has committed in the documented
// order: shared gate, session advisory lock, then the session row. Null when the client did not negotiate it.
function synth_control_audience_status($db, array $event): ?array
{
    if (!synth_control_everyone_supported($event)) return null;
    require_once __DIR__.'/synth_audience_control.php';
    return $db->withManagementTransaction(static function($connection) use ($event) {
        synth_audience_lock_gate($connection, false);
        synth_audience_query($connection, 'SELECT pg_advisory_xact_lock(hashtext($1))', [$event['session_id']]);
        return synth_audience_read($connection, $event['session_id'], intval($event['generation']));
    });
}

function synth_control_status($db): string
{
    $mode = strval(($db->fetchOne("SELECT value FROM conf_opts WHERE id='synth_mode'"))['value'] ?? 'STANDARD');
    $slot = strval(($db->fetchOne("SELECT value FROM conf_opts WHERE id='synth_profile_model'"))['value'] ?? '1');
    if (!synth_control_valid_change('mode', $mode)) $mode = 'STANDARD';
    if (!synth_control_valid_change('model_slot', $slot)) $slot = '1';
    return $mode.'|'.$slot;
}
