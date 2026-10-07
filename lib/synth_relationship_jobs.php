<?php

require_once __DIR__.'/synth_relationship.php';

// Explicit canonical addressing grants player-evaluation evidence; proximity and names do not.
function synth_relationship_player_lines(array $context, array $response): array
{
    if (($response['schema'] ?? null)!=='synth.relationship.response.v3'
        || ($response['evaluation_mode'] ?? null)!=='dedicated' || !is_array($response['entries'] ?? null)
        || !array_is_list($response['entries'])) return [];
    $lines=[];
    foreach ($response['entries'] as $index=>$entry) {
        if ($index>=256 || !is_array($entry) || ($entry['routing']['availability'] ?? null)!=='addressed') continue;
        foreach (['speaker'=>'target','listener'=>'player'] as $role=>$key) {
            $actor=$role==='speaker' ? ($entry['speaker'] ?? null) : ($entry['routing']['listener'] ?? null);
            $expected=$context[$key] ?? null;
            if (!is_array($actor) || !is_array($expected)) continue 2;
            foreach (['form_id','origin_plugin','playthrough_id'] as $field) {
                if (!is_string($actor[$field] ?? null) || !is_string($expected[$field] ?? null)
                    || ($field==='playthrough_id' ? $actor[$field]!==$expected[$field] : strcasecmp($actor[$field],$expected[$field])!==0)) continue 3;
            }
        }
        $lines[]=['index'=>$index,'entry'=>$entry];
    }
    return $lines;
}

