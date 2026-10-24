<?php

require_once __DIR__.'/synth_protocol_state.php';
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
    $row=$db->fetchOne(synth_protocol_history_cte($db,$event).'SELECT * FROM native_ancestry WHERE depth=1');
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
    $subjectRow=$db->fetchOne('SELECT id FROM synth.actor_identities WHERE game=\'fo4\' AND upper(full_form_id)=upper('
        .$db->escapeLiteral($identity['form_id']).') AND lower(origin_plugin)=lower('.$db->escapeLiteral($identity['origin_plugin'])
        .') AND playthrough_id='.$db->escapeLiteral($identity['playthrough_id']));
    if (!$subjectRow || intval($subjectRow['id'])===intval($row['reader_identity_id'])) return null;
    return ['actor_id'=>intval($row['reader_identity_id']),'subject_id'=>intval($subjectRow['id']),
        'playthrough_id'=>$row['playthrough_id'],'context'=>$payload];
}

// Both publication and every inherited input must be reachable from this exact actor's saved frontier.
function synth_relationship_visible_cte($db, array $event, bool $admitCurrentResponse=false, ?int $readerIdentityId=null): string
{
    return synth_protocol_history_cte($db,$event,'',$readerIdentityId).synth_relationship_graph_scope_cte()
        .synth_protocol_history_rows_cte(true).synth_protocol_history_rows_cte(true,false,true,true)
        .synth_relationship_response_evidence_cte($db,$event,$admitCurrentResponse,true)
        .", relationship_response_evidence AS (
        SELECT * FROM relationship_graph_responses WHERE actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
    ), relationship_graph_sources AS MATERIALIZED (
        SELECT DISTINCT history_event_id,reader_identity_id AS actor_identity_id FROM relationship_graph_source_history
        WHERE type IN ('inputtext','inputtext_s','chat','backgroundchat','narration')
            AND delivery_state IN ('received','emitted')
        UNION SELECT history_event_id,actor_identity_id FROM relationship_graph_responses
    ), relationship_visible_sources AS MATERIALIZED (
        SELECT history_event_id FROM relationship_graph_sources WHERE actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
    ), relationship_revision_history AS MATERIALIZED (
        SELECT h.* FROM synth_meta.history_events h
        JOIN synth_meta.relationship_revisions revision ON revision.history_event_id=h.id
        WHERE EXISTS (SELECT 1 FROM relationship_graph_scopes scope
            WHERE scope.session_id=h.session_id AND scope.generation=h.generation
                AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=revision.actor_identity_id
                AND h.id<=scope.cutoff)
    ), relationship_candidates AS MATERIALIZED (
        SELECT r.*,h.playthrough_id FROM synth_meta.relationship_revisions r
        JOIN relationship_revision_history h ON h.id=r.history_event_id
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff AND r.actor_identity_id=scope.reader_identity_id
        JOIN synth_meta.context_snapshots origin ON origin.session_id=h.session_id
            AND origin.generation=h.generation AND origin.snapshot_sequence=h.context_sequence
            AND origin.playthrough_id=h.playthrough_id AND origin.target_identity_id=r.actor_identity_id
        WHERE h.event_payload->>'type'='relationship_state'
            AND r.affinity IS NOT NULL AND r.relationship_type IS NOT NULL
            AND r.interaction_result_history_event_id IS NULL
            AND r.details=COALESCE(h.event_payload->'relationship_details','{}'::jsonb)
            AND r.input_payload->'prior'=COALESCE(to_jsonb(r.prior_history_event_id),'null'::jsonb)
            AND COALESCE(r.input_payload->'evaluation_result','null'::jsonb)=COALESCE(to_jsonb(r.result_history_event_id),'null'::jsonb)
            AND jsonb_typeof(r.input_payload->'sources')='array'
            AND EXISTS (SELECT 1 FROM synth_meta.relationship_revision_sources d WHERE d.relationship_history_event_id=r.history_event_id)
            AND (SELECT count(*) FROM synth_meta.relationship_revision_sources d WHERE d.relationship_history_event_id=r.history_event_id)
                =jsonb_array_length(CASE WHEN jsonb_typeof(r.input_payload->'sources')='array' THEN r.input_payload->'sources' ELSE '[]'::jsonb END)
            AND NOT EXISTS (SELECT 1 FROM synth_meta.relationship_revision_sources d
                WHERE d.relationship_history_event_id=r.history_event_id AND (
                    NOT (r.input_payload->'sources' @> jsonb_build_array(d.source_history_event_id)) OR NOT EXISTS (
                    SELECT 1 FROM relationship_graph_sources s WHERE s.history_event_id=d.source_history_event_id AND s.actor_identity_id=r.actor_identity_id)))
    ) ".synth_relationship_result_state_cte();
}

