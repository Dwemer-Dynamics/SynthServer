<?php

require_once dirname(__DIR__, 3).'/lib/synth_protocol_state.php';
require_once __DIR__.'/relationship_runtime.php';
require_once __DIR__.'/relationship_response.php';
require_once __DIR__.'/synth_relationship_result_state.php';
require_once __DIR__.'/synth_relationship_policy.php';
require_once __DIR__.'/synth_relationship_control.php';
require_once __DIR__.'/synth_relationship_graph_scope.php';

// A directed pair is resolved only from the acknowledged context, never a global profile/name registry.
function synth_relationship_pair($db, array $event, string $subject): ?array
{
    $context=synth_protocol_current_context($db,$event);
    if (!$context || !is_array($context['target'])) return null;
    $historyCte=synth_protocol_history_cte($db,$event);
    try {
        $row=\Synth\Core\DbManager\Dao\HistoryEventDao::current($db)->readerFrontier($historyCte);
    } catch (Throwable $error) {
        // The shared session logs the failed statement; an unreadable frontier leaves the pair unresolved, as before.
        $row=null;
    }
    if (!$row) return null;
    $payload=$context['payload'];
    $subject=trim($subject);
    if ($subject==='' || strlen($subject)>256 || !mb_check_encoding($subject,'UTF-8')) return null;
    $aliases=['player','the player','player character','the player character','sole survivor','the sole survivor',
        '#player_name#','{player_name}'];
    if (in_array(strtolower($subject),$aliases,true)) {
        $identity=$context['player'];
    } else {
        $matches=[];
        foreach (array_merge([$context['player'],$context['target']],$payload['audience'] ?? []) as $actor) {
            if (!is_array($actor) || strcasecmp(trim(strval($actor['display_name'] ?? '')),$subject)!==0) continue;
            $key=strtolower($actor['form_id'].'|'.$actor['origin_plugin'].'|'.$actor['playthrough_id']);
            $matches[$key]=$actor;
        }
        if (count($matches)!==1) return null;
        $identity=reset($matches);
    }
    // All identities already exist from context ingestion; resolving a read must not create or rename one.
    if (($identity['playthrough_id'] ?? null)!==$row['playthrough_id']) return null;
    $subjectId=\Synth\Core\DbManager\Dao\ActorIdentityDao::current($db)->idByIdentity(strval($identity['form_id']),strval($identity['origin_plugin']),strval($identity['playthrough_id']));
    if (!$subjectId || $subjectId===intval($row['reader_identity_id'])) return null;
    return ['actor_id'=>intval($row['reader_identity_id']),'subject_id'=>$subjectId,
        'playthrough_id'=>$row['playthrough_id'],'context'=>$payload];
}

