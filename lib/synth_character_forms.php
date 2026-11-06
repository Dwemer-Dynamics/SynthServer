<?php declare(strict_types=1);

require_once __DIR__.'/synth_equipment_prompt.php';

// Caller supplies only its accepted immutable observation. Labels, dialogue and
// stale biography fields cannot establish an actor's identity or present body.
function synth_character_owned_state(array $states, array $identity): ?array
{
    $key=synth_equipment_prompt_identity_key($identity);
    if ($key===null || !array_is_list($states) || count($states)>16) return null;
    $matches=[];
    foreach ($states as $state) {
        if (is_array($state) && is_array($state['actor'] ?? null)
            && synth_equipment_prompt_identity_key($state['actor'])===$key) $matches[]=$state;
    }
    return count($matches)===1 ? $matches[0] : null;
}

function synth_character_local_id(string $form): string
{
    return preg_match('/^0x[0-9a-f]{8}$/iD',$form) ? '00'.strtoupper(substr($form,-6)) : '';
}

function synth_character_curie_body(array $state): ?string
{
    if (strcasecmp(strval($state['base_origin_plugin'] ?? ''),'Fallout4.esm')!==0) return null;
    $actor=is_array($state['actor'] ?? null) ? $state['actor'] : [];
    if (strcasecmp(strval($actor['origin_plugin'] ?? ''),'Fallout4.esm')===0
        && synth_character_local_id(strval($actor['form_id'] ?? ''))==='000865D1') return null;
    $base=synth_character_local_id(strval($state['base_form_id'] ?? ''));
    if (!in_array($base,['00027686','001846CB','001647C6'],true)) return null;
    $race=strtolower(trim(strval($state['race'] ?? '')));
    $voice=strtolower(trim(strval($state['voice_type'] ?? '')));
    if ($race==='handyrace' && $base!=='001647C6' && in_array($voice,['','robotcurie'],true)) return 'curie_robot';
    if ($race==='humanrace' && $base!=='001846CB' && in_array($voice,['','npcfcurie'],true)) return 'curie_synth';
    return null;
}

function synth_character_speech_policy(?array $state): string
{
    if ($state===null) return '';
    $actor=is_array($state['actor'] ?? null) ? $state['actor'] : [];
    $ref=synth_character_local_id(strval($actor['form_id'] ?? ''));
    $referencePlugin=strtolower(strval($actor['origin_plugin'] ?? ''));
    // Known placed references retain their speech limits when optional physical
    // state is unavailable; incomplete captures must not grant English fluency.
    if ($referencePlugin==='fallout4.esm' && $ref==='00002CDB') return 'takahashi';
    if (($referencePlugin==='fallout4.esm' && $ref==='000865D1')
        || ($referencePlugin==='dlccoast.esm' && in_array($ref,['00005C86','0001F363'],true))
        || ($referencePlugin==='dlcrobot.esm' && in_array($ref,['00001DAD','00007525'],true))
        || ($referencePlugin==='dlcworkshop03.esm' && in_array($ref,['00001086','00004229'],true))) return 'nonverbal';
    $base=synth_character_local_id(strval($state['base_form_id'] ?? ''));
    $plugin=strtolower(strval($state['base_origin_plugin'] ?? ''));
    if ($plugin==='fallout4.esm' && $base==='00002CDA') return 'takahashi';
    if ($plugin==='dlccoast.esm' && in_array($base,['00005C85','0005420B'],true)) return 'nonverbal';
    if ($plugin==='dlcrobot.esm' && in_array($base,['00001DAC','00010F5F'],true)) return 'nonverbal';
    return '';
}

// A fixed canonical phrase is used once per reply; nonverbal actors receive no
// synthesized dialogue. Original game sounds remain the game's responsibility.
function synth_character_constrain_lines(array $lines, string $policy, bool &$emitted): array
{
    if ($policy==='nonverbal') return [];
    if ($policy!=='takahashi') return $lines;
    foreach ($lines as $line) {
        if (is_string($line) && trim($line)!=='') {
            if ($emitted) return [];
            $emitted=true;
            return ['Nan-ni shimasho-ka?'];
        }
    }
    return [];
}

