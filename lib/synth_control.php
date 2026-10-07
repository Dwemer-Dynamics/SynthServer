<?php

// Closed native control vocabulary. No generic setconf, console or script proxy is accepted.
function synth_control_valid_change($setting, $value): bool
{
    if (!is_string($setting) || !is_string($value)) return false;
    return match ($setting) {
        'status' => $value === '',
        'mode' => in_array($value, ['STANDARD','WHISPER','CLOSE','SHOUT','NARRATOR','DIRECTOR','INJECTION_LOG','INJECTION_CHAT','CHEATMODE'], true),
        'model_slot' => in_array($value, ['1','2','3','4'], true),
        default => false,
    };
}

// Runs inside the existing session claim transaction, so stale generations cannot change preferences.
function synth_control_apply($db, array $event): void
{
    $setting = $event['payload']['setting'];
    $value = $event['payload']['value'];
    if (!synth_control_valid_change($setting, $value)) throw new DomainException('invalid_control');
    if ($setting === 'status') return;
    if ($setting === 'model_slot') {
        $column = [1=>'llm_primary_id',2=>'llm_secondary_id',3=>'llm_tertiary_id',4=>'llm_quaternary_id'][intval($value)];
        if (!$db->fetchOne("SELECT id FROM core_profiles WHERE {$column} IS NOT NULL AND {$column}>0 LIMIT 1"))
            throw new DomainException('model_slot_unconfigured');
    }
    $id = $setting === 'mode' ? 'synth_mode' : 'synth_profile_model';
    $literal = $db->escapeLiteral($value);
    if ($db->execQuery("INSERT INTO conf_opts (id,value) VALUES ('{$id}',{$literal}) ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value") === false)
        throw new RuntimeException('control_write_failed');
}

function synth_control_status($db): string
{
    $mode = strval(($db->fetchOne("SELECT value FROM conf_opts WHERE id='synth_mode'"))['value'] ?? 'STANDARD');
    $slot = strval(($db->fetchOne("SELECT value FROM conf_opts WHERE id='synth_profile_model'"))['value'] ?? '1');
    if (!synth_control_valid_change('mode', $mode)) $mode = 'STANDARD';
    if (!synth_control_valid_change('model_slot', $slot)) $slot = '1';
    return $mode.'|'.$slot;
}
