<?php

require_once __DIR__.'/synth_rechat_prepare.php';

// Read one completed ancestor under the caller's native lock, never the global/latest eventlog.
function synth_rechat_history_response($connection, array $owner, string $request, int $chainId): array
{
    $result=@pg_query_params($connection,"SELECT p.canonical_event,p.event_sha256,p.event_type,e.response_body,c.context_payload
        FROM synth_meta.request_executions e JOIN synth_meta.protocol_events p USING(session_id,request_id)
        JOIN synth_meta.context_snapshots c ON c.session_id=e.session_id AND c.generation=e.generation AND c.snapshot_sequence=$3
        JOIN synth_meta.runtime_sessions s ON s.session_id=e.session_id AND s.playthrough_id=c.playthrough_id
        WHERE e.session_id=$1 AND e.request_id=$2 AND e.state='complete' AND e.generation=$4 AND p.generation=e.generation
            AND e.turn_id=$5 AND p.turn_id=e.turn_id AND e.event_type=p.event_type AND e.request_sha256=p.event_sha256
            AND s.runtime_variant=$6 AND octet_length(e.response_body)<=2097152",
        [$owner['session_id'],$request,$owner['context_sequence'],$owner['generation'],$owner['turn_id'],$owner['runtime_variant']]);
    if (!$result || !($row=pg_fetch_assoc($result))) throw new LogicException('rechat_history_response_unavailable');
    $event=json_decode($row['canonical_event'],true,flags:JSON_THROW_ON_ERROR);
    if (($event['request_id'] ?? null)!==$request || ($event['type'] ?? null)!==$row['event_type']
        || !synth_protocol_owns_dialogue_turn($event)
        || !hash_equals($row['event_sha256'],hash('sha256',synth_protocol_canonical_json($event)))) {
        throw new LogicException('rechat_history_canonical_mismatch');
    }
    foreach (['session_id','generation','turn_id','context_sequence','runtime_variant'] as $field) {
        if (($event[$field] ?? null)!==$owner[$field]) throw new LogicException('rechat_history_owner_mismatch');
    }
    $body=synth_protocol_decode_bytea($row['response_body']);
    $lines=synth_rechat_completed_lines($body,$event);
    if ($lines===null) throw new LogicException('rechat_history_completion_invalid');
    $context=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
    $scene=synth_rechat_response_scene_locked($connection,$event);
    if ($scene!==null) $context=$scene['payload'];
    $actors=[];
    foreach (array_merge([$context['player'] ?? null,$context['target'] ?? null],$context['audience'] ?? []) as $actor) {
        if (is_array($actor) && ($key=synth_rechat_actor_key($actor))!==null) $actors[$key]=$actor;
    }
    $playerKey=synth_rechat_actor_key($context['player'] ?? []);
    $selection=null;
    if (synth_protocol_owns_rechat($event)) {
        $result=@pg_query_params($connection,"SELECT s.role,s.identity FROM synth_meta.rechat_selections s
            JOIN synth_meta.rechat_continuations d USING(session_id,request_id)
            WHERE s.session_id=$1 AND s.request_id=$2 AND d.chain_id=$3 AND d.status='reserved'
                AND s.prepared_at IS NOT NULL AND s.role IN ('npc','narrator')",
            [$owner['session_id'],$request,$chainId]);
        if (!$result || !($selection=pg_fetch_assoc($result))) throw new LogicException('rechat_history_selection_unavailable');
        $selection['identity']=$selection['identity']===null ? null : json_decode($selection['identity'],true,flags:JSON_THROW_ON_ERROR);
    }
    $result=@pg_query_params($connection,'SELECT * FROM synth_meta.response_routes
        WHERE session_id=$1 AND request_id=$2 AND generation=$3 AND context_sequence=$4',
        [$owner['session_id'],$request,$owner['generation'],$owner['context_sequence']]);
    if (!$result) throw new RuntimeException('rechat_history_routes_unavailable');
    $routes=[];
    foreach (pg_fetch_all($result) as $route) $routes[$route['line_id']]=$route;
    $messages=[]; $lastNpc=null;
    foreach ($lines as $entry) {
        $line=$entry['line'];
        if ($line['type']!=='dialogue') continue;
        $route=$routes[$line['line_id']] ?? null;
        $speaker=$line['payload']['speaker'] ?? [];
        if (!$route || !hash_equals($route['line_sha256'],hash('sha256',$entry['wire']))
            || synth_protocol_canonical_json(json_decode($route['speaker'],true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_canonical_json($speaker)) {
            throw new LogicException('rechat_history_route_mismatch');
        }
        $key=synth_rechat_actor_key($speaker);
        $narrator=$key===null && ($speaker['kind'] ?? '')==='narrator' && ($speaker['id'] ?? '')==='narrator';
        if (!$narrator && ($key===null || !isset($actors[$key]))) throw new LogicException('rechat_history_speaker_unavailable');
        if ($key!==null && $key===$playerKey) continue; // Original player input is separately proven, not a generated rewrite.
        if ($selection!==null && ($selection['role']==='narrator' ? !$narrator
            : $key!==synth_rechat_actor_key($selection['identity'] ?? []))) throw new LogicException('rechat_history_speaker_not_selected');
        $text=$line['payload']['text'] ?? null;
        if (!is_string($text) || !mb_check_encoding($text,'UTF-8') || mb_strlen($text,'UTF-8')>4096) {
            throw new LogicException('rechat_history_text_invalid');
        }
        $message=['request_id'=>$request,'line_id'=>$line['line_id'],'role'=>$narrator ? 'narrator' : 'npc','speaker'=>$speaker,'text'=>$text];
        $messages[]=$message;
        if (!$narrator) $lastNpc=$message;
    }
    return ['event'=>$event,'messages'=>$messages,'last_npc'=>$lastNpc,'player'=>$context['player'] ?? null,
        'response_sha256'=>hash('sha256',$body),'bytes'=>strlen($body)];
}

// Bounded recent ancestry is prompt context only, not a claim of client delivery or durable NPC memory.
function synth_rechat_history($db, array $event): array
{
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event) {
        if ($owner!==$event) throw new LogicException('rechat_history_owner_changed');
        $selection=synth_rechat_prepared_selection($db,$event);
        if ($selection['role']==='terminal') throw new LogicException('rechat_history_terminal');
        $chainId=intval($selection['chain_id']);
        $result=@pg_query_params($connection,'SELECT root_request_id FROM synth_meta.rechat_chains WHERE id=$1 AND session_id=$2',
            [$chainId,$event['session_id']]);
        if (!$result || !($chain=pg_fetch_assoc($result))) throw new LogicException('rechat_history_chain_unavailable');
        $node=$event; $visited=[$event['request_id']=>true]; $groups=[]; $bytes=0; $reachedRoot=false;
        for ($depth=0;$depth<64 && $bytes<8*1024*1024;++$depth) {
            $edge=null; $actionParent=null;
            if (synth_protocol_owns_rechat($node)) {
                $result=@pg_query_params($connection,"SELECT d.* FROM synth_meta.rechat_continuations d
                    JOIN synth_meta.rechat_selections s USING(session_id,request_id)
                    WHERE d.session_id=$1 AND d.request_id=$2 AND d.chain_id=$3 AND d.status='reserved'
                        AND s.prepared_at IS NOT NULL AND s.role IN ('npc','narrator')",
                    [$event['session_id'],$node['request_id'],$chainId]);
                if (!$result || !($edge=pg_fetch_assoc($result))
                    || $edge['parent_request_id']!==($node['payload']['reply_to_request_id'] ?? null)
                    || $edge['parent_line_id']!==($node['payload']['reply_to_line_id'] ?? null)) throw new LogicException('rechat_history_edge_unavailable');
                $parent=$edge['parent_request_id'];
            } elseif (($node['type'] ?? '')==='action_result') {
                $actionParent=synth_rechat_action_chain_origin($connection,$event,$node,true);
                $parent=$actionParent['request_id'];
            } else throw new LogicException('rechat_history_unexpected_root');
            if (isset($visited[$parent])) throw new LogicException('rechat_history_cycle');
            $visited[$parent]=true;
            $response=synth_rechat_history_response($connection,$event,$parent,$chainId);
            if ($edge!==null && (!hash_equals($edge['parent_response_sha256'],$response['response_sha256'])
                || ($response['last_npc']['line_id'] ?? null)!==$edge['parent_line_id']
                || synth_rechat_actor_key($response['last_npc']['speaker'] ?? [])!==synth_rechat_actor_key($node['payload']['actor'] ?? []))) {
                throw new LogicException('rechat_history_parent_mismatch');
            }
            if ($actionParent!==null && synth_protocol_canonical_json($actionParent)!==synth_protocol_canonical_json($response['event'])) {
                throw new LogicException('rechat_history_action_mismatch');
            }
            $groups[]=$response['messages']; $bytes+=$response['bytes']; $node=$response['event'];
            if ($parent===$chain['root_request_id']) { $reachedRoot=true; break; }
        }
        $messages=[];
        foreach (array_reverse($groups) as $group) $messages=array_merge($messages,$group);
        if ($reachedRoot && in_array($node['type'],['input_text','input_audio'],true)) {
            $result=@pg_query_params($connection,'SELECT input_text FROM synth_meta.dialogue_origin_policies WHERE session_id=$1 AND request_id=$2',
                [$event['session_id'],$node['request_id']]);
            if (!$result) throw new RuntimeException('rechat_history_input_unavailable');
            $origin=pg_fetch_assoc($result);
            if ($origin && $origin['input_text']!==null) {
                require_once __DIR__.'/synth_dialogue_policy.php';
                $input=synth_dialogue_origin_input($node,$node['type']==='input_audio' ? $origin['input_text'] : null);
                if ($input!==$origin['input_text']) throw new LogicException('rechat_history_input_mismatch');
                array_unshift($messages,['request_id'=>$node['request_id'],'role'=>'player','speaker'=>$response['player'],'text'=>$input]);
            }
        }
        $truncated=!$reachedRoot; $size=0; $recent=[];
        foreach (array_reverse($messages) as $message) {
            $length=strlen(json_encode($message,JSON_THROW_ON_ERROR));
            if (count($recent)>=64 || $size+$length>65536) { $truncated=true; break; }
            $recent[]=$message; $size+=$length;
        }
        return ['truncated'=>$truncated,'messages'=>array_reverse($recent)];
    },2000);
}
