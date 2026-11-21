<?php

require_once __DIR__.'/synth_rechat_prepare.php';

// Read one completed ancestor under the caller's native lock, never the global/latest eventlog.
// Receipt-enrolled ancestry requires accepted delivery. last_npc names the final NPC line or is null when that
// line is excluded. An earlier heard sibling cannot replace it.
function synth_rechat_history_response($connection, array $owner, string $request, int $chainId): array
{
    $row=\Synth\Core\DbManager\Dao\RequestExecutionDao::current()->completedResponse([$owner['session_id'],$request,$owner['context_sequence'],
        $owner['generation'],$owner['turn_id'],$owner['runtime_variant']]);
    if (!$row) throw new LogicException('rechat_history_response_unavailable');
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
    $originalTarget=$context['target'] ?? null;
    $scene=synth_rechat_response_scene_locked($connection,$event);
    if ($scene!==null) $context=$scene['payload'];
    $actors=[];
    foreach (array_merge([$context['player'] ?? null,$context['target'] ?? null],$context['audience'] ?? []) as $actor) {
        if (is_array($actor) && ($key=synth_rechat_actor_key($actor))!==null) $actors[$key]=$actor;
    }
    $playerKey=synth_rechat_actor_key($context['player'] ?? []);
    $selection=null;
    if (synth_protocol_owns_rechat($event)) {
        $selection=\Synth\Core\DbManager\Dao\RechatSelectionDao::current()->preparedIdentity($owner['session_id'],$request,$chainId);
        if (!$selection) throw new LogicException('rechat_history_selection_unavailable');
        $selection['identity']=$selection['identity']===null ? null : json_decode($selection['identity'],true,flags:JSON_THROW_ON_ERROR);
    }
    $found=\Synth\Core\DbManager\Dao\DialogueRouteDao::current()->forRequest($owner['session_id'],$request,$owner['generation'],$owner['context_sequence']);
    if ($found===null) throw new RuntimeException('rechat_history_routes_unavailable');
    $routes=[];
    foreach ($found as $route) $routes[$route['line_id']]=$route;
    $states=\Synth\Core\DbManager\Dao\DialogueUtteranceDao::current()->lineStates($owner['session_id'],$request,$owner['generation'],$owner['context_sequence']);
    if ($states===null) throw new RuntimeException('rechat_history_utterances_unavailable');
    $delivery=array_column($states,'state','line_id');
    $messages=[]; $lastNpc=null; $playerLines=[];
    foreach ($lines as $entry) {
        $line=$entry['line'];
        if ($line['type']!=='dialogue') continue;
        $included=true;
        if (($line['payload']['receipt_required'] ?? null)===true) {
            $state=$delivery[$line['line_id']] ?? null;
            if ($state===null) throw new LogicException('rechat_history_receipt_line_unenrolled');
            $included=in_array($state,['spoken','presented'],true);
        }
        $route=$routes[$line['line_id']] ?? null;
        $speaker=$line['payload']['speaker'] ?? [];
        if (!$route || !hash_equals($route['line_sha256'],hash('sha256',$entry['wire']))
            || synth_protocol_canonical_json(json_decode($route['speaker'],true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_canonical_json($speaker)) {
            throw new LogicException('rechat_history_route_mismatch');
        }
        $key=synth_rechat_actor_key($speaker);
        $narrator=$key===null && ($speaker['kind'] ?? '')==='narrator' && ($speaker['id'] ?? '')==='narrator';
        if (!$narrator && ($key===null || !isset($actors[$key]))) throw new LogicException('rechat_history_speaker_unavailable');
        $text=$line['payload']['text'] ?? null;
        if (!$included) {
            if ($key!==null && $key===$playerKey) throw new LogicException('rechat_history_receipt_line_unexpected');
            if (!$narrator) $lastNpc=null;
            continue;
        }
        if ($key!==null && $key===$playerKey) {
            // Ordinary roots prove their player input separately. Only an Auto Chat root reads its generated line,
            // and only through synth_autochat_root_line.
            if (is_string($text) && mb_check_encoding($text,'UTF-8') && mb_strlen($text,'UTF-8')<=4096)
                $playerLines[]=['line_id'=>$line['line_id'],'text'=>$text,'route'=>$route];
            else $playerLines[]=['line_id'=>$line['line_id'],'text'=>null,'route'=>['listener_state'=>'invalid','listener'=>null]];
            continue;
        }
        if ($selection!==null && ($selection['role']==='narrator' ? !$narrator
            : $key!==synth_rechat_actor_key($selection['identity'] ?? []))) throw new LogicException('rechat_history_speaker_not_selected');
        if (!is_string($text) || !mb_check_encoding($text,'UTF-8') || mb_strlen($text,'UTF-8')>4096) {
            throw new LogicException('rechat_history_text_invalid');
        }
        $message=['request_id'=>$request,'line_id'=>$line['line_id'],'role'=>$narrator ? 'narrator' : 'npc','speaker'=>$speaker,'text'=>$text];
        $messages[]=$message;
        if (!$narrator) $lastNpc=$message;
    }
    return ['event'=>$event,'messages'=>$messages,'last_npc'=>$lastNpc,'player'=>$context['player'] ?? null,
        'target'=>$originalTarget,'player_lines'=>$playerLines,
        'hearers'=>array_keys($actors),
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
        $chain=\Synth\Core\DbManager\Dao\RechatChainDao::current()->rootRequest($chainId,$event['session_id']);
        if (!$chain) throw new LogicException('rechat_history_chain_unavailable');
        $node=$event; $visited=[$event['request_id']=>true]; $groups=[]; $bytes=0; $reachedRoot=false; $parentEvent=null;
        for ($depth=0;$depth<64 && $bytes<8*1024*1024;++$depth) {
            $edge=null; $actionParent=null;
            if (synth_protocol_owns_rechat($node)) {
                if (!($edge=\Synth\Core\DbManager\Dao\RechatContinuationDao::current()->preparedEdge($event['session_id'],$node['request_id'],$chainId))
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
            $groups[]=$response; $bytes+=$response['bytes']; $node=$response['event']; $parentEvent ??= $node;
            if ($parent===$chain['root_request_id']) { $reachedRoot=true; break; }
        }
        // A Close or Everyone responder recalls only chain lines it was projected to hear; it always heard the root.
        $rootAudience=$parentEvent===null ? null : synth_rechat_root_audience_locked($connection,$event,$parentEvent);
        $reader=$selection['role']==='npc' && $rootAudience!==null
            ? synth_rechat_actor_key($selection['identity'] ?? []) : null;
        $messages=[];
        foreach (array_reverse($groups) as $group) {
            if ($reader===null || in_array($reader,$group['hearers'],true)) $messages=array_merge($messages,$group['messages']);
        }
        if ($reachedRoot && in_array($node['type'],['input_text','input_audio'],true)) {
            $origin=\Synth\Core\DbManager\Dao\DialogueOriginPolicyDao::current()->inputPolicy($event['session_id'],$node['request_id']);
            if ($origin===null) throw new RuntimeException('rechat_history_input_unavailable');
            require_once __DIR__.'/synth_autochat.php';
            // The frozen policy and the canonical root must agree on Auto Chat; a mismatch is never resolved either way.
            if (($origin && $origin['execution_mode']==='AUTOCHAT')!==synth_autochat_selected($node)) {
                throw new LogicException('rechat_history_autochat_unproven');
            }
            if (synth_autochat_selected($node)) {
                array_unshift($messages,['request_id'=>$node['request_id'],'role'=>'player','speaker'=>$response['player'],
                    'text'=>synth_autochat_root_line($response,$node)]);
            } elseif ($origin && $origin['input_text']!==null) {
                require_once dirname(__DIR__, 3).'/lib'.'/synth_dialogue_policy.php';
                $input=synth_dialogue_origin_input($node,$node['type']==='input_audio' ? $origin['input_text'] : null);
                if ($input!==$origin['input_text']) throw new LogicException('rechat_history_input_mismatch');
                // The root's public intent is kept apart from its transport responder and from who could hear.
                array_unshift($messages,['request_id'=>$node['request_id'],'role'=>'player','speaker'=>$response['player'],'text'=>$input]
                    +(($node['payload']['audience_intent'] ?? null)==='everyone' ? ['addressed'=>'everyone'] : []));
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
