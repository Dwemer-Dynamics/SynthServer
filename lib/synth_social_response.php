<?php
require_once __DIR__.'/synth_social_tasks.php';
require_once __DIR__.'/synth_dialogue_policy.php';
require_once __DIR__.'/synth_tts.php';
require_once __DIR__.'/response.php';

// Only the independently verified task topic becomes speech; saved labels and private briefing do not.
function synth_social_arrival_line(array $context,array $verified,array $task): array {
    foreach (['task_id','execution_id','topic'] as $field)
        if (!is_string($verified[$field]??null) || ($task[$field]??null)!==$verified[$field])
            throw new DomainException('social_arrival_task_changed');
    if (($task['status']??null)!=='arrived' || !synth_social_text($verified['topic'],512))
        throw new DomainException('social_arrival_task_unavailable');
    $actors=[];
    foreach (['performer','recipient'] as $role) {
        if (!is_array($verified[$role]??null) || !is_array($task[$role]??null)
            || synth_social_actor_key($verified[$role])!==synth_social_actor_key($task[$role]))
            throw new DomainException('social_arrival_actor_changed');
        $matches=[];
        foreach (array_merge([$context['target']??null],$context['payload']['audience']??[]) as $actor) {
            if (!is_array($actor) || synth_social_actor_key($actor)!==synth_social_actor_key($verified[$role]))continue;
            if (!synth_social_text($actor['display_name']??null,256))throw new DomainException('social_arrival_actor_unavailable');
            $matches[$actor['display_name']]=[
                'form_id'=>$actor['form_id'],'origin_plugin'=>$actor['origin_plugin'],
                'playthrough_id'=>$actor['playthrough_id'],'display_name'=>$actor['display_name'],
            ];
        }
        if (count($matches)!==1)throw new DomainException('social_arrival_actor_unavailable');
        $actors[$role]=reset($matches);
    }
    if (!is_array($context['target']??null)
        || synth_social_actor_key($context['target'])!==synth_social_actor_key($actors['performer'])
        || !synth_social_pair_ready($context,$actors['performer'],$actors['recipient'],256))
        throw new DomainException('social_arrival_pair_unavailable');
    $task['performer']=$actors['performer'];$task['recipient']=$actors['recipient'];
    return $actors+['task'=>$task,'text'=>'I wanted to talk with you about '.$verified['topic'].'.'];
}

function synth_social_emit_arrival($db,array $event,array $task,string $root): void {
    $verified=synth_social_exchange_context($db,$event);
    if ($verified===null)throw new DomainException('social_arrival_not_verified');
    $context=synth_social_context($db,$event);
    $line=synth_social_arrival_line($context,$verified,$task);
    synth_dialogue_capture_origin_policy($db,$event,[
        'RECHAT_H'=>1,'RECHAT_P'=>100,'RECHAT_MODE'=>'tight','RECHAT_ALLOW_ACTIONS'=>false,
        'RANDOM_NARATION'=>false,'RANDOM_NARATION_CHANCE'=>0,'RANDOM_NARRATION_COOLDOWN'=>0,
    ],'STANDARD');
    $speaker=$line['performer'];$listener=$line['recipient'];
    $GLOBALS['SYNTH_INPUT_TARGET']=$speaker['display_name'];
    $GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']=$speaker;
    $GLOBALS['SYNTH_NAME']=$speaker['display_name'];
    $GLOBALS['SYNTH_SPEECH_ROLE']='npc';
    $GLOBALS['SYNTH_RESPONSE_SPEAKER_FORMID']=$speaker['form_id'];
    // Scope to canonical identity, including two actors with the same display name.
    $key=synth_tts_cache_key($root,$speaker['display_name'],$line['text'],$speaker);
    $utterance='social:'.substr(hash('sha256',$event['request_id'].'|'.$task['execution_id']),0,32);
    if (strtolower(trim(strval($GLOBALS['TTSFUNCTION']??'')))!=='none') {
        $generated=synth_tts_generate_for_response($root,$speaker['display_name'],$line['text'],$speaker);
        $correlation=['request_id'=>$event['request_id'],'turn_id'=>$event['turn_id'],
            'generation'=>$event['generation'],'utterance_id'=>$utterance];
        if (!$generated || synth_register_npc_tts_media($key,$db,$correlation,$root,['speaker_identity'=>$speaker])===null)
            synth_write_npc_tts_status($key,'failed',$correlation+['reason'=>'generation_or_publication_failed'],$root);
    }
    synth_start_json_response_buffer();
    synth_buffer_response_line('','social_task','',['task'=>$line['task']]);
    synth_buffer_response_line($speaker['display_name'],'say',$line['text'],[
        'speaker_formid'=>$speaker['form_id'],'speaker_refid'=>$speaker['form_id'],
        'listener'=>$listener['display_name'],'listener_formid'=>$listener['form_id'],
        'listener_plugin'=>$listener['origin_plugin'],'utterance_id'=>$utterance,'tts_cache_key'=>$key,
    ],'npc');
    synth_emit_buffered_json_response();
}
