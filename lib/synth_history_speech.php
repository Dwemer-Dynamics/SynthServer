<?php

require_once __DIR__.'/synth_rechat_routes.php';

// Enrich only already-visible frozen rows; this evidence never grants hearing or expands history scope.
function synth_history_speech_routes($db, array $event, string $actor, array $rows): array
{
    $ids=[];
    foreach ($rows as $row) {
        if (($row['type'] ?? '')==='chat' && intval($row['history_event_id'] ?? 0)>0
            && is_string($row['utterance_id'] ?? null) && $row['utterance_id']!=='') $ids[intval($row['history_event_id'])]=true;
    }
    if ($ids===[]) return [];
    // Work from the newest prompt rows; bound both candidate count and completed-response decoding.
    $ids=array_slice(array_keys($ids),0,256);
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event,$actor,$ids) {
        if ($owner!==$event) throw new LogicException('history_speech_owner_changed');
        $deadline=hrtime(true)+250000000;
        $scope=synth_protocol_history_cte($db,$event,$actor);
        $result=@pg_query($connection,$scope." SELECT h.id,h.session_id,h.request_id,h.generation,h.context_sequence,
                h.playthrough_id,h.event_payload,p.canonical_event,p.event_sha256,p.event_type,e.turn_id,
                c.context_payload,c.player_identity_id,c.target_identity_id,c.audience_identity_ids,
                octet_length(e.response_body) AS response_bytes
            FROM native_history visible JOIN synth_meta.history_events h ON h.id=visible.history_event_id
            JOIN synth_meta.context_snapshots c ON c.session_id=h.session_id AND c.generation=h.generation
                AND c.snapshot_sequence=h.context_sequence AND c.playthrough_id=h.playthrough_id
            JOIN synth_meta.request_executions e ON e.session_id=h.session_id AND e.request_id=h.request_id
                AND e.generation=h.generation AND e.state='complete'
            JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                AND p.event_sha256=e.request_sha256
            WHERE h.id IN (".implode(',',$ids).") AND h.event_payload->>'type'='chat'
                AND octet_length(e.response_body) BETWEEN 1 AND 2097152 ORDER BY h.id DESC LIMIT 256");
        if (!$result) throw new RuntimeException('history_speech_source_query_failed');
        $proofs=[]; $responses=[]; $bytes=0;
        foreach (pg_fetch_all($result) as $source) {
            if (hrtime(true)>$deadline) break; // Optional enrichment retains conservative formatting when its work budget expires.
            $request=json_decode($source['canonical_event'],true,flags:JSON_THROW_ON_ERROR);
            if (!synth_protocol_owns_dialogue_turn($request)
                || ($request['protocol_version'] ?? null)!==2 || ($request['session_id'] ?? '')!==$source['session_id']
                || ($request['request_id'] ?? '')!==$source['request_id'] || ($request['turn_id'] ?? '')!==$source['turn_id']
                || ($request['generation'] ?? null)!==intval($source['generation'])
                || ($request['context_sequence'] ?? null)!==intval($source['context_sequence'])
                || ($request['type'] ?? '')!==$source['event_type']
                || !hash_equals($source['event_sha256'],hash('sha256',synth_protocol_canonical_json($request)))) continue;
            $key=$source['session_id'].'|'.$source['request_id'];
            if (!array_key_exists($key,$responses)) {
                if (count($responses)>=32 || $bytes+intval($source['response_bytes'])>8*1024*1024) continue;
                $bytes+=intval($source['response_bytes']); $responses[$key]=[];
                $bodyResult=@pg_query_params($connection,"SELECT response_body FROM synth_meta.request_executions
                    WHERE session_id=$1 AND request_id=$2 AND state='complete' AND octet_length(response_body)<=2097152",
                    [$source['session_id'],$source['request_id']]);
                if (!$bodyResult || !($body=pg_fetch_assoc($bodyResult))) continue;
                $lines=synth_rechat_completed_lines(synth_protocol_decode_bytea($body['response_body']),$request);
                if ($lines===null) continue;
                $selection=null;
                if (synth_protocol_owns_rechat($request)) {
                    $selected=@pg_query_params($connection,"SELECT selected.role,selected.identity
                        FROM synth_meta.rechat_selections selected
                        JOIN synth_meta.rechat_continuations continuation USING(session_id,request_id)
                        JOIN synth_meta.rechat_chains chain ON chain.id=continuation.chain_id AND chain.session_id=continuation.session_id
                        WHERE selected.session_id=$1 AND selected.request_id=$2 AND selected.prepared_at IS NOT NULL
                            AND selected.role IN ('npc','narrator') AND continuation.status='reserved'
                            AND chain.generation=$3 AND chain.context_sequence=$4 AND chain.turn_id=$5 AND chain.runtime_variant=$6",
                        [$request['session_id'],$request['request_id'],$request['generation'],$request['context_sequence'],$request['turn_id'],$request['runtime_variant']]);
                    if (!$selected || !($selection=pg_fetch_assoc($selected))) continue;
                    $selection['identity']=$selection['identity']===null ? null : json_decode($selection['identity'],true,flags:JSON_THROW_ON_ERROR);
                }
                $routeResult=@pg_query_params($connection,'SELECT * FROM synth_meta.response_routes
                    WHERE session_id=$1 AND request_id=$2 AND generation=$3 AND context_sequence=$4 AND utterance_id IS NOT NULL',
                    [$source['session_id'],$source['request_id'],$source['generation'],$source['context_sequence']]);
                if (!$routeResult) throw new RuntimeException('history_speech_route_query_failed');
                $routes=[];
                foreach (pg_fetch_all($routeResult) as $route) $routes[$route['line_id']]=$route;
                foreach ($lines as $entry) {
                    $line=$entry['line']; $route=$routes[$line['line_id']] ?? null;
                    if ($line['type']!=='dialogue' || !$route || !hash_equals($route['line_sha256'],hash('sha256',$entry['wire']))) continue;
                    $speaker=$line['payload']['speaker'] ?? null; $text=$line['payload']['text'] ?? null;
                    if (!is_array($speaker) || !is_string($text) || trim($text)==='' || !mb_check_encoding($text,'UTF-8')
                        || mb_strlen($text,'UTF-8')>4096 || str_contains($text,"\0")
                        || synth_protocol_canonical_json($speaker)!==synth_protocol_canonical_json(json_decode($route['speaker'],true,flags:JSON_THROW_ON_ERROR))) continue;
                    if ($selection!==null && ($selection['role']==='npc'
                        ? synth_protocol_canonical_json($speaker)!==synth_protocol_canonical_json($selection['identity'])
                        : (($speaker['kind'] ?? '')!=='narrator' || ($speaker['id'] ?? '')!=='narrator'))) continue;
                    try { if (synth_rechat_route_utterance($line,['utterance_id'=>$route['utterance_id']])!==$route['utterance_id']) continue; }
                    catch (LogicException $error) { continue; }
                    $responses[$key][$route['utterance_id']]=['speaker'=>$speaker,'text'=>$text,'route'=>$route];
                }
            }
            $history=json_decode($source['event_payload'],true,flags:JSON_THROW_ON_ERROR);
            $speech=$responses[$key][$history['utterance_id'] ?? ''] ?? null;
            if (!$speech) continue;
            $context=json_decode($source['context_payload'],true,flags:JSON_THROW_ON_ERROR);
            $speaker=$speech['speaker']; $speakerId=null; $role=null;
            $candidates=[[$context['player'] ?? null,$source['player_identity_id']],[$context['target'] ?? null,$source['target_identity_id']]];
            $audienceIds=json_decode($source['audience_identity_ids'],true,flags:JSON_THROW_ON_ERROR);
            foreach ($context['audience'] ?? [] as $index=>$candidate) $candidates[]=[$candidate,$audienceIds[$index] ?? null];
            foreach ($candidates as [$candidate,$id]) {
                if ($id!==null && is_array($candidate) && ($candidate['playthrough_id'] ?? '')===$source['playthrough_id']
                    && synth_protocol_canonical_json($speaker)===synth_protocol_canonical_json($candidate)) {
                    $speakerId=intval($id); $role=$speakerId===intval($source['player_identity_id']) ? 'player' : 'npc';
                }
            }
            if (($speaker['kind'] ?? '')==='narrator' && ($speaker['id'] ?? '')==='narrator'
                && !array_diff(array_keys($speaker),['kind','id','display_name'])) $role='narrator';
            if ($role===null) continue;
            // Utterance identity alone cannot lend authority to unrelated history text.
            $label=$role==='narrator' ? 'The Narrator' : ($speaker['display_name'] ?? '');
            $expected=trim($label.': '.$speech['text']); $data=trim($history['data'] ?? '');
            $suffix='';
            if ($data!==$expected) {
                if (!str_starts_with($data,$expected.' ')) continue;
                $suffix=trim(substr($data,strlen($expected)));
                if (!preg_match('/^\((?:Talking|Whispering|Shouting|Speaking privately|Speaking loudly) to [^\r\n()]{1,300}\)$/uiD',$suffix)) continue;
            }
            $listener=$speech['route']['listener']===null ? null : json_decode($speech['route']['listener'],true,flags:JSON_THROW_ON_ERROR);
            $listenerId=null;
            if ($speech['route']['listener_state']==='addressed' && is_array($listener)) {
                foreach ($candidates as [$candidate,$id]) {
                    if ($id!==null && is_array($candidate) && ($candidate['playthrough_id'] ?? '')===$source['playthrough_id']
                        && synth_protocol_canonical_json($listener)===synth_protocol_canonical_json($candidate)) $listenerId=intval($id);
                }
            }
            $namedNarratorListener=$listenerId!==null && $listenerId!==intval($source['player_identity_id'])
                && strcasecmp(trim($listener['display_name'] ?? ''),'The Narrator')===0
                && preg_match('/^\((?:Talking|Whispering|Shouting|Speaking privately|Speaking loudly) to The Narrator(?: from far away)?\)$/iD',$suffix)===1;
            $proofs[intval($source['id'])]=['role'=>$role,'speaker_identity_id'=>$speakerId,
                'speaker'=>$speaker,'listener_state'=>$speech['route']['listener_state'],
                'listener'=>$listenerId===null ? null : $listener,'listener_identity_id'=>$listenerId,
                'narrator_named_npc_listener'=>$namedNarratorListener];
        }
        return $proofs;
    },2000);
}
