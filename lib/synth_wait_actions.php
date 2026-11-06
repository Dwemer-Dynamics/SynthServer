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
    if(function_exists('synth_jev_action_owner')
        && ($GLOBALS['SYNTH_JEV_WAIT_HANDOFF']??null)===synth_jev_action_owner($event)
        && !synth_wait_jev_capture_fresh($db,$event))throw new DomainException('wait_handoff_capture_stale');
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


function synth_wait_actor_key($actor): ?string
{
    if (!is_array($actor) || array_diff(array_keys($actor),['form_id','origin_plugin','playthrough_id','display_name'])
        || !is_string($actor['form_id']??null) || preg_match('/^0x[0-9a-f]{8}$/Di',$actor['form_id'])!==1
        || in_array(strtoupper($actor['form_id']),['0X00000000','0XFFFFFFFF','0X00000014'],true)
        || !is_string($actor['origin_plugin']??null) || strlen($actor['origin_plugin'])>255
        || preg_match('#^[^/\\\\\x00-\x1f]+\.(esm|esp|esl)$#Di',$actor['origin_plugin'])!==1
        || !is_string($actor['playthrough_id']??null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$actor['playthrough_id'])!==1)
        return null;
    if (isset($actor['display_name']) && (!is_string($actor['display_name']) || trim($actor['display_name'])==='' || strlen($actor['display_name'])>255
        || str_contains($actor['display_name'],"\0") || preg_match('//u',$actor['display_name'])!==1))return null;
    return strtoupper($actor['form_id']).'|'.strtolower($actor['origin_plugin']).'|'.$actor['playthrough_id'];
}

function synth_wait_owned_package_valid($package,bool $held): bool
{
    if(!is_string($package)||preg_match('/^0x[0-9a-f]{8}$/Di',$package)!==1)return false;
    $form=(int)hexdec(substr($package,2));$local=$form&0xFFFFFF;
    return $held ? ($form>>24)!==0xFF && $local>=0x900 && $local<=0x9FF : $form===0;
}

// A terminal status alone is not a physical observation. Labels never confer actor ownership.
function synth_wait_result_observation(array $event,string $name,array $actor): ?array
{
    if(!in_array($name,['wait_here','release_wait'],true))throw new DomainException('invalid_wait_observation');
    $result=$event['payload']['result']??[];
    if (($result['status']??'')!=='succeeded')return null;
    if (!in_array('action.wait_verified',$event['capabilities']??[],true))return null;
    $detail=$result['detail']??null;
    try {$proof=is_string($detail)&&strlen($detail)<=1024&&!str_contains($detail,"\0")
        ? json_decode($detail,true,8,JSON_THROW_ON_ERROR):null;}catch(JsonException){$proof=null;}
    if (!is_array($proof)||array_is_list($proof)||array_diff(array_keys($proof),['schema','action_id','action','actor','owned_hold','restrained','lease_ms','reload_policy','owned_package','stationary'])
        || count($proof)!==10 || ($proof['schema']??'')!=='synth.action.wait.result.v1'
        || ($proof['action_id']??null)!==($result['action_id']??null) || ($proof['action']??null)!==$name
        || synth_wait_actor_key($proof['actor']??null)===null || synth_wait_actor_key($proof['actor'])!==synth_wait_actor_key($actor)
        || ($proof['owned_hold']??null)!==($name==='wait_here') || !is_bool($proof['restrained']??null) || !is_bool($proof['stationary']??null)
        || !synth_wait_owned_package_valid($proof['owned_package']??null,$name==='wait_here') || ($name==='wait_here'&&$proof['stationary']!==true)
        || ($proof['lease_ms']??null)!==90000 || ($proof['reload_policy']??null)!=='release_owned_hold')
        throw new DomainException('invalid_wait_observation');
    return $proof;
}