// Both publication and every inherited input must be reachable from this exact actor's saved frontier.
function synth_relationship_visible_cte($db, array $event, bool $admitCurrentResponse=false, ?int $readerIdentityId=null, ?array $sourceHistoryIds=null): string
{
    // Validate existing relationship edges and explicitly requested sources without scanning all dialogue.
    // Keep the full history projection for callers that request raw sources.
    $dependencySources='';
    if ($sourceHistoryIds!==null) {
        foreach ($sourceHistoryIds as $id) {
            if (!is_int($id) || $id<1) throw new InvalidArgumentException('Invalid relationship history source');
        }
        $dependencySources=", relationship_graph_dependency_sources(history_event_id) AS MATERIALIZED (
            SELECT source_history_event_id FROM public.core_relationship_revision_source
            UNION SELECT history_event_id FROM public.core_relationship_evaluation_source
            UNION SELECT unnest(ARRAY[".implode(',',$sourceHistoryIds)."]::bigint[])
        ) ";
    }
    return synth_protocol_history_cte($db,$event,'',$readerIdentityId).synth_relationship_graph_scope_cte()
        .$dependencySources.synth_protocol_history_rows_cte(true)
        .synth_protocol_history_rows_cte(true,false,true,true,limitRelationshipSources:$sourceHistoryIds!==null)
        .synth_relationship_response_evidence_cte($db,$event,$admitCurrentResponse,true)
        .", relationship_response_evidence AS (
        SELECT * FROM relationship_graph_responses WHERE actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
    ), relationship_response_candidates AS (
        SELECT * FROM relationship_graph_responses_candidates WHERE actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
    ), relationship_graph_sources AS MATERIALIZED (
        SELECT DISTINCT history_event_id,reader_identity_id AS actor_identity_id FROM relationship_graph_source_history
        WHERE type IN ('inputtext','inputtext_s','chat','backgroundchat','narration')
            AND delivery_state IN ('received','emitted','spoken','presented')
        UNION SELECT history_event_id,actor_identity_id FROM relationship_graph_responses
    ), relationship_visible_sources AS MATERIALIZED (
        SELECT history_event_id FROM relationship_graph_sources WHERE actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
    ), relationship_revision_history AS MATERIALIZED (
        SELECT h.* FROM public.core_history_event h
        JOIN public.core_relationship_revision revision ON revision.history_event_id=h.id
        WHERE EXISTS (SELECT 1 FROM relationship_graph_scopes scope
            WHERE scope.session_id=h.session_id AND scope.generation=h.generation
                AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=revision.actor_identity_id
                AND h.id<=scope.cutoff)
    ), relationship_candidates AS MATERIALIZED (
        SELECT r.*,h.playthrough_id FROM public.core_relationship_revision r
        JOIN relationship_revision_history h ON h.id=r.history_event_id
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff AND r.actor_identity_id=scope.reader_identity_id
        JOIN public.core_runtime_context origin ON origin.session_id=h.session_id
            AND origin.generation=h.generation AND origin.snapshot_sequence=h.context_sequence
            AND origin.playthrough_id=h.playthrough_id AND origin.target_identity_id=r.actor_identity_id
        WHERE h.event_payload->>'type'='relationship_state'
            AND r.affinity IS NOT NULL AND r.relationship_type IS NOT NULL
            AND r.interaction_result_history_event_id IS NULL
            AND r.details=COALESCE(h.event_payload->'relationship_details','{}'::jsonb)
            AND r.input_payload->'prior'=COALESCE(to_jsonb(r.prior_history_event_id),'null'::jsonb)
            AND COALESCE(r.input_payload->'evaluation_result','null'::jsonb)=COALESCE(to_jsonb(r.result_history_event_id),'null'::jsonb)
            AND jsonb_typeof(r.input_payload->'sources')='array'
            AND EXISTS (SELECT 1 FROM public.core_relationship_revision_source d WHERE d.relationship_history_event_id=r.history_event_id)
            AND (SELECT count(*) FROM public.core_relationship_revision_source d WHERE d.relationship_history_event_id=r.history_event_id)
                =jsonb_array_length(CASE WHEN jsonb_typeof(r.input_payload->'sources')='array' THEN r.input_payload->'sources' ELSE '[]'::jsonb END)
            AND NOT EXISTS (SELECT 1 FROM public.core_relationship_revision_source d
                WHERE d.relationship_history_event_id=r.history_event_id AND (
                    NOT (r.input_payload->'sources' @> jsonb_build_array(d.source_history_event_id)) OR NOT (
                    EXISTS(SELECT 1 FROM relationship_graph_sources s WHERE s.history_event_id=d.source_history_event_id AND s.actor_identity_id=r.actor_identity_id)
                    OR EXISTS(SELECT 1 FROM relationship_gift_fact_sources fact WHERE fact.history_event_id=d.source_history_event_id
                        AND fact.actor_identity_id=r.actor_identity_id AND fact.result_history_event_id=r.result_history_event_id))))
    ) ".synth_relationship_result_state_cte();
}

// Unknown native state is explicit; the legacy name-keyed profile is never a fallback for this reader.
function synth_relationship_read($db, string $subject): array
{
    if (empty(\Synth\Bootstrap::claimedRequest())) return ['availability'=>'unclaimed','relationship'=>null];
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db,$subject) {
        $pair=synth_relationship_pair($db,$event,$subject);
        if (!$pair) return ['availability'=>'context_or_subject_unavailable','relationship'=>null];
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,sourceHistoryIds:[])
            .'SELECT history_event_id,affinity,relationship_type,details FROM native_relationships
                WHERE subject_identity_id=$1 ORDER BY history_event_id DESC LIMIT 1',[$pair['subject_id']]);
        if (!$result) throw new RuntimeException('Unable to read native relationship');
        $row=pg_fetch_assoc($result);
        return ['availability'=>$row && $row['affinity']!==null ? 'available' : 'unknown','relationship'=>$row ? [
            'revision'=>intval($row['history_event_id']),'aff'=>$row['affinity']===null ? null : intval($row['affinity']),'type'=>$row['relationship_type']]
                +json_decode($row['details'],true,flags:JSON_THROW_ON_ERROR) : null];
    },2000);
}

