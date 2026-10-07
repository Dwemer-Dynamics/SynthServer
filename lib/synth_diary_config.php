<?php

require_once __DIR__.'/core/core_profiles.class.php';

// Match each reference role's automatic switches; manual NPC/Narrator requests bypass automatic policy.
function synth_diary_resolve_automation(string $role,string $reason,array $character,array $profile,array $environment): array
{
    if (!in_array($role,['npc','player','narrator'],true) || !in_array($reason,['manual','sleep','wait'],true)) {
        throw new InvalidArgumentException('Invalid diary automation role or reason.');
    }
    $master=$role!=='player' || filter_var($character['diary_enabled'] ?? false,FILTER_VALIDATE_BOOLEAN);
    if ($reason==='manual') return ['enabled'=>$master,'cooldown_seconds'=>0];
    $cooldown=max(0,intval($environment['DIARY_COOLDOWN'] ?? 30));
    if ($role==='player') {
        $automatic=filter_var($character['auto_diary_enabled'] ?? false,FILTER_VALIDATE_BOOLEAN);
        $wait=filter_var($character['auto_diary_wait_enabled'] ?? false,FILTER_VALIDATE_BOOLEAN);
    } elseif ($role==='narrator') {
        $automatic=filter_var($character['auto_diary_enabled'] ?? $environment['NARRATOR_AUTO_DIARY_ENABLED'] ?? false,FILTER_VALIDATE_BOOLEAN);
        $wait=true; // The reference has no Narrator wait opt-out or diary_enabled master gate here.
    } else {
        $metadata=json_decode(($profile['metadata'] ?? '')==='' ? '{}' : $profile['metadata'],true,flags:JSON_THROW_ON_ERROR);
        $extended=json_decode(($character['extended_data'] ?? '')==='' ? '{}' : $character['extended_data'],true,flags:JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || !is_array($extended)) throw new LogicException('Invalid diary automation settings.');
        $automatic=in_array($metadata['AUTO_DIARY_ENABLED'] ?? false,['1',1,true],true);
        $wait=in_array($metadata['AUTO_DIARY_WAIT_ENABLED'] ?? false,['1',1,true],true);
        // Null/blank inherits; explicit false/zero disables. Preserve the reference's stored override truthiness.
        if (isset($extended['auto_diary_enabled']) && $extended['auto_diary_enabled']!=='') $automatic=!empty($extended['auto_diary_enabled']);
        if (isset($extended['auto_diary_wait_enabled']) && $extended['auto_diary_wait_enabled']!=='') $wait=!empty($extended['auto_diary_wait_enabled']);
        if (array_key_exists('DIARY_COOLDOWN',$metadata)) $cooldown=max(0,intval($metadata['DIARY_COOLDOWN']));
    }
    return ['enabled'=>$master && $automatic && ($reason==='sleep' || $wait),'cooldown_seconds'=>$cooldown];
}

// Resolve role-specific diary policy without loading credentials, mutating globals or invoking a provider.
function synth_diary_resolve_settings(string $role,array $character,array $profile,array $environment,
    callable $connectorById,callable $legacyDriver,?int $effectiveProfileDiaryId=null): ?array
{
    if (!in_array($role,['npc','player','narrator'],true)) throw new InvalidArgumentException('Invalid diary role.');
    $id=0;$source='profile_diary';
    if ($role==='player') {
        $source='player_diary';
        $selected=trim(strval($character['diary_connector_id'] ?? ''));
        if ($selected==='') {
            foreach (['CORE_CONNECTOR_SUMMARY','CORE_CONNECTOR_PLAYER','CORE_CONNECTOR_PROFILES'] as $key) {
                $selected=trim(strval($environment[$key] ?? ''));
                if ($selected!=='') { $source=$key;break; }
            }
        }
        $id=intval($selected);
    } else {
        // The caller supplies the established forced-Player2 selection when that policy applies.
        $id=$effectiveProfileDiaryId ?? intval($profile['diary_connector_id'] ?? 0);
        if ($id<1 && $role==='narrator') { $id=intval($profile['connector_id'] ?? 0);$source='narrator_profile'; }
        if ($id<1) return null;
    }
    $connector=$id>0 ? $connectorById($id) : null;
    if (!$connector && $role!=='player') throw new LogicException('Diary connector unavailable.');
    if (!$connector) {
        // Player falls back to the configured legacy driver if its chosen core record is absent;
        // it does not silently walk past a broken selection to a different core connector.
        $driver=$legacyDriver();
        if ($driver===null) return null;
        $connector=['driver'=>$driver];$id=0;$source='legacy_diary';
    }
    $driver=$connector['driver'] ?? null;
    if (!is_string($driver) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$driver)) throw new LogicException('Invalid diary connector driver.');
    $metadata=$role==='player' ? [] : json_decode($profile['metadata'] ?? '{}',true,flags:JSON_THROW_ON_ERROR);
    if (!is_array($metadata)) throw new LogicException('Invalid diary profile settings.');
    $settings=CoreProfile::defaultMetadata();
    foreach (['DIARY_PROMPT','PROMPT_HEAD','CONTEXT_HISTORY_DIARY','CONTEXT_HISTORY'] as $key) {
        if (isset($environment[$key])) $settings[$key]=$environment[$key];
        if (isset($metadata[$key]) && $metadata[$key]!=='') $settings[$key]=$metadata[$key];
    }
    $tokens=2048;
    foreach ([$connector['max_tokens'] ?? null,$environment['CONNECTOR'][$driver]['max_tokens'] ?? null,
        $environment['CONNECTOR'][$driver]['MAX_TOKENS_MEMORY'] ?? null] as $candidate) {
        if ($candidate!==null && $candidate!=='') { $tokens=intval($candidate);break; }
    }
    if ($tokens<1) throw new LogicException('Invalid diary token limit.');
    $limit=intval($settings['CONTEXT_HISTORY_DIARY'] ?? 0);
    if ($limit<=0) $limit=intval($settings['CONTEXT_HISTORY'] ?? 25);
    $characterHead=$role==='player' ? '' : ($character['prompt_head'] ?? '');
    foreach ([$role==='player' ? ($environment['PLAYER_DIARY_PROMPT'] ?? '') : $settings['DIARY_PROMPT'],
        $characterHead,$settings['PROMPT_HEAD'] ?? ''] as $instruction) {
        if (!is_string($instruction)) throw new LogicException('Invalid diary instruction type.');
    }
    $prompt=$role==='player' ? trim(strval($environment['PLAYER_DIARY_PROMPT'] ?? '')) : strval($settings['DIARY_PROMPT']);
    if ($role==='player' && $prompt==='') {
        $prompt="Please write a short summary of #PLAYER_NAME#'s recent dialogues and events written above into #PLAYER_NAME#'s diary. "
            .'WRITE AS IF YOU WERE #PLAYER_NAME#. Use first person and start the diary entry with the current date and time.';
    }
    $head=trim($characterHead)!=='' ? $characterHead : strval($settings['PROMPT_HEAD'] ?? '');
    foreach ([$prompt,$head] as $text) {
        if (strlen($text)>16384 || str_contains($text,"\0") || !preg_match('//u',$text)) throw new LogicException('Invalid diary instruction.');
    }
    if (trim($prompt)==='') throw new LogicException('Diary instruction unavailable.');
    return ['connector_id'=>$id>0 ? $id : null,'connector_driver'=>$driver,'connector_source'=>$source,
        'max_tokens'=>$tokens,'history_limit'=>max(1,min(200,$limit)),'prompt'=>$prompt,'prompt_head'=>$head];
}
