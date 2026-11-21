<?php

require_once dirname(__DIR__, 3).'/managers/Relationships/Native/synth_relationship.php';

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
        $gift=$playerOnly ? synth_player_gift_for_event($db,$event) : null;
        $view=$gift!==null ? 'relationship_response_candidates' : 'relationship_response_evidence';
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true,sourceHistoryIds:[])
            .'SELECT * FROM '.$view.' WHERE history_event_id=$1',[$responseId]);
        if (!$result) throw new RuntimeException('Unable to resolve relationship evaluation response');
        $response=pg_fetch_assoc($result); if (!$response) return null;
        $context=synth_protocol_current_context($db,$event);
        if (!$context) return null;
        $responsePayload=json_decode($response['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        $giftOnly=$gift!==null && isset($responsePayload['delivery_lines']);
        if ($giftOnly) {
            if (!is_array($responsePayload['delivery_lines']) || !array_is_list($responsePayload['delivery_lines'])
                || count($responsePayload['delivery_lines'])<1 || count($responsePayload['delivery_lines'])>256
                || ($responsePayload['verified_gift'] ?? null)!=$gift
                || ($responsePayload['verified_gift_action_id'] ?? null)!==$gift['action_id']) return null;
            $responsePayload=synth_relationship_gift_response($responsePayload);
        }
        $playerLines=$playerOnly ? synth_relationship_player_lines($context['payload'],$responsePayload) : [];
        if ($playerOnly && !$playerLines && !$giftOnly) return null;
        if (!synth_relationship_write_allowed($connection,$event,intval($response['actor_identity_id']),$responseId)) return null;
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,sourceHistoryIds:[])
            .'SELECT 1 FROM native_relationship_results WHERE response_history_event_id=$1',[$responseId]);
        if (!$result) throw new RuntimeException('Unable to check completed relationship evaluation');
        if (pg_fetch_assoc($result)) return null;
        if ($existing=\Synth\Core\DbManager\Dao\RelationshipEvaluationDao::current($db)->replayFor($event['session_id'],$event['request_id'],$responseId)) {
            $schema=json_decode($existing['input_payload'],true)['schema'] ?? null;
            return $schema===($playerOnly ? 'synth.relationship.evaluation.v2' : 'synth.relationship.evaluation.v1') ? intval($existing['id']) : null;
        }
        $canonical=synth_protocol_canonical_json($event);
        if (!\Synth\Core\DbManager\Dao\RequestExecutionDao::current($db)->completedOwner($event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical))) return null;
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
            $visibleCte=synth_relationship_visible_cte($db,$event);
            try {
                $recent=\Synth\Core\DbManager\Dao\HistoryEventDao::current($db)->recentVisibleEvents($visibleCte);
            } catch (Throwable $error) {
                throw new RuntimeException('Unable to freeze relationship context events',0,$error);
            }
        }
        foreach ($recent as &$row) { $row['history_event_id']=intval($row['history_event_id']); $sources[]=$row['history_event_id']; }
        unset($row); $sources=array_values(array_unique($sources)); sort($sources,SORT_NUMERIC);
        $input=['schema'=>$playerOnly ? 'synth.relationship.evaluation.v2' : 'synth.relationship.evaluation.v1','evaluation_policy'=>'synth.relationship.policy.v2',
            'owner'=>$event,'connector_id'=>intval(\Synth\Bootstrap::config('RELLLM_CONNECTOR')),
            'system_prompt'=>$systemPrompt,'context'=>$context['payload'],'subjects'=>array_values($subjects),
            'response_id'=>$responseId,'response'=>$responsePayload,
            'recent_events'=>$recent,'source_ids'=>$sources];
        if ($playerOnly) $input['player_lines']=$playerLines;
        if ($giftOnly) {
            $input['gift_only']=true;
            $input['response_candidate_sha256']=$response['candidate_sha256'];
        }
        $gift ??= synth_player_gift_for_event($db,$event);
        if ($gift!==null) {
            // One physical handoff may be delivered again under another receipt request ID.
            // Its dedicated evaluation is owned by the action, not by repeated thank-you speech.
            if (\Synth\Core\DbManager\Dao\RelationshipEvaluationDao::current($db)->giftEvaluated(intval($response['actor_identity_id']),strval($gift['action_id']))) return null;
            $perspective=synth_player_gift_perspective($db,$event,intval($response['actor_identity_id']));
            if ($perspective===null) return null;
            $input['received_gift']=['fact'=>$gift,'recipient_perspective'=>$perspective];
        }
        $json=json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($json)>262144) throw new LengthException('Native relationship evaluation input too large');
        $id=\Synth\Core\DbManager\Dao\RelationshipEvaluationDao::current($db)->freeze($response['actor_identity_id'],$responseId,$event['session_id'],$event['request_id'],
            $event['generation'],$event['context_sequence'],$json);
        foreach ($sources as $source) \Synth\Core\DbManager\Dao\RelationshipEvaluationSourceDao::current($db)->add($id,$source);
        return $id;
    },2000);
}