// Freeze the selected prompt and all native evaluator facts before any provider work; no legacy profile lookup.
function synth_relationship_capture_evaluation($db, int $responseId, string $systemPrompt, bool $playerOnly=false): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    if ($responseId<1 || trim($systemPrompt)==='' || strlen($systemPrompt)>16384 || !mb_check_encoding($systemPrompt,'UTF-8')) {
        throw new InvalidArgumentException('Invalid native relationship evaluation input');
    }
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$responseId,$systemPrompt,$playerOnly) {
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true)
            .'SELECT * FROM relationship_response_evidence WHERE history_event_id=$1',[$responseId]);
        if (!$result) throw new RuntimeException('Unable to resolve relationship evaluation response');
        $response=pg_fetch_assoc($result); if (!$response) return null;
        $context=synth_protocol_current_context($db,$event);
        if (!$context) return null;
        $responsePayload=json_decode($response['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        $playerLines=$playerOnly ? synth_relationship_player_lines($context['payload'],$responsePayload) : [];
        if ($playerOnly && !$playerLines) return null;
        if (!synth_relationship_write_allowed($connection,$event,intval($response['actor_identity_id']),$responseId)) return null;
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event)
            .'SELECT 1 FROM native_relationship_results WHERE response_history_event_id=$1',[$responseId]);
        if (!$result) throw new RuntimeException('Unable to check completed relationship evaluation');
        if (pg_fetch_assoc($result)) return null;
        $result=@pg_query_params($connection,'SELECT id,input_payload FROM synth_meta.relationship_evaluations
            WHERE session_id=$1 AND request_id=$2 AND response_history_event_id=$3',[$event['session_id'],$event['request_id'],$responseId]);
        if (!$result) throw new RuntimeException('Unable to check relationship evaluation replay');
        if ($existing=pg_fetch_assoc($result)) {
            $schema=json_decode($existing['input_payload'],true)['schema'] ?? null;
            return $schema===($playerOnly ? 'synth.relationship.evaluation.v2' : 'synth.relationship.evaluation.v1') ? intval($existing['id']) : null;
        }
        $canonical=synth_protocol_canonical_json($event);
        $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e
            ON e.session_id=p.session_id AND e.request_id=p.request_id AND e.request_sha256=p.event_sha256 AND e.state=\'complete\'
            WHERE p.session_id=$1 AND p.request_id=$2 AND p.canonical_event=$3::jsonb AND p.event_sha256=$4',
            [$event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical)]);
        if (!$result) throw new RuntimeException('Unable to verify relationship evaluation owner');
        if (!pg_fetch_assoc($result)) return null;
        $subjects=[];
        foreach (array_merge([['display_name'=>'Player']],$playerOnly ? [] : ($context['payload']['audience'] ?? [])) as $actor) {
            $label=$actor['display_name']; $pair=synth_relationship_pair($db,$event,$label);
            if (!$pair || isset($subjects[$pair['subject_id']])) continue;
            $subjects[$pair['subject_id']]=['subject_id'=>$pair['subject_id'],'label'=>$label,
                'relationship'=>synth_relationship_read($db,$label)['relationship']];
        }
        ksort($subjects,SORT_NUMERIC);
        if ($playerOnly && count($subjects)!==1) return null;
        $recent=[];$sources=[$responseId];
        if (!$playerOnly) {
            $result=@pg_query($connection,synth_relationship_visible_cte($db,$event)
                ."SELECT h.history_event_id,h.type,h.data FROM native_history h JOIN relationship_visible_sources s USING(history_event_id)
                    ORDER BY h.gamets DESC NULLS LAST,h.ts DESC NULLS LAST,h.history_event_id DESC LIMIT 10");
            if (!$result) throw new RuntimeException('Unable to freeze relationship context events');
            $recent=array_reverse(pg_fetch_all($result));
        }
        foreach ($recent as &$row) { $row['history_event_id']=intval($row['history_event_id']); $sources[]=$row['history_event_id']; }
        unset($row); $sources=array_values(array_unique($sources)); sort($sources,SORT_NUMERIC);
        $input=['schema'=>$playerOnly ? 'synth.relationship.evaluation.v2' : 'synth.relationship.evaluation.v1','evaluation_policy'=>'synth.relationship.policy.v2',
            'owner'=>$event,'connector_id'=>intval($GLOBALS['RELLLM_CONNECTOR']),
            'system_prompt'=>$systemPrompt,'context'=>$context['payload'],'subjects'=>array_values($subjects),
            'response_id'=>$responseId,'response'=>$responsePayload,
            'recent_events'=>$recent,'source_ids'=>$sources];
        if ($playerOnly) $input['player_lines']=$playerLines;
        $json=json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($json)>262144) throw new LengthException('Native relationship evaluation input too large');
        $result=@pg_query_params($connection,'INSERT INTO synth_meta.relationship_evaluations
            (actor_identity_id,response_history_event_id,session_id,request_id,generation,context_sequence,input_payload,input_sha256)
            VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,encode(sha256(convert_to(($7::jsonb)::text,\'UTF8\')),\'hex\')) RETURNING id',
            [$response['actor_identity_id'],$responseId,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence'],$json]);
        if (!$result) throw new RuntimeException('Unable to freeze relationship evaluation');
        $id=intval(pg_fetch_assoc($result)['id']);
        foreach ($sources as $source) if (@pg_query_params($connection,'INSERT INTO synth_meta.relationship_evaluation_sources VALUES($1,$2)',[$id,$source])===false) {
            throw new RuntimeException('Unable to freeze relationship evaluation dependency');
        }
        return $id;
    },2000);
}

// Revalidate the immutable payload, every source and all saved prior states at worker boundaries.
function synth_relationship_evaluation_input($connection, $db, array $event, int $evaluationId): ?array
{
    $result=@pg_query_params($connection,'SELECT i.* FROM synth_meta.relationship_evaluations i
        JOIN synth_meta.request_executions e ON e.session_id=i.session_id AND e.request_id=i.request_id AND e.generation=i.generation AND e.state=\'complete\'
        JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.event_sha256=e.request_sha256
            AND p.canonical_event=i.input_payload->\'owner\'
        WHERE i.id=$1 AND i.session_id=$2 AND i.request_id=$3 AND i.generation=$4 AND i.context_sequence=$5
            AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,\'UTF8\')),\'hex\')',
        [$evaluationId,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence']]);
    if (!$result) throw new RuntimeException('Unable to verify relationship evaluation');
    $row=pg_fetch_assoc($result); if (!$row) return null;
    if (!synth_relationship_write_allowed($connection,$event,intval($row['actor_identity_id']),intval($row['response_history_event_id']))) return null;
    $input=json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR);
    if (synth_protocol_canonical_json($input['owner'])!==synth_protocol_canonical_json($event)) return null;
    $context=synth_protocol_current_context($db,$event);
    if (!$context || $context['payload']!=$input['context']) return null;
    $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true)
        .'SELECT response_payload FROM relationship_response_evidence WHERE history_event_id=$1 AND actor_identity_id=$2',
        [$row['response_history_event_id'],$row['actor_identity_id']]);
    if (!$result) throw new RuntimeException('Unable to recheck relationship evaluation response');
    $response=pg_fetch_assoc($result);
    if (!$response || json_decode($response['response_payload'],true)!=$input['response']) return null;
    if (($input['schema'] ?? null)==='synth.relationship.evaluation.v2') {
        $pair=synth_relationship_pair($db,$event,'Player');
        $lines=synth_relationship_player_lines($context['payload'],$input['response']);
        if (!$pair || !$lines || !is_array($input['player_lines'] ?? null)
            || synth_protocol_canonical_json($input['player_lines'])!==synth_protocol_canonical_json($lines) || ($input['recent_events'] ?? null)!==[]
            || ($input['source_ids'] ?? null)!==[intval($row['response_history_event_id'])]
            || !is_array($input['subjects'] ?? null) || count($input['subjects'])!==1 || ($input['subjects'][0]['subject_id'] ?? null)!==$pair['subject_id']
            || ($input['subjects'][0]['label'] ?? null)!=='Player' || ($input['evaluation_policy'] ?? null)!=='synth.relationship.policy.v2') return null;
    } elseif (($input['schema'] ?? null)!=='synth.relationship.evaluation.v1' || isset($input['player_lines'])) return null;
    $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true)
        .'SELECT d.history_event_id FROM synth_meta.relationship_evaluation_sources d
            JOIN relationship_visible_sources s USING(history_event_id) WHERE d.evaluation_id=$1 ORDER BY d.history_event_id',[$evaluationId]);
    if (!$result) throw new RuntimeException('Unable to recheck relationship evaluation sources');
    if (array_map('intval',array_column(pg_fetch_all($result),'history_event_id'))!==$input['source_ids']) return null;
    $result=@pg_query_params($connection,'SELECT count(*) AS n FROM synth_meta.relationship_evaluation_sources WHERE evaluation_id=$1',[$evaluationId]);
    if (!$result) throw new RuntimeException('Unable to check relationship dependency count');
    if (intval(pg_fetch_assoc($result)['n'])!==count($input['source_ids'])) return null;
    foreach ($input['recent_events'] as $recent) {
        $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.history_events WHERE id=$1 AND event_payload->>\'type\'=$2 AND event_payload->>\'data\'=$3',
            [$recent['history_event_id'],$recent['type'],$recent['data']]);
        if (!$result) throw new RuntimeException('Unable to verify frozen relationship event');
        if (!pg_fetch_assoc($result)) return null;
    }
    foreach ($input['subjects'] as $subject) {
        $pair=synth_relationship_pair($db,$event,$subject['label']);
        if (!$pair || $pair['subject_id']!==$subject['subject_id']
            || synth_relationship_read($db,$subject['label'])['relationship']!=$subject['relationship']) return null;
    }
    return $row;
}

