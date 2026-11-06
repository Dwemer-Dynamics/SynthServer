<?php
// A saved instruction is executable data only after canonical player admission, never because a diary promises it.
const SYNTH_SOCIAL_STATUSES=['waiting_departure','waiting_return','ready','travelling','arrived','conversing','completed','cancelled','failed'];
function synth_social_text($text,int $limit,bool $empty=false): bool {
    return is_string($text) && ($empty || trim($text)!=='') && strlen($text)<=$limit && !str_contains($text,chr(0)) && preg_match('//u',$text)===1;
}
function synth_social_ref_valid($ref): bool {
    return is_array($ref) && count($ref)===2 && array_diff(array_keys($ref),['form_id','origin_plugin'])===[]
        && is_string($ref['form_id']??null) && preg_match('/^0x[0-9a-f]{8}$/Di',$ref['form_id'])===1
        && strtoupper($ref['form_id'])!=='0X00000000' && is_string($ref['origin_plugin']??null)
        && synth_social_text($ref['origin_plugin'],255) && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di',$ref['origin_plugin'])===1;
}
function synth_social_ref_key(array $ref): string {return strtoupper($ref['form_id']??'').'|'.strtolower($ref['origin_plugin']??'');}
function synth_social_actor_key(array $actor): string {return synth_social_ref_key($actor).'|'.($actor['playthrough_id']??'');}
function synth_social_supported(array $event): bool {
    return ($event['protocol_version']??null)===2 && ($event['runtime_variant']??'')==='flat'
        && count(array_diff(['social.tasks.v1','context.saved_anchor','dialogue.turn_ownership'], $event['capabilities']??[]))===0;
}
function synth_social_location(array $context): ?array {
    $ref=$context['payload']['world']['location_ref']??$context['world']['location_ref']??null;
    return synth_social_ref_valid($ref)?$ref:null;
}
// Shared parser/test vectors bind the structured request to an explicit instruction, not hypothetical/quoted dialogue.
function synth_social_parse_directive(string $text,string $performer=''): ?array {
    if (!synth_social_text($text,1200)) return null;
    $text=trim($text);
    if ($performer!=='' && preg_match('/^'.preg_quote($performer,'/').'\s*[,;:]\s*(.*)$/Diu',$text,$m)) $text=$m[1];
    $when='now';$location='';
    if (preg_match('/^(?:when|once)\s+(?:we|you)\s+(?:(?:get|come|are|head)\s+back|return)(?:\s+(?:to|at|in)\s+([^,]+))?\s*,\s*(.+)$/Diu',$text,$m)) {
        $when='on_return';$location=trim($m[1]??'');$text=$m[2];
    } elseif (preg_match('/^(.+?)\s+(?:when|once)\s+(?:we|you)\s+(?:(?:get|come|are|head)\s+back|return)(?:\s+(?:to|at|in)\s+(.+?))?[.!]?$/Diu',$text,$m)) {
        $when='on_return';$location=trim($m[2]??'');$text=$m[1];
    }
    if (!preg_match('/^(?:(?:can|could|would)\s+you\s+)?(?:please\s+)?(?:go\s+)?(?:talk|speak|chat)\s+(?:to|with)\s+(.+?)(?:\s+about\s+(.+))?[.!]?$/Diu',$text,$m)) return null;
    $name=rtrim(trim($m[1]),'.!');$topic=rtrim(trim($m[2]??'general conversation'),'.!');
    if (!synth_social_text($name,128) || !synth_social_text($topic,512) || !synth_social_text($location,128,true)
        || preg_match('/["\x{201C}\x{201D}<>]/u',$name.$location)) return null;
    return ['recipient_name'=>$name,'topic'=>$topic,'when'=>$when,'location_name'=>$location];
}
function synth_social_request_valid(array $payload): bool {
    if (array_diff(array_keys($payload),['performer','recipient_name','topic','when','location_name','source_text'])!==[]
        || !is_array($payload['performer']??null) || !synth_social_text($payload['recipient_name']??null,128)
        || !synth_social_text($payload['topic']??null,512) || !synth_social_text($payload['source_text']??null,1200)
        || !in_array($payload['when']??null,['now','on_return'],true) || !synth_social_text($payload['location_name']??'',128,true)) return false;
    $parsed=synth_social_parse_directive($payload['source_text'],$payload['performer']['display_name']??'');
    if (!$parsed) return false;
    foreach (['recipient_name','topic','when','location_name'] as $key)
        if (mb_strtolower(trim($parsed[$key]))!==mb_strtolower(trim($payload[$key]??''))) return false;
    return true;
}
function synth_social_initial_status(string $when,?array $here,?array $destination): string {
    if ($when==='now') return 'ready';
    return $here!==null && $destination!==null && synth_social_ref_key($here)!==synth_social_ref_key($destination)
        ? 'waiting_return':'waiting_departure';
}
function synth_social_context_status(array $task,?array $here): string {
    $status=$task['status'];$dest=$task['location_ref']??null;
    if ($here===null || !synth_social_ref_valid($dest)) return $status;
    $at=synth_social_ref_key($here)===synth_social_ref_key($dest);
    if ($status==='waiting_departure' && !$at) return 'waiting_return';
    if ($status==='waiting_return' && $at) return 'ready';
    return $status;
}
function synth_social_pair_ready(array $context,array $performer,array $recipient,float $range,bool $requireFollower=true): bool {
    $states=$context['payload']['actor_states']??[];$found=[];
    foreach ([$performer,$recipient] as $index=>$actor) {
        $matches=array_values(array_filter($states,static fn($state)=>is_array($state['actor']??null)
            && synth_social_actor_key($state['actor'])===synth_social_actor_key($actor)));
        if (count($matches)!==1) return false;$state=$matches[0];
        if (($state['alive']??null)!==true || ($state['disabled']??null)!==false || ($state['in_combat']??null)!==false
            || ($state['hostile_to_player']??null)!==false || ($index===0 && $requireFollower && ($state['teammate']??null)!==true)
            || !in_array($state['life_state']??'alive',['alive','unknown'],true)) return false;
        $found[]=$state;
    }
    if (synth_social_actor_key($performer)===synth_social_actor_key($recipient)
        || ($performer['playthrough_id']??null)!==($recipient['playthrough_id']??null)
        || strtoupper($performer['form_id']??'')==='0X00000014' || strtoupper($recipient['form_id']??'')==='0X00000014') return false;
    $distance=0.;foreach (['x','y','z'] as $axis) {
        $a=$found[0]['position'][$axis]??null;$b=$found[1]['position'][$axis]??null;
        if ((!is_int($a)&&!is_float($a)) || (!is_int($b)&&!is_float($b)) || !is_finite((float)$a) || !is_finite((float)$b)) return false;
        $distance+=($a-$b)**2;
    }
    return is_finite($distance) && $distance<=$range**2;
}

function synth_social_progress_valid(array $payload): bool {
    if (array_diff(array_keys($payload),['task_id','expected_revision','execution_id','phase','proof'])!==[]
        || !is_string($payload['task_id']??null)||preg_match('/^social:[0-9a-f]{32}$/D',$payload['task_id'])!==1
        || !is_string($payload['execution_id']??null)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$payload['execution_id'])!==1
        || !is_int($payload['expected_revision']??null)||$payload['expected_revision']<1||$payload['expected_revision']>9007199254740991
        || !in_array($payload['phase']??null,['started','arrived','spoken','failed','cancelled'],true)
        || (($payload['phase']==='spoken')!==array_key_exists('proof',$payload)))return false;
    if ($payload['phase']==='spoken') {
        $proof=$payload['proof'];if(!is_array($proof)||count($proof)!==2||array_diff(array_keys($proof),['request_id','line_id'])!==[])return false;
        foreach(['request_id','line_id'] as $key)if(!is_string($proof[$key]??null)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$proof[$key])!==1)return false;
    }
    return true;
}