// Reuse the accepted intent and immutable capture, including after a registry-name change.
function synth_wait_result_context($db,array $event): array
{
    $result=$event['payload']['result'];$session=$db->escapeLiteral($event['session_id']);$action=$db->escapeLiteral($result['action_id']);
    $request=$db->escapeLiteral($event['request_id']);$turn=$db->escapeLiteral($event['turn_id']);$generation=intval($event['generation']);
    $sequence=$db->escapeLiteral(strval($event['context_sequence']));
    $row=$db->fetchOne("SELECT i.actor_identity_id,i.action_name,i.capability,i.arguments,i.target_identity_id,i.created_at AS intent_created_at,
            receipt.received_at AS result_received_at,
            a.full_form_id,a.origin_plugin,a.playthrough_id,source.canonical_event AS origin_event,source.event_sha256 AS origin_sha256,
            receipt.canonical_event AS receipt_event,receipt.event_sha256 AS receipt_sha256,c.context_payload
        FROM synth_meta.action_intents i JOIN synth.actor_identities a ON a.id=i.actor_identity_id AND a.game='fo4'
        JOIN synth_meta.protocol_events source ON source.session_id=i.session_id AND source.request_id=i.request_id AND source.generation=i.generation AND source.turn_id=i.turn_id
        JOIN synth_meta.request_executions issued ON issued.session_id=source.session_id AND issued.request_id=source.request_id AND issued.request_sha256=source.event_sha256
        JOIN synth_meta.protocol_events receipt ON receipt.session_id=i.session_id AND receipt.request_id=$request AND receipt.generation=i.generation AND receipt.turn_id=i.turn_id
        JOIN synth_meta.request_executions accepted ON accepted.session_id=receipt.session_id AND accepted.request_id=receipt.request_id AND accepted.request_sha256=receipt.event_sha256
        JOIN synth_meta.context_snapshots c ON c.session_id=i.session_id AND c.generation=i.generation AND c.snapshot_sequence=".intval($event['context_sequence'])."
        JOIN synth_meta.runtime_sessions r ON r.session_id=i.session_id AND r.playthrough_id=a.playthrough_id
        WHERE i.action_id=$action AND i.idempotency_key=".$db->escapeLiteral($result['idempotency_key'])." AND i.session_id=$session AND i.generation=$generation AND i.turn_id=$turn
            AND i.state IN ('pending','dispatched','complete') AND source.canonical_event->>'context_sequence'=$sequence
            AND receipt.event_type='action_result' AND accepted.state IN ('claimed','streaming','complete')
            AND r.state='active' AND r.active_generation=i.generation AND r.active_turn_id=i.turn_id AND r.active_turn_sequence=c.snapshot_sequence
            AND r.runtime_variant='flat' AND c.playthrough_id=a.playthrough_id");
    if (!$row)throw new DomainException('wait_result_owner_mismatch');
    $decode=static function($raw): array {if(is_string($raw)&&strlen($raw)<=1048576)$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($raw))throw new DomainException('wait_result_owner_mismatch');return $raw;};
    $origin=$decode($row['origin_event']);$receipt=$decode($row['receipt_event']);$payload=$decode($row['context_payload']);
    $code=match($row['action_name']){'WaitHere','wait_here'=>'WaitHere','ReleaseWait','release_wait'=>'ReleaseWait',default=>''};
    $name=$code==='WaitHere'?'wait_here':'release_wait';$arguments=is_string($row['arguments'])?json_decode($row['arguments']):$row['arguments'];
    if ($code==='' || !synth_wait_action_supported($origin,$code) || ($event['protocol_version']??1)!==2 || ($event['runtime_variant']??'')!=='flat'
        || $row['capability']!=='action.'.$name || $row['target_identity_id']!==null || !($arguments instanceof stdClass)||get_object_vars($arguments)!==[]
        || !in_array('action.'.$name,$event['capabilities']??[],true) || synth_protocol_canonical_json($receipt)!==synth_protocol_canonical_json($event)
        || !hash_equals($row['origin_sha256'],hash('sha256',synth_protocol_canonical_json($origin)))
        || !hash_equals($row['receipt_sha256'],hash('sha256',synth_protocol_canonical_json($event))))throw new DomainException('wait_result_owner_mismatch');
    $actor=null;$key=synth_wait_actor_key(['form_id'=>$row['full_form_id'],'origin_plugin'=>$row['origin_plugin'],'playthrough_id'=>$row['playthrough_id']]);
    foreach(array_merge([$payload['target']??null],$payload['audience']??[]) as $candidate)
        if(synth_wait_actor_key($candidate)===$key)$actor=$candidate;
    if($actor===null || synth_wait_actor_key($payload['target']??null)!==$key)throw new DomainException('wait_result_owner_mismatch');
    if(in_array('action.wait_verified',$event['capabilities']??[],true)!==in_array('action.wait_verified',$origin['capabilities']??[],true))throw new DomainException('wait_result_capability_mismatch');
    $proof=synth_wait_result_observation($event,$name,$actor);
    if($proof!==null) {
        $unique=$db->fetchOne('SELECT '.$db->escapeLiteral($result['detail']).' IS JSON OBJECT WITH UNIQUE KEYS AS valid');
        if(!in_array($unique['valid']??null,[true,'t',1,'1'],true))throw new DomainException('invalid_wait_observation');
    }
    $message=synth_wait_result_message($name,$actor['display_name']??'The addressed NPC',$result['status'],$proof!==null,$proof);
    return ['actor'=>$actor,'actor_identity_id'=>intval($row['actor_identity_id']),'action'=>$name,'status'=>$result['status'],
        'verified'=>$proof!==null,'proof'=>$proof,'message'=>$message,'context'=>$payload,'origin_sha256'=>$row['origin_sha256'],
        'origin_event'=>$origin,'intent_created_at'=>$row['intent_created_at']??null,'result_received_at'=>$row['result_received_at']??null];
}