// Revalidate the immutable payload, every source and all saved prior states at worker boundaries.
function synth_relationship_evaluation_input($connection, $db, array $event, int $evaluationId): ?array
{
    $row=\Synth\Core\DbManager\Dao\RelationshipEvaluationDao::current($db)->verifiedInput($evaluationId,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence']);
    if (!$row) return null;
    if (!synth_relationship_write_allowed($connection,$event,intval($row['actor_identity_id']),intval($row['response_history_event_id']))) return null;
    $input=json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR);
    if (synth_protocol_canonical_json($input['owner'])!==synth_protocol_canonical_json($event)) return null;
    $context=synth_protocol_current_context($db,$event);
    if (!$context || $context['payload']!=$input['context']) return null;
    $giftOnly=($input['gift_only'] ?? null)===true;
    if (isset($input['gift_only']) && !$giftOnly) return null;
    if ($giftOnly && ($input['schema'] ?? null)!=='synth.relationship.evaluation.v2') return null;
    $view=$giftOnly ? 'relationship_response_candidates' : 'relationship_response_evidence';
    $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true,sourceHistoryIds:[])
        .'SELECT response_payload,candidate_sha256 FROM '.$view.' WHERE history_event_id=$1 AND actor_identity_id=$2',
        [$row['response_history_event_id'],$row['actor_identity_id']]);
    if (!$result) throw new RuntimeException('Unable to recheck relationship evaluation response');
    $response=pg_fetch_assoc($result);
    if (!$response) return null;
    $candidate=json_decode($response['response_payload'],true);
    if ($giftOnly) {
        if (!isset($candidate['delivery_lines'],$candidate['verified_gift'],$input['received_gift'])
            || ($input['response_candidate_sha256'] ?? null)!==$response['candidate_sha256']
            || $candidate['verified_gift']!=($input['received_gift']['fact'] ?? null)
            || synth_relationship_gift_response($candidate)!=$input['response']) return null;
    } elseif ($candidate!=$input['response']) return null;
    if (($input['schema'] ?? null)==='synth.relationship.evaluation.v2') {
        $pair=synth_relationship_pair($db,$event,'Player');
        $lines=synth_relationship_player_lines($context['payload'],$input['response']);
        if (!$pair || (!$lines && !$giftOnly) || !is_array($input['player_lines'] ?? null)
            || synth_protocol_canonical_json($input['player_lines'])!==synth_protocol_canonical_json($lines) || ($input['recent_events'] ?? null)!==[]
            || ($input['source_ids'] ?? null)!==[intval($row['response_history_event_id'])]
            || !is_array($input['subjects'] ?? null) || count($input['subjects'])!==1 || ($input['subjects'][0]['subject_id'] ?? null)!==$pair['subject_id']
            || ($input['subjects'][0]['label'] ?? null)!=='Player' || ($input['evaluation_policy'] ?? null)!=='synth.relationship.policy.v2') return null;
    } elseif (($input['schema'] ?? null)!=='synth.relationship.evaluation.v1' || isset($input['player_lines'])) return null;
    if (isset($input['received_gift'])) {
        $gift=synth_player_gift_for_event($db,$event);
        if ($gift===null || synth_protocol_canonical_json($gift)!==synth_protocol_canonical_json($input['received_gift']['fact'] ?? [])
            || intval($input['received_gift']['recipient_perspective']['actor_identity_id'] ?? 0)!==intval($row['actor_identity_id'])) return null;
        require_once dirname(__DIR__, 3).'/lib/synth_profile_state.php';
        if (($input['received_gift']['recipient_perspective']['profile_revision'] ?? null)!==(synth_profile_visible($db,$event,intval($row['actor_identity_id']))['revision'] ?? null)) return null;
    }
    // Player-only evaluations have exactly one source, the response already verified above.
    if (($input['schema'] ?? null)==='synth.relationship.evaluation.v2') {
        $sourceIds=\Synth\Core\DbManager\Dao\RelationshipEvaluationSourceDao::current($db)->historyEventIds($evaluationId);
    } else {
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true,sourceHistoryIds:$input['source_ids'])
            .'SELECT d.history_event_id FROM public.core_relationship_evaluation_source d
                JOIN relationship_visible_sources s USING(history_event_id) WHERE d.evaluation_id=$1 ORDER BY d.history_event_id',[$evaluationId]);
        if (!$result) throw new RuntimeException('Unable to recheck relationship evaluation sources');
        $sourceIds=array_map('intval',array_column(pg_fetch_all($result),'history_event_id'));
    }
    if ($sourceIds!==$input['source_ids']) return null;
    if (\Synth\Core\DbManager\Dao\RelationshipEvaluationSourceDao::current($db)->countFor($evaluationId)!==count($input['source_ids'])) return null;
    foreach ($input['recent_events'] as $recent) {
        if (!\Synth\Core\DbManager\Dao\HistoryEventDao::current($db)->hasTypeAndData($recent['history_event_id'],$recent['type'],$recent['data'])) return null;
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
        \Synth\Core\DbManager\Dao\WorkerJobDao::current()->lockRelationshipQueue($actorId);
    }
    $capacity=true;
    foreach (array_unique($actorIds) as $actorId) {
        $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current();
        $jobs->retireExpiredRelationshipQueue($actorId);
        if ($jobs->relationshipQueueDepth($actorId)>=2) $capacity=false;
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
            \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->retireInvalidEvaluation($evaluationId,$event['session_id'],$event['request_id'],$event['turn_id'],
                $event['generation'],$event['context_sequence'],synth_protocol_canonical_json($event));
            return null;
        }
        $capacity=synth_relationship_queue_has_capacity($connection,[intval($input['actor_identity_id'])]);
        $jobPayload=['context_sequence'=>$event['context_sequence']];
        if (isset(json_decode($input['input_payload'],true,flags:JSON_THROW_ON_ERROR)['received_gift'])) {
            $jobPayload['schema']='synth.relationship.gift-job.v1';
        }
        $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
        $created=$jobs->enqueueEvaluation($evaluationId,$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],
            $input['actor_identity_id'],json_encode($jobPayload,JSON_THROW_ON_ERROR),$capacity);
        return $created ?? $jobs->activeJobForEvaluation($evaluationId);
    },2000);
}