// Callers hold participant control locks first. Both queue types share two active slots per actor.
function synth_relationship_queue_has_capacity($connection, array $actorIds): bool
{
    sort($actorIds,SORT_NUMERIC);
    foreach (array_unique($actorIds) as $actorId) {
        if (!is_int($actorId) || $actorId<1) throw new InvalidArgumentException('Invalid relationship queue actor');
        if (@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-relationship-jobs:'||$1,0))",[$actorId])===false) {
            throw new RuntimeException('Unable to lock relationship queue capacity');
        }
    }
    $capacity=true;
    foreach (array_unique($actorIds) as $actorId) {
        $scope="job_type='relationship' AND (relationship_evaluation_id IS NOT NULL OR relationship_interaction_id IS NOT NULL)
            AND state IN ('pending','running') AND (actor_identity_id=$1 OR relationship_interaction_id IN (
                SELECT id FROM synth_meta.relationship_interaction_evaluations WHERE listener_identity_id=$1))";
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
            last_error='lease_expired',updated_at=clock_timestamp() WHERE $scope
            AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",[$actorId]);
        if (!$result) throw new RuntimeException('Unable to retire relationship queue work');
        $result=@pg_query_params($connection,"SELECT count(*) AS n FROM synth_meta.worker_jobs WHERE $scope",[$actorId]);
        if (!$result) throw new RuntimeException('Unable to read relationship queue capacity');
        if (intval(pg_fetch_assoc($result)['n'])>=2) $capacity=false;
    }
    return $capacity;
}