// Database-only groundwork: callers must supply frozen visible inputs; game-facing hooks are not cut over yet.
function synth_relationship_publish($db, string $subject, int $delta, ?string $type, ?int $expectedRevision, array $sources, ?int $evaluationResult=null, ?array $details=null): int
{
    if ($details!==null) synth_relationship_validate_details($details);
    if ($delta < -200 || $delta > 200 || ($expectedRevision!==null && $expectedRevision<1)
        || ($evaluationResult!==null && $evaluationResult<1) || !array_is_list($sources) || count($sources)<1 || count($sources)>150) {
        throw new InvalidArgumentException('Invalid native relationship proposal');
    }
    foreach ($sources as $id) if (!is_int($id) || $id<1) throw new InvalidArgumentException('Invalid native relationship source');
    $sources=array_values(array_unique($sources)); sort($sources,SORT_NUMERIC);
    if ($type!==null && preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$type)!==1) {
        throw new InvalidArgumentException('Invalid native relationship type');
    }
    if (!synthRelationshipSettingEnabled()) throw new LogicException('Relationships are disabled');
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db,$subject,$delta,$type,$expectedRevision,$sources,$evaluationResult,$details) {
        $pair=synth_relationship_pair($db,$event,$subject);
        if (!$pair) throw new OutOfBoundsException('Native relationship context or subject unavailable');
        if (!synth_relationship_write_allowed($connection,$event,$pair['actor_id'])) throw new LogicException('Relationship control blocks this proposal');
        foreach (\Synth\Core\DbManager\Dao\RelationshipResponseInputDao::current($db)->responseIdsAmong($sources) as $responseId) {
            if (!synth_relationship_write_allowed($connection,$event,$pair['actor_id'],$responseId)) {
                throw new LogicException('Relationship response predates the current control');
            }
        }
        $key='synth-relationship:'.$pair['actor_id'].':'.$pair['subject_id'];
        \Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->lockPair($key);
        $input=['delta'=>$delta,'type'=>$type,'prior'=>$expectedRevision,'sources'=>$sources];
        if ($details!==null) $input['details']=(object)$details;
        if ($evaluationResult!==null) {
            if (!\Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->ownedByEvaluation($evaluationResult,$event['session_id'],$event['request_id'],
                $pair['actor_id'],$event['context_sequence'],$event['generation'])) throw new LogicException('Native relationship result owner mismatch');
            $input['evaluation_result']=$evaluationResult;
        }
        $input=json_encode($input,JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$input);
        $row=\Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->replayFor($event['session_id'],$event['generation'],$event['request_id'],$pair['actor_id'],$pair['subject_id']);
        if ($row) {
            if (intval($row['context_sequence'])!==intval($event['context_sequence']) || !hash_equals($row['input_hash'],$hash)) {
                throw new LogicException('Conflicting relationship replay');
            }
            return intval($row['history_event_id']);
        }
        $state=synth_relationship_read($db,$subject)['relationship'];
        if (($state['revision'] ?? null)!==$expectedRevision) throw new LogicException('Native relationship revision changed');
        if ($type!==null && synth_relationship_canonical_type($type)!==$type) {
            // Custom types must already belong to this actor's saved state, not a mutable global profile.
            $known=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,sourceHistoryIds:[])
                .'SELECT 1 FROM (SELECT DISTINCT ON(subject_identity_id) relationship_type FROM native_relationships
                    ORDER BY subject_identity_id,history_event_id DESC) current_types WHERE relationship_type=$1 LIMIT 1',[$type]);
            if (!$known || !pg_fetch_assoc($known)) throw new InvalidArgumentException('Invalid native relationship type');
        }
        // Unsaved concurrent publication is stale. Invalid older revisions must not permanently block recovery.
        $headId=\Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->headAfterContext($event['session_id'],$event['generation'],$pair['actor_id'],$pair['subject_id'],
            $event['context_sequence']);
        if ($headId!==null) throw new LogicException('Native relationship proposal is stale');
        $visibleCte=synth_relationship_visible_cte($db,$event,true,sourceHistoryIds:$sources);
        try {
            $visible=\Synth\Core\DbManager\Dao\HistoryEventDao::current($db)->visibleSourceCount($visibleCte,$sources);
        } catch (Throwable $error) {
            throw new OutOfBoundsException('Native relationship evidence unavailable',0,$error);
        }
        if ($visible!==count($sources)) {
            // Only this already-persisted result may use a factual gift source before its thank-you is heard. Reuse
            // full worker revalidation under the same owner/lease; never admit the raw candidate as speech evidence.
            $factEvaluation=$evaluationResult===null ? null : \Synth\Core\DbManager\Dao\RelationshipResultDao::current($db)->evaluationIdOf($evaluationResult);
            $validated=$factEvaluation!==null ? synth_relationship_evaluation_input($connection,$db,$event,intval($factEvaluation)) : null;
            $inputFact=$validated ? json_decode($validated['input_payload'],true,flags:JSON_THROW_ON_ERROR) : null;
            if (($inputFact['gift_only'] ?? null)!==true || ($inputFact['source_ids'] ?? null)!==$sources)
                throw new OutOfBoundsException('Native relationship evidence unavailable');
        }
        $affinity=max(-100,min(100,intval($state['aff'] ?? 0)+$delta));
        $newType=$type ?? $state['type'] ?? 'neutral';
        $storedDetails=$details ?? array_diff_key($state ?? [],array_flip(['revision','aff','type']));
        $detailsJson=json_encode((object)$storedDetails,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $id=\Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->publish($pair['playthrough_id'],intval($pair['context']['world']['game_time_ticks'] ?? 0),time(),
            $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],
            $pair['actor_id'],$pair['subject_id'],$expectedRevision,$affinity,$newType,$input,$hash,$evaluationResult,$detailsJson);
        foreach ($sources as $source) \Synth\Core\DbManager\Dao\RelationshipRevisionSourceDao::current($db)->add($id,$source);
        return $id;
    },2000);
}

