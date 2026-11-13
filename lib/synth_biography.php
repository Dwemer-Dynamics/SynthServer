<?php

// Roleplay biography layout: authored expertise belongs to the background; "skills" are the addressed
// actor's own SPECIAL values from this session's bound context snapshot, never stored profile text.

const SYNTH_ACTOR_SPECIAL = ['strength','perception','endurance','charisma','intelligence','agility','luck'];
const SYNTH_EXPERTISE_PREFIX = 'Expertise: ';

// Background plus legacy expertise prose exactly once; a repaired or hand-merged background is unchanged.
function synth_biography_background(string $background, string $expertise): string
{
    $background=trim($background);$expertise=trim($expertise);
    if ($expertise==='' || str_contains($background,$expertise)) return $background;
    return $background==='' ? SYNTH_EXPERTISE_PREFIX.$expertise : $background."\n\n".SYNTH_EXPERTISE_PREFIX.$expertise;
}

function synth_actor_stats_supported(array $event): bool
{
    return ($event['protocol_version'] ?? 1)===2 && in_array('context.actor_stats',$event['capabilities'] ?? [],true);
}

// Base is the engine base value; current includes every active modifier. Permanent values are never sent.
function synth_protocol_validate_actor_stats($value,string $field): array
{
    if (!synth_protocol_is_object($value)) synth_protocol_fail('invalid_actor_stats',422,[['field'=>$field,'issue'=>'object_required']]);
    $observation=$value['observation'] ?? null;
    if ($observation==='unavailable') {
        synth_protocol_expect_exact_keys($value,['observation'],[],$field);
        return $value;
    }
    if ($observation!=='base_current') synth_protocol_fail('invalid_actor_stats',422,[['field'=>$field.'.observation','issue'=>'invalid']]);
    synth_protocol_expect_exact_keys($value,['observation','special'],[],$field);
    if (!synth_protocol_is_object($value['special'])) synth_protocol_fail('invalid_actor_stats',422,[['field'=>$field.'.special','issue'=>'object_required']]);
    synth_protocol_expect_exact_keys($value['special'],SYNTH_ACTOR_SPECIAL,[],$field.'.special');
    foreach (SYNTH_ACTOR_SPECIAL as $stat) {
        $pair=$value['special'][$stat];
        if ($pair===null) continue;
        if (!synth_protocol_is_object($pair)) synth_protocol_fail('invalid_actor_stats',422,[['field'=>$field.'.special.'.$stat,'issue'=>'object_required']]);
        synth_protocol_expect_exact_keys($pair,['base','current'],[],$field.'.special.'.$stat);
        foreach (['base','current'] as $kind) if ((!is_int($pair[$kind]) && !is_float($pair[$kind]))
            || !is_finite((float)$pair[$kind]) || abs($pair[$kind])>1000000)
            synth_protocol_fail('invalid_actor_stats',422,[['field'=>$field.'.special.'.$stat.'.'.$kind,'issue'=>'bounded_number_required']]);
    }
    return $value;
}

// Exact owner only: form, origin plugin and playthrough of the addressed actor in the bound snapshot.
function synth_actor_stats_state(array $context,array $identity): ?array
{
    $match=null;
    foreach ($context['payload']['actor_states'] ?? [] as $state) {
        $owner=$state['actor'] ?? [];
        if (strcasecmp($owner['form_id'] ?? '',$identity['form_id'] ?? '')===0
            && strcasecmp($owner['origin_plugin'] ?? '',$identity['origin_plugin'] ?? '')===0
            && ($owner['playthrough_id'] ?? '')===($identity['playthrough_id'] ?? null)) {
            if ($match!==null) return null;
            $match=$state;
        }
    }
    return $match;
}

// Empty without the capability; "unknown" when the actor or its values are absent from this snapshot.
function synth_actor_stats_prompt(array $event,?array $state): string
{
    if (!synth_actor_stats_supported($event)) return '';
    $stats=is_array($state) ? ($state['actor_stats'] ?? null) : null;
    if (!is_array($stats) || ($stats['observation'] ?? '')!=='base_current') return 'Skills (SPECIAL): unknown';
    $parts=[];
    foreach (SYNTH_ACTOR_SPECIAL as $stat) {
        $pair=$stats['special'][$stat] ?? null;
        $parts[]=ucfirst($stat).' '.(is_array($pair) ? $pair['base'].'/'.$pair['current'] : 'unknown');
    }
    return 'Skills (SPECIAL, base/current): '.implode(', ',$parts);
}

// Ordinary route: the named speaker must resolve to one exact actor of the current request snapshot.
function synth_actor_stats_for_request($db,string $npcName): string
{
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null;
    if (!is_array($event) || !synth_actor_stats_supported($event)) return '';
    require_once __DIR__.'/synth_npc_identity.php';
    $actorId=synth_npc_request_actor_id($db,$npcName);
    $context=synth_protocol_current_context($db,$event);
    $identity=$actorId===null ? null
        : $db->fetchOne('SELECT full_form_id AS form_id,origin_plugin,playthrough_id FROM synth.actor_identities WHERE id='.intval($actorId));
    return synth_actor_stats_prompt($event,is_array($identity) && is_array($context) ? synth_actor_stats_state($context,$identity) : null);
}

// Repair of profiles imported before the layout: only a byte-exact factory import of a still-resolving template moves.
function synth_biography_repair_classify(array $row,?array $factory,?string $resolvedTemplate): array
{
    if (intval($row['lock_profile'] ?? 0)===1) return ['status'=>'locked'];
    $metadata=json_decode(strval($row['metadata'] ?? ''),true);
    $template=is_array($metadata) && is_string($metadata['bio_template'] ?? null) ? $metadata['bio_template'] : '';
    if ($template==='' || $factory===null) return ['status'=>'provenance_missing'];
    if (empty($row['actor_identity_id']) || $resolvedTemplate!==$template) return ['status'=>'ambiguous','reason'=>'identity'];
    $bio=trim(strval($factory['npc_static_bio'] ?? ''));$expertise=trim(strval($factory['skills'] ?? ''));
    $merged=synth_biography_background($bio,$expertise);
    $currentBio=strval($row['npc_static_bio'] ?? '');$currentSkills=strval($row['skills'] ?? '');
    if ($expertise==='' || $merged===$bio) return ['status'=>'no_expertise'];
    if ($currentBio===$merged && $currentSkills==='') return ['status'=>'already_repaired'];
    if (($currentBio===$bio || $currentBio===$merged) && $currentSkills===$expertise)
        return ['status'=>'repair','npc_static_bio'=>$merged];
    if (in_array($currentBio,[$bio,$merged,''],true) || in_array($currentSkills,[$expertise,''],true))
        return ['status'=>'ambiguous','reason'=>'partial_factory_match'];
    return ['status'=>'customized'];
}
