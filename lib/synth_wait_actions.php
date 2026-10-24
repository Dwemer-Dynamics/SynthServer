<?php

// Waiting changes only the addressed NPC. It is never an autonomous or narrator action.
function synth_wait_action_supported(array $event, string $code): bool
{
    $name=match ($code) {'WaitHere'=>'wait_here','ReleaseWait'=>'release_wait',default=>null};
    return $name!==null && ($event['protocol_version'] ?? 1)===2
        && ($event['runtime_variant'] ?? '')==='flat'
        && in_array($event['type'] ?? '',['input_text','input_audio'],true)
        && in_array($event['payload']['control']['mode'] ?? 'STANDARD',['STANDARD','WHISPER','CLOSE','SHOUT'],true)
        && count(array_diff(['dialogue.turn_ownership','action.'.$name],$event['capabilities'] ?? []))===0;
}

function synth_wait_action_is_intent(array $action): bool
{
    return in_array($action['name'] ?? '',['wait_here','release_wait'],true)
        || in_array($action['server_code'] ?? '',['WaitHere','ReleaseWait'],true)
        || in_array($action['capability'] ?? '',['action.wait_here','action.release_wait'],true);
}

function synth_wait_action_legacy_empty(array $line): bool
{
    $metadata=$line['metadata'] ?? [];
    if (!is_array($metadata)) return false;
    if (array_diff(array_keys($metadata),['request_id','request_type','command','command_name','command_args','args',
        'target','item','amount','location','message','instruction','speed'])!==[]) return false;
    foreach ([$line,$metadata] as $part) {
        foreach (['target','item','amount','location','message','instruction','speed'] as $key)
            if (array_key_exists($key,$part) && $part[$key]!=='') return false;
        if (array_key_exists('arguments',$part)) return false;
        foreach (['command_args','args'] as $key) {
            if (!array_key_exists($key,$part)) continue;
            if (!is_array($part[$key])) return false;
            $values=$key==='args' ? array_diff_key($part[$key],['request_type'=>true]) : $part[$key];
            if (array_filter($values,static fn($v)=>$v!=='')!==[]) return false;
        }
    }
    return true;
}

function synth_wait_action_parameters_empty($parameter): bool
{
    return $parameter==='' || $parameter===[] || ($parameter instanceof stdClass && get_object_vars($parameter)===[]);
}

function synth_wait_action_validate($db, array $event, array $action): void
{
    $code=match ($action['name'] ?? '') {'wait_here'=>'WaitHere','release_wait'=>'ReleaseWait',default=>''};
    if (!synth_wait_action_supported($event,$code)
        || ($action['capability'] ?? '')!=='action.'.$action['name']
        || (isset($action['server_code']) && $action['server_code']!==$code)
        || array_key_exists('target',$action)
        || !($action['arguments'] ?? null) instanceof stdClass || get_object_vars($action['arguments'])!==[]
        || !is_array($action['actor'] ?? null)) throw new DomainException('invalid_wait_intent');
    $context=synth_protocol_current_context($db,$event);
    $actor=$context ? synth_protocol_response_actor_identity($db,$event,$context) : null;
    if (!is_array($actor) || strcasecmp($actor['form_id'] ?? '','0x00000014')===0
        || synth_protocol_canonical_json($action['actor'])!==synth_protocol_canonical_json($actor)) throw new DomainException('wait_actor_not_owned');
}

// Existing databases predate ReleaseWait. Add only its missing runtime definition; explicit rows always win.
function synth_wait_action_missing_release_fallback(array $rows, array $definition): void
{
    if (array_key_exists('ReleaseWait',$rows)) return;
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (!synth_wait_action_supported($event,'ReleaseWait')) return;
    $GLOBALS['ENABLED_FUNCTIONS'][]='ReleaseWait';
    $GLOBALS['FUNCTIONS'][]=$definition;
    $GLOBALS['BASE_FUNCTIONS']['ReleaseWait']=$definition;
}