// A deliberately small spoken/typed companion-command shortcut. This does not
// parse diary prose, promises, quoted examples, multiple actions or arbitrary targets.
function synth_wait_explicit_directive(string $text,string $actorName=''): ?string
{
    if(strlen($text)>255 || str_contains($text,"\0") || preg_match('//u',$text)!==1)return null;
    $text=trim($text);
    if($actorName!=='' && strlen($actorName)<=255)
        $text=preg_replace('/^'.preg_quote($actorName,'/').'\s*[,;:]\s*/Diu','',$text);
    $prefix='(?:(?:can|could|would) you\s+)?(?:please\s+)?';
    $suffix='(?:\s+please)?[.!?]?';
    if(preg_match('/^'.$prefix.'(?:wait here|stay here|hold position)'.$suffix.'$/Diu',$text)===1)return 'WaitHere';
    if(preg_match('/^'.$prefix.'(?:stop waiting(?: now)?|release wait|resume following)'.$suffix.'$/Diu',$text)===1)return 'ReleaseWait';
    return null;
}

// Pure policy over the already authenticated immutable context. JEV confidence
// classifies intent; companion status and these exact facts supply command eligibility.
function synth_wait_jev_handoff_plan(array $event,?string $transcript,?array $policy,array $context): ?array
{
    if($policy===null || ($event['protocol_version']??1)!==2 || ($event['runtime_variant']??'')!=='flat'
        || !in_array($event['type']??'',['input_text','input_audio'],true)
        || ($event['payload']['control']['mode']??'STANDARD')!=='STANDARD'
        || !is_int($event['generation']??null) || $event['generation']<1
        || !is_int($event['context_sequence']??null) || $event['context_sequence']<1
        || !in_array($policy['code']??null,['WaitHere','ReleaseWait'],true) || !empty($policy['emitted'])
        || !is_string($policy['owner']??null) || !hash_equals($policy['owner'],synth_jev_action_owner($event))
        || ($policy['generation']??null)!==($event['generation']??null)
        || ($policy['context_sequence']??null)!==($event['context_sequence']??null)
        || count(array_diff(['action.wait_verified','context.wait_state'], $event['capabilities']??[]))!==0
        || !synth_wait_action_supported($event,$policy['code']))return null;
    $confidence=$policy['confidence']??null;
    $setting=$GLOBALS['JEV_ACTION_MIN_CONFIDENCE']??0.8;
    $threshold=is_numeric($setting)&&is_finite((float)$setting)?max(0.8,min(1.0,(float)$setting)):0.8;
    if(!(is_int($confidence)||is_float($confidence)) || !is_finite((float)$confidence)
        || $confidence<$threshold || $confidence>1)return null;
    $text=$event['type']==='input_text'?($event['payload']['text']??null):$transcript;
    $actor=$context['target']??null;
    if(!is_string($text)||!is_array($actor)||synth_wait_actor_key($actor)===null
        || synth_wait_actor_key($actor)!==synth_wait_actor_key($policy['actor']??null)
        || !is_string($policy['utterance_sha256']??null) || !hash_equals($policy['utterance_sha256'],hash('sha256',$text))
        || synth_wait_explicit_directive($text,strval($actor['display_name']??''))!==$policy['code'])return null;
    $state=synth_jev_action_actor_state($event,$context,$actor);
    if($state!==($policy['actor_state']??null) || ($state['captured']??false)!==true
        || ($state['teammate']??null)!==true || ($state['alive']??null)!==true
        || ($state['disabled']??null)!==false || ($state['in_combat']??null)!==false
        || ($state['hostile_to_player']??null)!==false || ($state['life_state']??'unknown')!=='alive'
        || ($state['line_of_sight']??'unavailable')==='blocked' || !is_bool($state['wait_held']??null))return null;
    // Only a known transition takes this shortcut; repeats and unknowns retain ordinary dialogue.
    if($state['wait_held']!==($policy['code']==='ReleaseWait'))return null;
    return ['code'=>$policy['code'],'actor'=>$actor,'confidence'=>(float)$confidence];
}