// Durable, bounded dedicated work; this does not start a provider or publish relationship state.
function synth_relationship_enqueue_evaluation($db, int $evaluationId): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$evaluationId) {
        $input=synth_relationship_evaluation_input($connection,$db,$event,$evaluationId);
        if (!$input) {
            // Persist terminal scheduling evidence so an invalid frozen input cannot monopolize recovery.
            $result=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs
                (job_type,relationship_evaluation_id,session_id,request_id,turn_id,generation,actor_identity_id,payload,state,last_error,deadline_at)
                SELECT 'relationship',i.id,i.session_id,i.request_id,$4,i.generation,i.actor_identity_id,
                    jsonb_build_object('context_sequence',i.context_sequence),'stale','input_unavailable',clock_timestamp()
                FROM synth_meta.relationship_evaluations i
                WHERE i.id=$1 AND i.session_id=$2 AND i.request_id=$3 AND i.generation=$5 AND i.context_sequence=$6
                    AND i.input_payload->'owner'=$7::jsonb
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.worker_jobs j WHERE j.relationship_evaluation_id=i.id)",
                [$evaluationId,$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],
                    $event['context_sequence'],synth_protocol_canonical_json($event)]);
            if (!$result) throw new RuntimeException('Unable to retire invalid relationship evaluation');
            return null;
        }
        $capacity=synth_relationship_queue_has_capacity($connection,[intval($input['actor_identity_id'])]);
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs
            (job_type,relationship_evaluation_id,session_id,request_id,turn_id,generation,actor_identity_id,payload,deadline_at)
            SELECT 'relationship',$1,$2,$3,$4,$5,$6,jsonb_build_object('context_sequence',$7::bigint),clock_timestamp()+INTERVAL '10 minutes'
            WHERE NOT EXISTS(SELECT 1 FROM synth_meta.worker_jobs WHERE relationship_evaluation_id=$1 AND state IN ('ready','failed','cancelled','stale'))
                AND $8::boolean
            ON CONFLICT DO NOTHING RETURNING id",[$evaluationId,$event['session_id'],$event['request_id'],$event['turn_id'],
                $event['generation'],$input['actor_identity_id'],$event['context_sequence'],$capacity ? 't' : 'f']);
        if (!$result) throw new RuntimeException('Unable to enqueue relationship evaluation');
        $created=pg_fetch_assoc($result); if ($created) return intval($created['id']);
        $result=@pg_query_params($connection,"SELECT id FROM synth_meta.worker_jobs WHERE relationship_evaluation_id=$1
            AND state IN ('pending','running')",[$evaluationId]);
        if (!$result) throw new RuntimeException('Unable to read relationship evaluation replay');
        $existing=pg_fetch_assoc($result); return $existing ? intval($existing['id']) : null;
    },2000);
}

// A fresh fencing token replaces an expired attempt; all frozen inputs are checked before granting the lease.
function synth_relationship_claim_evaluation($db, int $jobId): ?array
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId) {
        $result=@pg_query_params($connection,"SELECT relationship_evaluation_id FROM synth_meta.worker_jobs
            WHERE id=$1 AND job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                AND session_id=$2 AND request_id=$3 AND generation=$4 AND turn_id=$5 AND payload->>'context_sequence'=$6",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to resolve relationship attempt');
        $job=pg_fetch_assoc($result); if (!$job) return null;
        $input=synth_relationship_evaluation_input($connection,$db,$event,intval($job['relationship_evaluation_id']));
        if (!$input) {
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs j SET state='stale',lease_token=NULL,lease_until=NULL,
                last_error='input_unavailable',updated_at=clock_timestamp() FROM synth_meta.relationship_evaluations i
                WHERE j.id=$1 AND j.relationship_evaluation_id=i.id AND i.input_payload->'owner'=$2::jsonb
                    AND (j.state='pending' OR (j.state='running' AND j.lease_until<=clock_timestamp()))",
                [$jobId,synth_protocol_canonical_json($event)]);
            if (!$result) throw new RuntimeException('Unable to retire invalid relationship input');
            return null;
        }
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
            last_error='lease_expired',updated_at=clock_timestamp() WHERE id=$1 AND state IN ('pending','running')
                AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",[$jobId]);
        if (!$result) throw new RuntimeException('Unable to retire expired relationship attempt');
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='running',attempts=attempts+1,lease_token=$2,
            lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
            WHERE id=$1 AND attempts<3 AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp()
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) RETURNING id,lease_token,attempts",
            [$jobId,bin2hex(random_bytes(16))]);
        if (!$result) throw new RuntimeException('Unable to claim relationship attempt');
        $claimed=pg_fetch_assoc($result); return $claimed ? array_merge($claimed,['input_payload'=>$input['input_payload']]) : null;
    },2000);
}