// A fresh fencing token replaces an expired attempt; all frozen inputs are checked before granting the lease.
function synth_relationship_claim_evaluation($db, int $jobId): ?array
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId) {
        $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
        $evaluationId=$jobs->evaluationOfAttempt($jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']);
        if ($evaluationId===null) return null;
        $input=synth_relationship_evaluation_input($connection,$db,$event,intval($evaluationId));
        if (!$input) {
            $jobs->retireInvalidEvaluationJob($jobId,synth_protocol_canonical_json($event));
            return null;
        }
        $jobs->failIfExpired($jobId);
        $claimed=$jobs->claimAttempt($jobId,bin2hex(random_bytes(16)));
        return $claimed ? array_merge($claimed,['input_payload'=>$input['input_payload']]) : null;
    },2000);
}

// Fixed failure codes and retry limits prevent provider diagnostics or secrets entering durable state.
function synth_relationship_fail_evaluation($db, int $jobId, string $token): bool
{
    return $db->withNativeRequestLock(static function($connection,array $event) use($jobId,$token) {
        return \Synth\Core\DbManager\Dao\WorkerJobDao::current()->failEvaluationAttempt($jobId,$event['session_id'],$event['request_id'],$event['generation'],(string)$event['context_sequence'],$token);
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
        $job=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->evaluationCompletionState($jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']);
        if (!$job || !hash_equals($job['lease_token'] ?? '',$token) || !in_array($job['state'],['running','ready'],true)
            || ($job['state']==='running' && $job['live']!=='t')) throw new SYNTHProtocolCancelled('relationship_lease_lost');
        $evaluation=synth_relationship_evaluation_input($connection,$db,$event,intval($job['relationship_evaluation_id']));
        if (!$evaluation) throw new SYNTHProtocolCancelled('relationship_input_unavailable');
        $input=json_decode($evaluation['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $parsed=synth_relationship_parse_evaluation($modelText,$input);
        $json=json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if ($job['state']==='ready') {
            $existing=\Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->replayFor($jobId,$json);
            if (!$existing) throw new LogicException('Conflicting relationship evaluation replay');
            return intval($existing);
        }
        require_once __DIR__.'/synth_relationship_interaction_result.php';
        if (synth_relationship_paired_response_consumed($connection,$db,$event,intval($evaluation['response_history_event_id']),
            array_column($input['subjects'],'subject_id'))) {
            throw new LogicException('Relationship response already evaluated by paired worker');
        }
        \Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->lockResponse($evaluation['actor_identity_id'],$evaluation['response_history_event_id']);
        // A verified gift has one immutable response. A result for that receipt is a duplicate on every save.
        if (isset($input['received_gift'])) {
            $consumed=\Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->existsForResponse($evaluation['response_history_event_id']);
        } else {
            $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,sourceHistoryIds:[])
                .'SELECT 1 FROM native_relationship_results WHERE response_history_event_id=$1',[$evaluation['response_history_event_id']]);
            if (!$result) throw new RuntimeException('Unable to check prior relationship result');
            $consumed=pg_fetch_assoc($result)!==false;
        }
        if ($consumed) throw new LogicException('Relationship response already evaluated');
        if (\Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->existsAfterContext($event['session_id'],$event['generation'],$evaluation['response_history_event_id'],
            $evaluation['actor_identity_id'],$event['context_sequence'])) throw new LogicException('Native relationship evaluation is stale');
        $resultId=\Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->publish($input['context']['playthrough_id'],intval($input['context']['world']['game_time_ticks'] ?? 0),time(),
            $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],$evaluation['input_sha256'],$json,$evaluation['id'],$jobId);
        $subjects=[];
        foreach ($input['subjects'] as $subject) {
            $subjects[$subject['subject_id']]=$subject;
            if ($subject['relationship']!==null) \Synth\Core\DbManager\Dao\RelationshipResultPriorDao::current($db)->add($resultId,$subject['relationship']['revision']);
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
        if (!\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->markReady($jobId,$token)) throw new SYNTHProtocolCancelled('relationship_lease_lost');
        return $resultId;
    },2000);
}

// The actual provider runs outside SQL transactions; every outcome goes through the same fenced completion boundary.
function synth_relationship_process_evaluation($db, int $jobId, callable $generate): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Relationship providers require an idle database connection');
    $job=synth_relationship_claim_evaluation($db,$jobId); if (!$job) return null;
    $previous=\Synth\Bootstrap::config('SYNTH_RELATIONSHIP_JOB_LEASE') ?? null;
    \Synth\Bootstrap::set('SYNTH_RELATIONSHIP_JOB_LEASE', ['id'=>$jobId,'token'=>$job['lease_token']]);
    try {
        $input=json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $text=$generate($input);
        if (!is_string($text)) throw new InvalidArgumentException('Invalid relationship model result');
        return synth_relationship_complete_evaluation($db,$jobId,$job['lease_token'],$text);
    } catch (SYNTHProtocolCancelled $error) { throw $error;
    } catch (Throwable $error) {
        synth_relationship_fail_evaluation($db,$jobId,$job['lease_token']); throw $error;
    } finally {
        if ($previous===null) \Synth\Bootstrap::forget('SYNTH_RELATIONSHIP_JOB_LEASE');
        else \Synth\Bootstrap::set('SYNTH_RELATIONSHIP_JOB_LEASE', $previous);
    }
}