// Kept separate from the pure planner, then repeated by Wait validation while
// the existing native intent transaction holds the session lock.
function synth_wait_jev_capture_fresh($db,array $event): bool
{
    if(!is_int($event['generation']??null)||$event['generation']<1
        || !is_int($event['context_sequence']??null)||$event['context_sequence']<1)return false;
    $session=$db->escapeLiteral(synth_protocol_session_id($event));
    $generation=intval($event['generation']);$sequence=intval($event['context_sequence']);
    $row=$db->fetchOne("SELECT 1 AS fresh FROM synth_meta.context_snapshots c
        JOIN synth_meta.runtime_sessions r ON r.session_id=c.session_id
        WHERE c.session_id=$session AND c.generation=$generation AND c.snapshot_sequence=$sequence
            AND r.state='active' AND r.active_generation=c.generation AND r.runtime_variant='flat'
            AND c.playthrough_id=r.playthrough_id
            AND c.received_at<=clock_timestamp() AND c.received_at>=clock_timestamp()-INTERVAL '5 seconds'
            AND NOT EXISTS(SELECT 1 FROM synth_meta.context_snapshots newer
                WHERE newer.session_id=c.session_id AND newer.generation=c.generation
                    AND newer.snapshot_sequence>c.snapshot_sequence)");
    return is_array($row) && in_array($row['fresh']??null,[true,'1',1],true);
}

// The existing client queues actions until the response/speech finishes. Complete
// this command response before any dialogue inference; its existing result RPC
// supplies the later natural acknowledgement using the verified physical receipt.
function synth_wait_try_jev_handoff($db,array $event,?string $transcript=null): bool
{
    $policy=synth_jev_action_policy();
    if(empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || $policy===null
        || !in_array($policy['code']??null,['WaitHere','ReleaseWait'],true))return false;
    $context=synth_protocol_current_context($db,$event);
    $plan=is_array($context)?synth_wait_jev_handoff_plan($event,$transcript,$policy,$context):null;
    if($plan===null)return false;
    if(!synth_wait_jev_capture_fresh($db,$event))return false;
    if(!in_array($plan['code'],$GLOBALS['ENABLED_FUNCTIONS']??[],true)
        || !synthActionCodeIsNegotiated($plan['code']))return false;
    synth_protocol_assert_request_active();
    $GLOBALS['SYNTH_JEV_WAIT_HANDOFF']=synth_jev_action_owner($event);
    $trace=['selected_code'=>$plan['code'],'action_code'=>$plan['code'],
        'confidence'=>$plan['confidence'],'route'=>'jev_handoff','status'=>'pending','reason'=>'handoff'];
    if(is_numeric($GLOBALS['SYNTH_TURN_START_TIME']??null))
        $trace['elapsed_ms']=round((microtime(true)-(float)$GLOBALS['SYNTH_TURN_START_TIME'])*1000,2);
    try { synth_jev_action_trace('handoff',$event,$trace); }
    catch(Throwable) { /* Optional diagnostics do not grant or remove command authority. */ }
    // Convert exactly once through the ordinary action builder, validation,
    // persisted intent and final response writer in either streaming mode.
    synth_buffer_command_response_line(strval($plan['actor']['display_name']??''),synthEncodeCommandAction($plan['code']));
    synth_emit_buffered_json_response();
    return true;
}

// Called only after action-result admission has committed. This is receipt
// evidence, never an inference from a selected choice, queued intent or dialogue.
function synth_wait_trace_committed_result(array $event,array $outcome): void
{
    if(!function_exists('synth_jev_action_trace') || ($event['type']??'')!=='action_result'
        || !is_array($outcome['origin_event']??null))return;
    $details=['action_code'=>($outcome['action']??'')==='wait_here'?'WaitHere':'ReleaseWait',
        'action_id'=>$event['payload']['result']['action_id']??'',
        'status'=>$outcome['status']??'failed','verified'=>($outcome['verified']??false)===true,
        'reason'=>!empty($outcome['verified'])?'verified':'unverified'];
    if(is_array($outcome['proof']??null)){
        $details['owned_hold']=$outcome['proof']['owned_hold'];
        $details['stationary']=$outcome['proof']['stationary'];
    }
    try {
        if(is_string($outcome['intent_created_at']??null)&&is_string($outcome['result_received_at']??null)){
            $start=(float)(new DateTimeImmutable($outcome['intent_created_at']))->format('U.u');
            $end=(float)(new DateTimeImmutable($outcome['result_received_at']))->format('U.u');
            if($end>=$start)$details['elapsed_ms']=round(($end-$start)*1000,2);
        }
        synth_jev_action_trace('result',$outcome['origin_event'],$details);
    } catch(Throwable) { /* Diagnostics must not change a committed native outcome. */ }
}

function synth_wait_result_message(string $name,string $actor,string $status,bool $verified,?array $proof=null): string
{
    if($verified)return $name==='wait_here'
        ? "$actor was verified to enter a temporary SYNTH wait hold in its owned package and remain stationary, for at most 90 seconds. This is historical evidence; Stop, expiry, cleanup or loading a save may release that hold. Current waiting requires a fresh observation."
        : "$actor was verified to have no SYNTH-owned Wait package after ReleaseWait. This is historical evidence; it does not recruit a follower or prove current movement.".(($proof['restrained']??false)?' Another restraint was present and was not cleared by SYNTH.':' No native restraint was observed at that receipt.');
    return "$actor: the client reported ".($name==='wait_here'?'WaitHere':'ReleaseWait')." status $status. No successful physical waiting or release was verified; this report does not establish a current wait hold.";
}

// Admission already holds the request transaction. Publish once, independently of optional model follow-up.
function synth_wait_record_consequence($db,array $event,array $outcome): void
{
    $result=$event['payload']['result'];$now=time();$meta=['schema'=>'synth.wait.consequence.v1','actor_identity_id'=>$outcome['actor_identity_id'],
        'action'=>$outcome['action'],'verified'=>$outcome['verified'],'result'=>$result,'message'=>$outcome['message'],
        'origin_sha256'=>$outcome['origin_sha256']];
    if($outcome['proof']!==null)$meta['proof']=$outcome['proof'];
    $query="WITH publication AS (INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
            synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
        VALUES('infoaction',$1,$2,$3,$4,$4,'',$5,'','emitted',$6,$7,$8,$9) RETURNING *)
        INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
        SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$2,rowid,to_jsonb(publication)||jsonb_build_object('synth_wait_consequence',$10::jsonb)
        FROM publication";
    if($db->execQuery($query,[$outcome['message'],$outcome['actor']['playthrough_id'],intval($outcome['context']['world']['game_time_ticks']??0),$now,
        strval($outcome['context']['world']['location']??''),$event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)])===false)
        throw new RuntimeException('Unable to persist native wait consequence');
}