// Apply accepted inline directives after completion, retaining exact saved priors and current controls.
function synth_relationship_apply_inline_response($db): array
{
    if (!synthRelationshipSettingEnabled() || synthRelationshipUsesDedicatedConnector()) return [];
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db) {
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true,sourceHistoryIds:[])
            .'SELECT history_event_id,response_payload FROM relationship_response_evidence
                WHERE session_id=$1 AND request_id=$2 AND generation=$3 AND context_sequence=$4',
            [$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to load owned relationship response');
        $response=pg_fetch_assoc($result); if (!$response) return [];
        $payload=json_decode($response['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        if (($payload['evaluation_mode'] ?? null)!=='inline') return [];
        $context=synth_protocol_current_context($db,$event); $target=$context['target'];
        $visibleCte=synth_relationship_visible_cte($db,$event,sourceHistoryIds:[]);
        try {
            $typeMap=\Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->savedCurrentTypes($visibleCte);
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to load saved relationship proposal state',0,$error);
        }
        $typeMap[]=['type'=>'wary'];
        $text=[];
        foreach ($payload['entries'] as $entry) {
            $speaker=$entry['speaker'];
            if (($speaker['playthrough_id'] ?? null)===$target['playthrough_id']
                && strcasecmp($speaker['form_id'] ?? '',$target['form_id'])===0
                && strcasecmp($speaker['origin_plugin'] ?? '',$target['origin_plugin'])===0) $text[]=$entry['text'];
        }
        $commands=RelationshipManager::extractChangeCommands(implode(' ',$text),$typeMap);
        if (count($commands['affinity'])+count($commands['types'])>128) throw new LengthException('Too many native relationship commands');
        $proposals=[]; $pairs=[];
        foreach (['affinity','types'] as $kind) foreach ($commands[$kind] as $command) {
            $subject=$command['target'];
            if (!array_key_exists($subject,$pairs)) $pairs[$subject]=synth_relationship_pair($db,$event,$subject);
            $pair=$pairs[$subject]; if (!$pair) continue;
            $id=$pair['subject_id'];
            if (!isset($proposals[$id])) {
                $base=synth_relationship_read($db,$subject)['relationship'] ?? ['revision'=>null,'aff'=>0,'type'=>'neutral'];
                $proposals[$id]=['subject'=>$subject,'base'=>$base,'aff'=>$base['aff'],'type'=>$base['type']];
            }
            if ($kind==='affinity') $proposals[$id]['aff']=max(-100,min(100,$proposals[$id]['aff']+max(-200,min(200,$command['delta']))));
            else $proposals[$id]['type']=$command['type'];
        }
        // Stable directed-pair order plus the outer transaction makes a multi-subject response all-or-nothing.
        ksort($proposals,SORT_NUMERIC); $published=[];
        foreach ($proposals as $id=>$proposal) {
            $delta=$proposal['aff']-$proposal['base']['aff'];
            $type=$proposal['type']===$proposal['base']['type'] ? null : $proposal['type'];
            if ($delta===0 && $type===null) continue;
            $published[$id]=synth_relationship_publish($db,$proposal['subject'],$delta,$type,
                $proposal['base']['revision'],[intval($response['history_event_id'])]);
        }
        return $published;
    },2000);
}