// Fixed failure codes and retry limits prevent provider diagnostics or secrets entering durable state.
function synth_relationship_fail_evaluation($db, int $jobId, string $token): bool
{
    return $db->withNativeRequestLock(static function($connection,array $event) use($jobId,$token) {
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state=CASE WHEN attempts>=3 OR deadline_at<=clock_timestamp()
            THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
            last_error='provider_failed',updated_at=clock_timestamp() WHERE id=$1 AND job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                AND session_id=$2 AND request_id=$3 AND generation=$4 AND payload->>'context_sequence'=$5
                AND state='running' AND lease_token=$6 AND lease_until>clock_timestamp() RETURNING id",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],(string)$event['context_sequence'],$token]);
        if (!$result) throw new RuntimeException('Unable to record relationship evaluation failure');
        return pg_fetch_assoc($result)!==false;
    },2000);
}

// Model keys resolve only to frozen subjects. Invalid types cannot invent policy; malformed proposals fail closed.
function synth_relationship_parse_evaluation(string $text, array $input): array
{
    $modern=($input['evaluation_policy'] ?? null)==='synth.relationship.policy.v2';
    if (isset($input['evaluation_policy']) && !$modern) throw new InvalidArgumentException('Invalid relationship model policy');
    if (strlen($text)>65536 || !mb_check_encoding($text,'UTF-8')) throw new InvalidArgumentException('Invalid relationship model result');
    $text=trim($text);
    if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s',$text,$match)) $text=$match[1];
    try {$json=json_decode($text,false,64,JSON_THROW_ON_ERROR);} catch(JsonException $error) {
        throw new InvalidArgumentException('Invalid relationship model result');
    }
    if (!$json instanceof stdClass || array_keys(get_object_vars($json))!==['changes'] || !$json->changes instanceof stdClass
        || count(get_object_vars($json->changes))>32) throw new InvalidArgumentException('Invalid relationship model result');
    $aliases=[]; $subjects=[]; $custom=[];
    foreach ($input['subjects'] as $subject) {
        $id=$subject['subject_id']; $subjects[$id]=$subject;
        $aliases[strtolower(trim($subject['label']))][$id]=true;
        if (is_string($subject['relationship']['type'] ?? null)) $custom[]=$subject['relationship']['type'];
        if ($subject['label']==='Player') foreach (['player','the player','player character','the player character','sole survivor',
            'the sole survivor','#player_name#','{player_name}',strtolower($input['context']['player']['display_name'])] as $alias) $aliases[$alias][$id]=true;
    }
    $changes=[];
    foreach (get_object_vars($json->changes) as $name=>$proposal) {
        $ids=array_keys($aliases[strtolower(trim((string)$name))] ?? []);
        if (count($ids)!==1 || !$proposal instanceof stdClass) throw new InvalidArgumentException('Unknown relationship model subject');
        $values=get_object_vars($proposal);
        if (array_diff(array_keys($values),$modern ? ['delta','type','reason','relation'] : ['delta','type','reason']) || !is_int($values['delta'] ?? null)
            || abs($values['delta'])>200 || (isset($values['type']) && !is_string($values['type']))
            || (isset($values['reason']) && (!is_string($values['reason']) || strlen($values['reason'])>256))
            || (isset($values['relation']) && (!is_string($values['relation']) || strlen($values['relation'])>100))) {
            throw new InvalidArgumentException('Invalid relationship model proposal');
        }
        $id=$ids[0]; $subject=$subjects[$id]; $base=intval($subject['relationship']['aff'] ?? 0);
        if (!isset($changes[$id])) $changes[$id]=['subject_id'=>$id,'label'=>$subject['label'],'delta'=>0,'type'=>null,'reason'=>''];
        $changes[$id]['delta']=max(-100,min(100,$base+$changes[$id]['delta']+$values['delta']))-$base;
        $type=$modern ? synth_relationship_canonical_type($values['type'] ?? null,$custom)
            : RelationshipManager::canonicalizeRelationshipType($values['type'] ?? null,$custom);
        if ($type!==null) $changes[$id]['type']=$type;
        if (isset($values['reason'])) $changes[$id]['reason']=trim($values['reason']);
        if ($modern) {
            $prior=array_key_exists('state',$changes[$id]) ? $changes[$id]['state'] : $subject['relationship'];
            $state=synth_relationship_project_evaluation($prior,$values['delta'],$type,trim($values['reason'] ?? ''),trim($values['relation'] ?? ''));
            if ($state!==null) unset($state['revision']);
            $changes[$id]['state']=$state;
            $changes[$id]['delta']=($state['aff'] ?? $base)-$base;
        }
    }
    ksort($changes,SORT_NUMERIC);
    return ['schema'=>$modern ? 'synth.relationship.result.v2' : 'synth.relationship.result.v1','changes'=>array_values($changes)];
}