// Only the validated terminal source can grant this special actor-scoped history row; labels grant nothing.
function synth_wait_history_visibility_sql(): string
{
    return "EXISTS(SELECT 1 FROM synth_meta.action_intents wi
        JOIN synth_meta.action_terminal_results wt ON wt.action_id=wi.action_id AND wt.idempotency_key=wi.idempotency_key
        JOIN synth_meta.protocol_events wr ON wr.session_id=h.session_id AND wr.request_id=h.request_id AND wr.generation=h.generation AND wr.turn_id=wi.turn_id
        JOIN synth_meta.request_executions we ON we.session_id=wr.session_id AND we.request_id=wr.request_id AND we.request_sha256=wr.event_sha256
        JOIN synth_meta.protocol_events wo ON wo.session_id=wi.session_id AND wo.request_id=wi.request_id AND wo.generation=wi.generation AND wo.turn_id=wi.turn_id
        JOIN synth_meta.request_executions weo ON weo.session_id=wo.session_id AND weo.request_id=wo.request_id AND weo.request_sha256=wo.event_sha256
        JOIN synth_meta.context_snapshots wc ON wc.session_id=h.session_id AND wc.generation=h.generation AND wc.snapshot_sequence=h.context_sequence AND wc.target_identity_id=wi.actor_identity_id
        JOIN synth.actor_identities wa ON wa.id=wi.actor_identity_id AND wa.playthrough_id=h.playthrough_id
        WHERE h.event_payload->>'type'='infoaction' AND wi.state='complete' AND wi.session_id=h.session_id AND wi.generation=h.generation
            AND wi.actor_identity_id::text=h.event_payload#>>'{synth_wait_consequence,actor_identity_id}'
            AND (wi.actor_identity_id=s.reader_identity_id OR s.reader_identity_id=s.reader_player_id)
            AND h.event_payload#>>'{synth_wait_consequence,schema}'='synth.wait.consequence.v1'
            AND h.event_payload#>'{synth_wait_consequence,result}'=wt.result AND wt.result=wr.canonical_event#>'{payload,result}'
            AND h.event_payload->>'data'=h.event_payload#>>'{synth_wait_consequence,message}'
            AND wr.event_type='action_result' AND wr.canonical_event->>'protocol_version'='2' AND wr.canonical_event->>'runtime_variant'='flat'
            AND wr.canonical_event->>'context_sequence'=wo.canonical_event->>'context_sequence'
            AND wo.event_type IN ('input_text','input_audio') AND wo.canonical_event->>'protocol_version'='2' AND wo.canonical_event->>'runtime_variant'='flat'
            AND h.event_payload#>>'{synth_wait_consequence,origin_sha256}'=wo.event_sha256
            AND ((wi.action_name IN ('WaitHere','wait_here') AND wi.capability='action.wait_here' AND h.event_payload#>>'{synth_wait_consequence,action}'='wait_here')
                OR (wi.action_name IN ('ReleaseWait','release_wait') AND wi.capability='action.release_wait' AND h.event_payload#>>'{synth_wait_consequence,action}'='release_wait'))
            AND ((h.event_payload#>'{synth_wait_consequence,verified}')='false'::jsonb OR
                ((h.event_payload#>'{synth_wait_consequence,verified}')='true'::jsonb AND wt.status='succeeded'
                    AND wr.canonical_event->'capabilities' @> '[\"action.wait_verified\"]'::jsonb AND wo.canonical_event->'capabilities' @> '[\"action.wait_verified\"]'::jsonb
                    AND CASE WHEN octet_length(wt.result->>'detail')<=1024 AND (wt.result->>'detail') IS JSON OBJECT WITH UNIQUE KEYS THEN (wt.result->>'detail')::jsonb END=h.event_payload#>'{synth_wait_consequence,proof}'
                    AND h.event_payload#>>'{synth_wait_consequence,proof,schema}'='synth.action.wait.result.v1'
                    AND h.event_payload#>>'{synth_wait_consequence,proof,action_id}'=wi.action_id
                    AND h.event_payload#>>'{synth_wait_consequence,proof,action}'=h.event_payload#>>'{synth_wait_consequence,action}'
                    AND upper(h.event_payload#>>'{synth_wait_consequence,proof,actor,form_id}')=upper(wa.full_form_id)
                    AND lower(h.event_payload#>>'{synth_wait_consequence,proof,actor,origin_plugin}')=lower(wa.origin_plugin)
                    AND h.event_payload#>>'{synth_wait_consequence,proof,actor,playthrough_id}'=wa.playthrough_id
                    AND h.event_payload#>'{synth_wait_consequence,proof,owned_hold}'=to_jsonb((h.event_payload#>>'{synth_wait_consequence,action}')='wait_here')
                    AND jsonb_typeof(h.event_payload#>'{synth_wait_consequence,proof,restrained}')='boolean'
                    AND jsonb_typeof(h.event_payload#>'{synth_wait_consequence,proof,stationary}')='boolean'
                    AND ((h.event_payload#>>'{synth_wait_consequence,action}'='wait_here'
                        AND (h.event_payload#>>'{synth_wait_consequence,proof,owned_package}') ~* '^0x[0-9a-f]{2}0009[0-9a-f]{2}$'
                        AND upper(substr(h.event_payload#>>'{synth_wait_consequence,proof,owned_package}',3,2))<>'FF'
                        AND h.event_payload#>'{synth_wait_consequence,proof,stationary}'='true'::jsonb)
                        OR (h.event_payload#>>'{synth_wait_consequence,action}'='release_wait'
                            AND lower(h.event_payload#>>'{synth_wait_consequence,proof,owned_package}')='0x00000000'))
                    AND h.event_payload#>'{synth_wait_consequence,proof,lease_ms}'='90000'::jsonb
                    AND h.event_payload#>>'{synth_wait_consequence,proof,reload_policy}'='release_owned_hold')))";
}


// Legacy display-name tool logs must not duplicate or supersede the owned consequence.
function synth_wait_unscoped_history_sql(): string
{
    return "(CASE WHEN original.type IN ('funcret','infoaction') THEN EXISTS(SELECT 1 FROM synth_meta.protocol_events raw_receipt
        JOIN synth_meta.action_intents raw_intent ON raw_intent.action_id=raw_receipt.canonical_event#>>'{payload,result,action_id}'
            AND raw_intent.session_id=raw_receipt.session_id AND raw_intent.generation=raw_receipt.generation
        WHERE raw_receipt.session_id=h.session_id AND raw_receipt.request_id=h.request_id AND raw_receipt.generation=h.generation
            AND raw_receipt.event_type='action_result' AND raw_receipt.canonical_event->>'protocol_version'='2'
            AND raw_intent.capability IN ('action.wait_here','action.release_wait')) ELSE false END)";
}


// Pure prompt observer. Call only after normal native admission/binding has authenticated the selected target.
function synth_wait_observer_message(array $event,array $states,array $target): array
{
    $key=synth_wait_actor_key($target);
    if(($event['protocol_version']??1)!==2||($event['runtime_variant']??'')!=='flat'||$key===null
        ||!in_array('context.wait_state',$event['capabilities']??[],true)
        ||!in_array($event['type']??'',['input_text','input_audio','action_result','activate','trigger'],true))return [];
    $name=$target['display_name']??'the selected NPC';
    $policy=' Historical success is not an enduring order: a hold lasts at most 90 seconds of unpaused gameplay, and Stop, load or cleanup may release it. Other restraint and follower status are separate.';
    if(($event['type']??'')==='action_result') {
        $latest=null;
        try {
            $raw=$event['payload']['result']['detail']??'';
            $proof=is_string($raw)&&strlen($raw)<=1024?json_decode($raw,true,8,JSON_THROW_ON_ERROR):null;
            if(is_array($proof)&&in_array($proof['action']??'',['wait_here','release_wait'],true)) {
                $verified=synth_wait_result_observation($event,$proof['action'],$target);
                if($verified!==null)$latest=synth_wait_result_message($proof['action'],$name,'succeeded',true,$verified);
            }
        } catch(JsonException|DomainException) {}
        $fact=$latest??'This result does not establish a verified Wait or Release postcondition; current owned hold is unknown.';
        return ['role'=>'system','content'=>'[Native Wait observer for '.$name.'] The issuing snapshot predates this action result; ignore its wait_held for post-action state. Latest receipt: '.$fact.$policy];
    }
    $matches=array_values(array_filter($states,static fn($state)=>is_array($state)&&synth_wait_actor_key($state['actor']??null)===$key));
    $held=count($matches)===1?($matches[0]['wait_held']??null):null;
    $fact=match($held) {
        true=>'The fresh selected-actor capture confirms a SYNTH-owned Wait hold.',
        false=>'The fresh selected-actor capture confirms no SYNTH-owned Wait hold. This does not prove freedom from another restraint or that movement is possible.',
        default=>'Current SYNTH wait ownership is unavailable. Missing or ambiguous evidence is unknown, not false.',
    };
    return ['role'=>'system','content'=>'[Native Wait observer for '.$name.'] '.$fact.$policy];
}
