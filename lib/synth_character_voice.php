<?php declare(strict_types=1);

require_once __DIR__.'/synth_npc_identity.php';
require_once __DIR__.'/synth_character_forms.php';

// Curie's wrapper uses a leveled template; its raw base race/voice cannot identify her live body.
function synth_curie_observed_voice_form(array $state): ?string
{
    return synth_character_curie_body($state);
}

// This pure overlay is consumed for one TTS resolution only; it must never be persisted as the profile.
function synth_curie_form_voice_overlay(array $profile, array $state): array
{
    $form=synth_curie_observed_voice_form($state);
    if ($form===null) return $profile;
    $raw=$profile['extended_data'] ?? [];
    $extended=is_array($raw) ? $raw : json_decode((string)$raw,true);
    if (!is_array($extended)) $extended=[];
    $slots=is_array($extended['form_voiceids'] ?? null) ? $extended['form_voiceids'] : [];
    $slot=$slots[$form] ?? '';
    // Provider IDs and local catalog keys are bounded identifiers, never paths or model text.
    $slot=is_string($slot) ? trim($slot) : '';
    if (!preg_match('/^[A-Za-z0-9_+.:-]{1,255}$/D',$slot)) $slot='';
    $generic=trim((string)($profile['voiceid'] ?? ''));
    if ($slot==='') {
        if (filter_var($profile['lock_profile'] ?? false,FILTER_VALIDATE_BOOL)
            || ($generic!=='' && !in_array(strtolower($generic),['robotcurie','npcfcurie'],true))) return $profile;
        $observed=trim((string)($state['voice_type'] ?? ''));
        if ($observed==='') return $profile;
        $selected=strtolower($observed);
        $voiceName=$observed;
        $voiceForm=$form==='curie_robot' ? '00027687' : '001647BF';
    } else {
        $selected=$slot;
        // A per-form provider choice must not fall back through a stale native sample hint.
        $voiceName='';
        $voiceForm='';
    }
    $extended['voice_metadata']=['voiceid'=>$selected,'voice_name'=>$voiceName,
        'voice_formid'=>$voiceForm,'source'=>'accepted_character_form'];
    $profile['voiceid']=$selected;
    $profile['voice_name']=$voiceName;
    $profile['voice_formid']=$voiceForm;
    $profile['extended_data']=json_encode($extended,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    return $profile;
}

// Resolve only the already bound profile owner in this native request's accepted immutable snapshot.
// Labels, mutable profile race/voice metadata and caller-supplied dialogue text are not identity evidence.
function synth_character_request_voice_profile($db, array $profile): array
{
    if (!synth_npc_native_request() || !is_object($db)) return $profile;
    $actorId=intval($profile['actor_identity_id'] ?? 0);
    if ($actorId<1) return $profile;
    if (!function_exists('synth_protocol_response_speaker')
        || synth_protocol_current_speech_role()!=='npc'
        || ($GLOBALS['db'] ?? null)!==$db) return $profile;
    synth_protocol_assert_request_active();
    $speaker=synth_protocol_response_speaker((string)($GLOBALS['SYNTH_NAME'] ?? ''),'npc');
    if (!is_array($speaker)) return $profile;
    $speakerKey=synth_equipment_prompt_identity_key($speaker);
    if ($speakerKey===null) return $profile;
    $identity=$db->fetchOne('SELECT full_form_id,origin_plugin,playthrough_id FROM synth.actor_identities WHERE game=\'fo4\' AND id='.$actorId);
    if (!is_array($identity)) return $profile;
    $identity['form_id']=$identity['full_form_id'] ?? null;
    if (synth_equipment_prompt_identity_key($identity)!==$speakerKey) return $profile;
    $snapshot=synth_protocol_prompt_snapshot($db);
    if (!is_array($snapshot)
        || ($snapshot['player']['playthrough_id'] ?? null)!==($speaker['playthrough_id'] ?? null)) return $profile;
    $state=synth_character_owned_state($snapshot['actor_states'] ?? [],$speaker);
    return $state!==null ? synth_curie_form_voice_overlay($profile,$state) : $profile;
}