// Result, all prior dependencies, every changed pair and the ready transition commit as one fenced transaction.
function synth_relationship_complete_evaluation($db, int $jobId, string $token, string $modelText): int
{
    if (!synthRelationshipUsesDedicatedConnector()) throw new LogicException('Dedicated relationships are disabled');
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId,$token,$modelText) {
        $result=@pg_query_params($connection,"SELECT relationship_evaluation_id,state,lease_token,
            (lease_until>clock_timestamp() AND deadline_at>clock_timestamp()) AS live FROM synth_meta.worker_jobs
            WHERE id=$1 AND job_type='relationship' AND relationship_evaluation_id IS NOT NULL AND session_id=$2
                AND request_id=$3 AND generation=$4 AND turn_id=$5 AND payload->>'context_sequence'=$6",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to resolve relationship completion');
        $job=pg_fetch_assoc($result);
        if (!$job || !hash_equals($job['lease_token'] ?? '',$token) || !in_array($job['state'],['running','ready'],true)
            || ($job['state']==='running' && $job['live']!=='t')) throw new SYNTHProtocolCancelled('relationship_lease_lost');
        $evaluation=synth_relationship_evaluation_input($connection,$db,$event,intval($job['relationship_evaluation_id']));
        if (!$evaluation) throw new SYNTHProtocolCancelled('relationship_input_unavailable');
        $input=json_decode($evaluation['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $parsed=synth_relationship_parse_evaluation($modelText,$input);
        $json=json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if ($job['state']==='ready') {
            $result=@pg_query_params($connection,'SELECT history_event_id FROM synth_meta.relationship_results WHERE job_id=$1 AND result_payload=$2::jsonb',[$jobId,$json]);
            $existing=$result ? pg_fetch_assoc($result) : false;
            if (!$existing) throw new LogicException('Conflicting relationship evaluation replay');
            return intval($existing['history_event_id']);
        }
        require_once __DIR__.'/synth_relationship_interaction_result.php';
        if (synth_relationship_paired_response_consumed($connection,$db,$event,intval($evaluation['response_history_event_id']),
            array_column($input['subjects'],'subject_id'))) {
            throw new LogicException('Relationship response already evaluated by paired worker');
        }
        if (@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-relationship-result:'||$1||':'||$2,0))",
            [$evaluation['actor_identity_id'],$evaluation['response_history_event_id']])===false) throw new RuntimeException('Unable to lock relationship result');
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event)
            .'SELECT 1 FROM native_relationship_results WHERE response_history_event_id=$1',[$evaluation['response_history_event_id']]);
        if (!$result) throw new RuntimeException('Unable to check prior relationship result');
        if (pg_fetch_assoc($result)) throw new LogicException('Relationship response already evaluated');
        $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.relationship_results r JOIN synth_meta.relationship_evaluations i ON i.id=r.evaluation_id
            WHERE i.session_id=$1 AND i.generation=$2 AND i.response_history_event_id=$3 AND i.actor_identity_id=$4 AND r.history_event_id>(
                SELECT history_event_id FROM synth_meta.context_snapshots WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$5) LIMIT 1',
            [$event['session_id'],$event['generation'],$evaluation['response_history_event_id'],$evaluation['actor_identity_id'],$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to check concurrent relationship result');
        if (pg_fetch_assoc($result)) throw new LogicException('Native relationship evaluation is stale');
        $result=@pg_query_params($connection,"WITH publication AS (
            INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('relationship_result','Native relationship evaluation',$1,$2,$3,$3,'','','','derived',$4,$5,$6,$7) RETURNING *
        ), history AS (
            INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$1,rowid,to_jsonb(publication)
                ||jsonb_build_object('evaluation_sha256',$8::text,'result_sha256',encode(sha256(convert_to(($9::jsonb)::text,'UTF8')),'hex')) FROM publication RETURNING id
        ) INSERT INTO synth_meta.relationship_results(history_event_id,evaluation_id,job_id,result_payload)
            SELECT id,$10,$11,$9::jsonb FROM history RETURNING history_event_id",
            [$input['context']['playthrough_id'],intval($input['context']['world']['game_time_ticks'] ?? 0),time(),$event['session_id'],
                $event['generation'],$event['context_sequence'],$event['request_id'],$evaluation['input_sha256'],$json,$evaluation['id'],$jobId]);
        if (!$result) throw new RuntimeException('Unable to publish relationship result');
        $resultId=intval(pg_fetch_assoc($result)['history_event_id']); $subjects=[];
        foreach ($input['subjects'] as $subject) {
            $subjects[$subject['subject_id']]=$subject;
            if ($subject['relationship']!==null && @pg_query_params($connection,'INSERT INTO synth_meta.relationship_result_priors VALUES($1,$2)',
                [$resultId,$subject['relationship']['revision']])===false) throw new RuntimeException('Unable to freeze relationship result prior');
        }
        foreach ($parsed['changes'] as $change) {
            $subject=$subjects[$change['subject_id']];
            if (array_key_exists('state',$change)) {
                $base=$subject['relationship'];if ($base!==null) unset($base['revision']);
                $state=$change['state'];if ($state===null || $state==$base) continue;
                $details=array_diff_key($state,array_flip(['aff','type']));
                synth_relationship_publish($db,$subject['label'],$state['aff']-($base['aff'] ?? 0),$state['type'],
                    $subject['relationship']['revision'] ?? null,$input['source_ids'],$resultId,$details);
                continue;
            }
            $type=$change['type']===($subject['relationship']['type'] ?? 'neutral') ? null : $change['type'];
            if ($change['delta']===0 && $type===null) continue;
            synth_relationship_publish($db,$subject['label'],$change['delta'],$type,$subject['relationship']['revision'] ?? null,$input['source_ids'],$resultId);
        }
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='ready',lease_until=NULL,updated_at=clock_timestamp()
            WHERE id=$1 AND state='running' AND lease_token=$2 AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id",[$jobId,$token]);
        if (!$result || !pg_fetch_assoc($result)) throw new SYNTHProtocolCancelled('relationship_lease_lost');
        return $resultId;
    },2000);
}

// The actual provider runs outside SQL transactions; every outcome goes through the same fenced completion boundary.
function synth_relationship_process_evaluation($db, int $jobId, callable $generate): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Relationship providers require an idle database connection');
    $job=synth_relationship_claim_evaluation($db,$jobId); if (!$job) return null;
    $previous=$GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE'] ?? null;
    $GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE']=['id'=>$jobId,'token'=>$job['lease_token']];
    try {
        $input=json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $text=$generate($input);
        if (!is_string($text)) throw new InvalidArgumentException('Invalid relationship model result');
        return synth_relationship_complete_evaluation($db,$jobId,$job['lease_token'],$text);
    } catch (SYNTHProtocolCancelled $error) { throw $error;
    } catch (Throwable $error) {
        synth_relationship_fail_evaluation($db,$jobId,$job['lease_token']); throw $error;
    } finally {
        if ($previous===null) unset($GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE']);
        else $GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE']=$previous;
    }
}