// Unknown native state is explicit; the legacy name-keyed profile is never a fallback for this reader.
function synth_relationship_read($db, string $subject): array
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return ['availability'=>'unclaimed','relationship'=>null];
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db,$subject) {
        $pair=synth_relationship_pair($db,$event,$subject);
        if (!$pair) return ['availability'=>'context_or_subject_unavailable','relationship'=>null];
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event)
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
        $responses=@pg_query($connection,'SELECT history_event_id FROM synth_meta.relationship_response_inputs WHERE history_event_id IN ('.implode(',',$sources).')');
        if (!$responses) throw new RuntimeException('Unable to verify relationship response boundaries');
        foreach (pg_fetch_all($responses) as $response) {
            if (!synth_relationship_write_allowed($connection,$event,$pair['actor_id'],intval($response['history_event_id']))) {
                throw new LogicException('Relationship response predates the current control');
            }
        }
        $key='synth-relationship:'.$pair['actor_id'].':'.$pair['subject_id'];
        if (@pg_query_params($connection,'SELECT pg_advisory_xact_lock(hashtextextended($1,0))',[$key])===false) {
            throw new RuntimeException('Unable to lock native relationship');
        }
        $input=['delta'=>$delta,'type'=>$type,'prior'=>$expectedRevision,'sources'=>$sources];
        if ($details!==null) $input['details']=(object)$details;
        if ($evaluationResult!==null) {
            $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.relationship_results r JOIN synth_meta.relationship_evaluations i ON i.id=r.evaluation_id
                WHERE r.history_event_id=$1 AND i.session_id=$2 AND i.request_id=$3 AND i.actor_identity_id=$4 AND i.context_sequence=$5 AND i.generation=$6',
                [$evaluationResult,$event['session_id'],$event['request_id'],$pair['actor_id'],$event['context_sequence'],$event['generation']]);
            if (!$result || !pg_fetch_assoc($result)) throw new LogicException('Native relationship result owner mismatch');
            $input['evaluation_result']=$evaluationResult;
        }
        $input=json_encode($input,JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$input);
        $existing=@pg_query_params($connection,'SELECT r.history_event_id,r.input_hash,h.context_sequence FROM synth_meta.relationship_revisions r
            JOIN synth_meta.history_events h ON h.id=r.history_event_id
            WHERE h.session_id=$1 AND h.generation=$2 AND h.request_id=$3
                AND r.actor_identity_id=$4 AND r.subject_identity_id=$5',
            [$event['session_id'],$event['generation'],$event['request_id'],$pair['actor_id'],$pair['subject_id']]);
        if (!$existing) throw new RuntimeException('Unable to check relationship replay');
        if ($row=pg_fetch_assoc($existing)) {
            if (intval($row['context_sequence'])!==intval($event['context_sequence']) || !hash_equals($row['input_hash'],$hash)) {
                throw new LogicException('Conflicting relationship replay');
            }
            return intval($row['history_event_id']);
        }
        $state=synth_relationship_read($db,$subject)['relationship'];
        if (($state['revision'] ?? null)!==$expectedRevision) throw new LogicException('Native relationship revision changed');
        if ($type!==null && synth_relationship_canonical_type($type)!==$type) {
            // Custom types must already belong to this actor's saved state, not a mutable global profile.
            $known=@pg_query_params($connection,synth_relationship_visible_cte($db,$event)
                .'SELECT 1 FROM (SELECT DISTINCT ON(subject_identity_id) relationship_type FROM native_relationships
                    ORDER BY subject_identity_id,history_event_id DESC) current_types WHERE relationship_type=$1 LIMIT 1',[$type]);
            if (!$known || !pg_fetch_assoc($known)) throw new InvalidArgumentException('Invalid native relationship type');
        }
        // Unsaved concurrent publication is stale. Invalid older revisions must not permanently block recovery.
        $head=@pg_query_params($connection,'SELECT max(r.history_event_id) AS id FROM synth_meta.relationship_revisions r
            JOIN synth_meta.history_events h ON h.id=r.history_event_id WHERE h.session_id=$1 AND h.generation=$2
                AND r.actor_identity_id=$3 AND r.subject_identity_id=$4 AND h.id>(
                    SELECT history_event_id FROM synth_meta.context_snapshots WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$5)',
            [$event['session_id'],$event['generation'],$pair['actor_id'],$pair['subject_id'],$event['context_sequence']]);
        if (!$head) throw new RuntimeException('Unable to check relationship head');
        $headId=pg_fetch_assoc($head)['id'];
        if ($headId!==null) throw new LogicException('Native relationship proposal is stale');
        $visible=@pg_query($connection,synth_relationship_visible_cte($db,$event,true)
            .'SELECT count(*) AS n FROM relationship_visible_sources WHERE history_event_id IN ('.implode(',',$sources).')');
        if (!$visible || intval(pg_fetch_assoc($visible)['n'])!==count($sources)) throw new OutOfBoundsException('Native relationship evidence unavailable');
        $affinity=max(-100,min(100,intval($state['aff'] ?? 0)+$delta));
        $newType=$type ?? $state['type'] ?? 'neutral';
        $storedDetails=$details ?? array_diff_key($state ?? [],array_flip(['revision','aff','type']));
        $detailsJson=json_encode((object)$storedDetails,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $result=@pg_query_params($connection,"WITH publication AS (
            INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('relationship_state','Native relationship state publication',$1,$2,$3,$3,'','','','derived',$4,$5,$6,$7) RETURNING *
        ), history AS (
            INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$1,rowid,to_jsonb(publication)
                ||jsonb_build_object('relationship_details',$16::jsonb)
            FROM publication RETURNING id
        ) INSERT INTO synth_meta.relationship_revisions(history_event_id,actor_identity_id,subject_identity_id,
            prior_history_event_id,affinity,relationship_type,input_payload,input_hash,result_history_event_id,details)
            SELECT id,$8,$9,$10,$11,$12,$13::jsonb,$14,$15,$16::jsonb FROM history RETURNING history_event_id",
            [$pair['playthrough_id'],intval($pair['context']['world']['game_time_ticks'] ?? 0),time(),
             $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],
             $pair['actor_id'],$pair['subject_id'],$expectedRevision,$affinity,$newType,$input,$hash,$evaluationResult,$detailsJson]);
        if (!$result) throw new RuntimeException('Unable to publish native relationship');
        $id=intval(pg_fetch_assoc($result)['history_event_id']);
        foreach ($sources as $source) {
            if (@pg_query_params($connection,'INSERT INTO synth_meta.relationship_revision_sources VALUES($1,$2)',[$id,$source])===false) {
                throw new RuntimeException('Unable to freeze native relationship evidence');
            }
        }
        return $id;
    },2000);
}

// Apply accepted inline directives after completion, retaining exact saved priors and current controls.
function synth_relationship_apply_inline_response($db): array
{
    if (!synthRelationshipSettingEnabled() || synthRelationshipUsesDedicatedConnector()) return [];
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db) {
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true)
            .'SELECT history_event_id,response_payload FROM relationship_response_evidence
                WHERE session_id=$1 AND request_id=$2 AND generation=$3 AND context_sequence=$4',
            [$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to load owned relationship response');
        $response=pg_fetch_assoc($result); if (!$response) return [];
        $payload=json_decode($response['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        if (($payload['evaluation_mode'] ?? null)!=='inline') return [];
        $context=synth_protocol_current_context($db,$event); $target=$context['target'];
        $result=@pg_query($connection,synth_relationship_visible_cte($db,$event)
            .'SELECT DISTINCT relationship_type AS type FROM (SELECT DISTINCT ON(subject_identity_id) relationship_type
                FROM native_relationships ORDER BY subject_identity_id,history_event_id DESC) current_types WHERE relationship_type IS NOT NULL');
        if (!$result) throw new RuntimeException('Unable to load saved relationship proposal state');
        $typeMap=pg_fetch_all($result);
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