function synth_character_current_speech_policy(): string
{
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)!==2
        || !function_exists('synth_protocol_current_speech_role')
        || synth_protocol_current_speech_role()!=='npc'
        || !function_exists('synth_protocol_response_speaker')
        || !function_exists('synth_protocol_prompt_snapshot')) return '';
    $identity=synth_protocol_response_speaker(strval($GLOBALS['SYNTH_NAME'] ?? ''),'npc');
    $snapshot=synth_protocol_prompt_snapshot($GLOBALS['db'] ?? null);
    if (!is_array($identity) || !is_array($snapshot)) return '';
    $state=synth_character_owned_state($snapshot['actor_states'] ?? [],$identity);
    return synth_character_speech_policy($state ?? ['actor'=>$identity]);
}

function synth_character_form_prompt(array $states,array $identity): string
{
    $state=synth_character_owned_state($states,$identity);
    if ($state===null) return '';
    $base=synth_character_local_id(strval($state['base_form_id'] ?? ''));
    $plugin=strtolower(strval($state['base_origin_plugin'] ?? ''));
    $ref=synth_character_local_id(strval($identity['form_id'] ?? ''));
    $officialReference=strcasecmp(strval($identity['origin_plugin'] ?? ''),'Fallout4.esm')===0;
    $rule='';
    if ($plugin==='fallout4.esm') {
        if (in_array($base,['001846CB','001647C6','00027686'],true)
            || ($officialReference && $ref==='00102249')) {
            $body=synth_character_curie_body($state);
            $robot=$body==='curie_robot';
            $synth=$body==='curie_synth';
            $rule='Curie remains the same physician and researcher across a body transfer. Her actual prior conversations, relationships and accepted memories belong to her ongoing life; do not invent events or adopt memories of a body donor. ';
            if ($robot) $rule.='The current observation shows her original Miss Nanny robot body. She knows her robot origins and present mechanical limitations. The synth transfer has not been established by this observation; do not describe it as already completed.';
            elseif ($synth) $rule.='The current observation shows Curie in her humanlike synth body. She explicitly remembers having lived as a Miss Nanny and recognizes the major change: new bodily sensations, emotions, vulnerability and learning to interpret them. She remains Curie, with her earlier scientific knowledge and experiences; she is not a newly met stranger.';
            else $rule.='Her current body is unconfirmed or the observations conflict. Do not infer robot or synth form from the static biography, wrapper base record or the player merely saying she changed.';
        } elseif ($base==='0006B503') {
            $rule='Virgil is currently observed in his super mutant form. He remembers being Brian Virgil, a human Institute scientist involved with FEV research, the moral burden of that work, his deliberate transformation to escape and the effort to recover his former body. He knows that his changed physique, abilities and daily limitations affect him now. Do not claim a cure has already happened or reveal an unobserved quest outcome.';
        } elseif ($base==='00168B89') {
            $rule='Virgil is currently observed as human after his reversal. He remembers his human Institute career, FEV work, escape and intervening life as a super mutant. Becoming human again does not erase that personal past. Do not invent player conversations, relationships or memories from another actor record or playthrough.';
        } elseif ($base==='00045AC9' || ($officialReference && $ref==='00050976')) {
            $rule='Deacon is one continuing person while changing clothing, appearance and cover stories. Previous disguises can recur; a returning outfit is not a new personality or a memory reset. Retain his accepted conversations and relationships. Describe the current disguise only from current observed equipment. His claims about personal history are often deliberate misdirection, not verified facts.';
        } elseif (in_array($base,['001A0413','001A041A','001A041E','001A0422'],true)) {
            $rule='This observed actor is using a public cover identity. Keep conversation within the observed caravan worker, security guard or drifter role. Do not volunteer a concealed real name, Railroad allegiance, or unseen surveillance history. Separate actor references do not authorize copying another person\'s private memories.';
        }
    }
    $policy=synth_character_speech_policy($state);
    if ($policy==='takahashi') $rule='Takahashi is Diamond City\'s malfunctioning noodle Protectron. His only spoken reply is the repeated Japanese phrase Nan-ni shimasho-ka? Do not add English translations, fluent Japanese conversation, invented repaired speech or an English-teaching outcome.';
    if ($policy==='nonverbal') $rule='This actor does not conduct ordinary spoken conversation in canon. Do not supply fluent speech, translated thoughts or explanatory voiced narration. Preserve original nonverbal game sounds and observed behavior.';
    if ($rule==='') return '';
    return "\n\n<character_form_context>".$rule.'</character_form_context>';
}
