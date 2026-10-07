<?php

require_once __DIR__.'/synth_middleterm_scope.php';
require_once __DIR__.'/synth_middleterm_regeneration.php';

// Confirmation binds an exact reviewed plan independently of any later worker owner.
function synth_middleterm_approval_input(array $input): array
{
    if (array_diff(array_keys($input),['session_id','generation','context_sequence','actor_identity_id','narrative_id','expected_revision','expected_plan_sha256','approval_token'])) {
        throw new InvalidArgumentException('Unknown regeneration approval field.');
    }
    $scope=array_diff_key($input,array_flip(['expected_revision','expected_plan_sha256','approval_token']));
    $scope=synth_middleterm_scope_input(['operation'=>'detail']+$scope);
    unset($scope['operation']);
    if ((!is_string($input['expected_revision']??null) && !is_int($input['expected_revision']??null))
        || !preg_match('/^(0|[1-9][0-9]{0,17})$/D',strval($input['expected_revision']))
        || !is_string($input['approval_token']??null) || !preg_match('/^[a-f0-9]{32}$/D',$input['approval_token'])
        || !is_string($input['expected_plan_sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$input['expected_plan_sha256'])) {
        throw new InvalidArgumentException('Regeneration requires an exact revision, reviewed plan hash and confirmation token.');
    }
    return $scope+['expected_revision'=>strval($input['expected_revision']),'expected_plan_sha256'=>$input['expected_plan_sha256'],
        'approval_token'=>$input['approval_token']];
}

// Validate immutable receipt provenance independently of its current execution eligibility.
function synth_middleterm_approval_cte(string $prefix='WITH',bool $savedScope=false): string
{
    // Bound the history reader's planner at saved approvals; management lookups retain predicate pushdown by exact ID/token.
    return $prefix.' regeneration_receipts AS '.($savedScope?'MATERIALIZED ':'')."(
        SELECT q.* FROM synth_meta.middleterm_regeneration_approvals q
        JOIN synth_meta.history_events h ON h.id=q.history_event_id AND h.session_id=q.session_id
            AND h.generation=q.generation AND h.context_sequence=q.context_sequence AND h.playthrough_id=q.playthrough_id
        JOIN synth_meta.context_snapshots origin ON origin.session_id=q.session_id AND origin.generation=q.generation
            AND origin.snapshot_sequence=q.context_sequence AND origin.playthrough_id=q.playthrough_id
        JOIN synth_meta.middleterm_results result ON result.history_event_id=q.narrative_id
        JOIN synth_meta.middleterm_inputs target ON target.id=result.input_id
            AND target.actor_identity_id=q.actor_identity_id AND target.playthrough_id=q.playthrough_id
        JOIN synth.actor_identities actor ON actor.id=q.actor_identity_id AND actor.playthrough_id=q.playthrough_id AND actor.game='fo4'
        WHERE h.request_id IS NULL AND h.event_payload->>'type'='middleterm_regeneration'
            AND h.event_payload->>'delivery_state'='derived' AND origin.player_identity_id IS NOT NULL
            AND origin.player_identity_id<>q.actor_identity_id
            AND h.event_payload->'middleterm_regeneration'=q.approval_payload
            AND h.event_payload->>'middleterm_regeneration_sha256'=q.approval_sha256
            AND q.approval_sha256=encode(sha256(convert_to(q.approval_payload::text,'UTF8')),'hex')
            AND q.approval_payload->>'schema'='synth.middleterm.regeneration.v1'
            AND q.approval_payload#>>'{scope,session_id}'=q.session_id
            AND q.approval_payload#>>'{scope,generation}'=q.generation::text
            AND q.approval_payload#>>'{scope,context_sequence}'=q.context_sequence::text
            AND q.approval_payload#>>'{scope,actor_identity_id}'=q.actor_identity_id::text
            AND q.approval_payload#>>'{scope,narrative_id}'=q.narrative_id::text
            AND q.approval_payload#>>'{scope,approval_token}'=q.approval_token
            AND q.approval_payload#>>'{scope,expected_revision}'=q.approval_payload#>>'{plan,target,control_revision}'
            AND q.approval_payload#>>'{scope,expected_plan_sha256}'=q.approval_payload#>>'{plan,plan_sha256}'
            AND q.approval_payload#>>'{plan,plan_sha256}'=encode(sha256(convert_to(((q.approval_payload->'plan')-'plan_sha256')::text,'UTF8')),'hex')
            AND q.approval_payload#>>'{plan,target,narrative_id}'=q.narrative_id::text
            AND q.approval_payload#>>'{plan,target,actor_identity_id}'=q.actor_identity_id::text
            AND q.approval_payload#>>'{plan,target,input_sha256}'=target.input_sha256
            AND q.approval_payload#>>'{plan,status}'='ready'".($savedScope?"
            AND EXISTS(SELECT 1 FROM native_history_scopes s WHERE s.session_id=q.session_id AND s.generation=q.generation
                AND s.playthrough_id=q.playthrough_id AND s.reader_identity_id=q.actor_identity_id AND s.cutoff>=q.history_event_id)":"")."
    ) ";
}

// Keep the existing NPC-over-profile opt-out authoritative for both confirmation and later worker admission.
function synth_middleterm_regeneration_enabled($db,int $actorId): bool
{
    $npc=$db->fetchOne('SELECT n.extended_data,p.metadata FROM synth.core_npc_master n
        LEFT JOIN synth.core_profiles p ON p.id=n.profile_id WHERE n.actor_identity_id='.$actorId);
    if (!$npc) return false;
    $extended=json_decode($npc['extended_data']??'{}',true,512,JSON_THROW_ON_ERROR);
    $metadata=json_decode($npc['metadata']??'{}',true,512,JSON_THROW_ON_ERROR);
    return synth_middleterm_enabled(['extended_data'=>is_array($extended)?$extended:[]],['metadata'=>is_array($metadata)?$metadata:[]]);
}

// Append approval and history together; replaying its token returns the same immutable receipt, never another intent.
function synth_middleterm_approve_regeneration($db,array $input): array
{
    $input=synth_middleterm_approval_input($input);
    return $db->withManagementTransaction(static function($connection) use($db,$input) {
        if (!@pg_query_params($connection,'SELECT pg_advisory_xact_lock(hashtext($1))',[$input['session_id']])
            || !@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-middleterm:'||$1,0))",[$input['actor_identity_id']])) {
            throw new RuntimeException('Unable to lock regeneration approval.');
        }
        $existing=@pg_query_params($connection,synth_middleterm_approval_cte()." SELECT q.history_event_id,
            q.approval_payload->'scope'=$4::jsonb AS same_scope FROM regeneration_receipts q
            WHERE q.session_id=$1 AND q.generation=$2 AND q.actor_identity_id=$3 AND q.approval_token=$5",
            [$input['session_id'],$input['generation'],$input['actor_identity_id'],json_encode($input,JSON_THROW_ON_ERROR),$input['approval_token']]);
        if (!$existing) throw new RuntimeException('Unable to check approval replay.');
        if ($row=pg_fetch_assoc($existing)) {
            if ($row['same_scope']!=='t') throw new LogicException('Confirmation token was already used for a different saved scope.');
            return ['approval_id'=>$row['history_event_id'],'availability'=>'recorded','replayed'=>true];
        }
        $result=@pg_query_params($connection,"SELECT c.*,r.runtime_variant FROM synth_meta.context_snapshots c
            JOIN synth_meta.runtime_sessions r USING(session_id)
            JOIN synth.actor_identities a ON a.id=$4 AND a.game='fo4' AND a.playthrough_id=c.playthrough_id
            WHERE c.session_id=$1 AND c.generation=$2 AND c.snapshot_sequence=$3
                AND r.state='active' AND r.active_generation=c.generation AND r.playthrough_id=c.playthrough_id
                AND c.history_event_id IS NOT NULL AND c.player_identity_id IS NOT NULL AND a.id<>c.player_identity_id",
            [$input['session_id'],$input['generation'],$input['context_sequence'],$input['actor_identity_id']]);
        if (!$result) throw new RuntimeException('Unable to read regeneration approval scope.');
        $scope=pg_fetch_assoc($result);
        if (!$scope) throw new OutOfBoundsException('Regeneration approval scope or actor is unavailable.');
        if (!synth_middleterm_regeneration_enabled($db,intval($input['actor_identity_id']))) {
            throw new LogicException('Narrative generation is disabled for this actor.');
        }
        $event=['protocol_version'=>2,'session_id'=>$scope['session_id'],'generation'=>intval($scope['generation']),
            'context_sequence'=>intval($scope['snapshot_sequence']),'runtime_variant'=>$scope['runtime_variant']];
        $plan=synth_middleterm_regeneration_plan($db,$event,intval($input['actor_identity_id']),intval($input['narrative_id']));
        if ($plan['status']!=='ready' || $plan['target']['control_revision']!==$input['expected_revision']
            || !hash_equals($plan['plan_sha256'],$input['expected_plan_sha256'])) {
            throw new LogicException('Regeneration plan changed or is unavailable. Refresh before confirming.');
        }
        $payload=json_encode(['schema'=>'synth.middleterm.regeneration.v1','scope'=>$input,'plan'=>$plan],JSON_THROW_ON_ERROR);
        if (strlen($payload)>1000000) throw new LengthException('Regeneration approval exceeds its storage bound.');
        $result=@pg_query_params($connection,"WITH value AS (SELECT $1::jsonb AS payload), publication AS (
            INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('middleterm_regeneration','Confirmed native narrative regeneration',$2,0,$3,$3,'','','','derived',$4,$5,$6,NULL) RETURNING *
        ), history AS (
            INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,NULL,$2,rowid,to_jsonb(publication)
                ||jsonb_build_object('middleterm_regeneration',value.payload,'middleterm_regeneration_sha256',
                    encode(sha256(convert_to(value.payload::text,'UTF8')),'hex')) FROM publication CROSS JOIN value RETURNING id
        ) INSERT INTO synth_meta.middleterm_regeneration_approvals(history_event_id,session_id,generation,context_sequence,
            actor_identity_id,playthrough_id,narrative_id,approval_token,approval_payload,approval_sha256)
            SELECT history.id,$4,$5,$6,$7,$2,$8,$9,value.payload,encode(sha256(convert_to(value.payload::text,'UTF8')),'hex')
            FROM history CROSS JOIN value RETURNING history_event_id",
            [$payload,$scope['playthrough_id'],time(),$scope['session_id'],$scope['generation'],$scope['snapshot_sequence'],
                $input['actor_identity_id'],$input['narrative_id'],$input['approval_token']]);
        if (!$result || !($saved=pg_fetch_assoc($result))) throw new RuntimeException('Unable to record regeneration approval.');
        return ['approval_id'=>$saved['history_event_id'],'availability'=>'awaiting_new_snapshot','replayed'=>false];
    });
}

// Recheck saved approval inside the caller's transaction; ready is eligibility, not a claim or inherited branch permission.
function synth_middleterm_approved_regeneration_plan($db,array $event,int $actorId,int $approvalId): array
{
    if ($actorId<1 || $approvalId<1) throw new InvalidArgumentException('Approved regeneration requires exact actor and approval IDs.');
    $result=$db->fetchOne(synth_middleterm_approval_cte()." SELECT q.*,c.history_event_id AS cutoff,
        EXISTS(SELECT 1 FROM synth_meta.middleterm_regeneration_approvals newer
            WHERE newer.session_id=q.session_id AND newer.generation=q.generation AND newer.actor_identity_id=q.actor_identity_id
                AND newer.narrative_id=q.narrative_id AND newer.history_event_id>q.history_event_id) AS superseded
        FROM regeneration_receipts q JOIN synth_meta.context_snapshots c ON c.session_id=q.session_id
            AND c.generation=q.generation AND c.playthrough_id=q.playthrough_id
        JOIN synth_meta.runtime_sessions r ON r.session_id=c.session_id AND r.active_generation=c.generation AND r.state='active'
            AND r.playthrough_id=c.playthrough_id
            AND r.runtime_variant=".$db->escapeLiteral($event['runtime_variant'])."
        WHERE q.history_event_id=$approvalId AND q.actor_identity_id=$actorId
            AND q.session_id=".$db->escapeLiteral($event['session_id']).' AND q.generation='.intval($event['generation'])
            .' AND c.snapshot_sequence='.intval($event['context_sequence']));
    if (!$result) throw new OutOfBoundsException('Approval does not belong to this active saved scope.');
    if ($result['superseded']==='t') return ['status'=>'superseded','approval_id'=>strval($approvalId)];
    if (intval($result['cutoff'])<$approvalId || intval($result['context_sequence'])>=intval($event['context_sequence'])) {
        return ['status'=>'awaiting_snapshot','approval_id'=>strval($approvalId)];
    }
    if (!synth_middleterm_regeneration_enabled($db,$actorId)) return ['status'=>'disabled','approval_id'=>strval($approvalId)];
    $plan=synth_middleterm_regeneration_plan($db,$event,$actorId,intval($result['narrative_id']));
    $same=$db->fetchOne('SELECT '. $db->escapeLiteral($result['approval_payload'])."::jsonb->'plan'="
        .$db->escapeLiteral(json_encode($plan,JSON_THROW_ON_ERROR)).'::jsonb AS matches');
    if (!$same) throw new RuntimeException('Unable to compare approved regeneration evidence.');
    if ($same['matches']!=='t') return ['status'=>'plan_changed','approval_id'=>strval($approvalId),'plan_status'=>$plan['status']];
    return ['status'=>'ready','approval_id'=>strval($approvalId),'plan'=>$plan];
}

// Owner changes do not reset a confirmation's three-attempt budget or its first two-minute execution deadline.
function synth_middleterm_regeneration_budget($db,int $approvalId): array
{
    if ($approvalId<1) throw new InvalidArgumentException('Regeneration budget requires an approval.');
    $row=$db->fetchOne("SELECT COALESCE(SUM(j.attempts),0) AS attempts,MIN(j.deadline_at) AS deadline_at,
        COALESCE(bool_or(j.state='failed'),false) AS failed,
        COALESCE(MIN(j.deadline_at)<=clock_timestamp(),false) AS expired,
        EXISTS(SELECT 1 FROM synth_meta.middleterm_replacements WHERE approval_id=$approvalId) AS published
        FROM synth_meta.middleterm_inputs i JOIN synth_meta.worker_jobs j ON j.middleterm_input_id=i.id AND j.job_type='middleterm'
        WHERE i.regeneration_approval_id=$approvalId");
    if (!$row) throw new RuntimeException('Unable to read regeneration attempt budget.');
    return ['state'=>$row['published']==='t'?'succeeded':($row['failed']==='t'?'failed':($row['expired']==='t'?'expired':
        (intval($row['attempts'])>=3?'exhausted':'ready'))),'attempts'=>intval($row['attempts']),'deadline_at'=>$row['deadline_at']];
}

// Report execution separately from saved visibility; never expose source text from an unacknowledged publication.
function synth_middleterm_regeneration_status($db,array $event,int $actorId,int $narrativeId,int $cutoff,array $plan): array
{
    $row=$db->fetchOne(synth_middleterm_approval_cte()." SELECT raw.history_event_id AS approval_id,
        valid.history_event_id IS NOT NULL AS valid,valid.context_sequence,valid.approval_token,
        valid.approval_payload#>>'{plan,plan_sha256}' AS plan_sha256,
        replacement.history_event_id AS publication_id,
        replacement.target_history_event_id=raw.narrative_id AND replacement.session_id=raw.session_id
            AND replacement.generation=raw.generation AND job.state='ready'
            AND EXISTS(SELECT 1 FROM synth_meta.middleterm_results result JOIN synth_meta.middleterm_inputs input ON input.id=result.input_id
                JOIN synth_meta.history_events history ON history.id=result.history_event_id AND history.session_id=input.session_id
                    AND history.generation=input.generation AND history.request_id=input.request_id AND history.context_sequence=input.context_sequence
                    AND history.playthrough_id=input.playthrough_id
                WHERE result.history_event_id=replacement.history_event_id AND input.id=job.middleterm_input_id
                    AND input.regeneration_approval_id=raw.history_event_id AND input.actor_identity_id=raw.actor_identity_id
                    AND input.session_id=raw.session_id AND input.generation=raw.generation AND input.playthrough_id=raw.playthrough_id
                    AND history.event_payload->>'middleterm_narrative'=result.narrative
                    AND history.event_payload->>'middleterm_result_sha256'=result.result_sha256
                    AND result.result_sha256=encode(sha256(convert_to(result.narrative,'UTF8')),'hex')) AS publication_recorded,
        job.state AS job_state,job.attempts AS job_attempts,job.lease_until>clock_timestamp() AS lease_live,
        job.deadline_at>clock_timestamp() AS deadline_live,
        session.active_turn_id=job.turn_id AND session.active_turn_sequence::text=job.payload->>'context_sequence'
            AND execution.state='complete' AS owner_live
        FROM synth_meta.middleterm_regeneration_approvals raw LEFT JOIN regeneration_receipts valid ON valid.history_event_id=raw.history_event_id
        LEFT JOIN synth_meta.middleterm_replacements replacement ON replacement.approval_id=raw.history_event_id
        LEFT JOIN LATERAL (SELECT j.* FROM synth_meta.middleterm_inputs i JOIN synth_meta.worker_jobs j ON j.middleterm_input_id=i.id
            WHERE i.regeneration_approval_id=raw.history_event_id AND j.job_type='middleterm' ORDER BY j.id DESC LIMIT 1) job ON true
        LEFT JOIN synth_meta.runtime_sessions session ON session.session_id=job.session_id AND session.active_generation=job.generation AND session.state='active'
        LEFT JOIN synth_meta.request_executions execution ON execution.session_id=job.session_id AND execution.request_id=job.request_id
            AND execution.generation=job.generation AND execution.turn_id=job.turn_id
        WHERE raw.session_id=".$db->escapeLiteral($event['session_id']).' AND raw.generation='.intval($event['generation'])
            ." AND raw.actor_identity_id=$actorId AND raw.narrative_id=$narrativeId ORDER BY raw.history_event_id DESC LIMIT 1");
    if (!$row) return ['state'=>'none','approval_id'=>null,'attempts'=>0];
    $status=['state'=>'unavailable','approval_id'=>$row['approval_id'],'attempts'=>0];
    if ($row['valid']!=='t') return $status;
    $budget=synth_middleterm_regeneration_budget($db,intval($row['approval_id']));
    $status+=['approval_token'=>$row['approval_token'],'deadline_at'=>$budget['deadline_at']];
    $status['attempts']=$budget['attempts'];
    if ($row['publication_id']!==null) {
        if ($row['publication_recorded']!=='t') return $status;
        // A receipt before the selected frontier is not a claim that this snapshot already contains its replacement.
        $status['state']=intval($row['publication_id'])>$cutoff?'published_awaiting_snapshot':'succeeded';
        if ($status['state']==='succeeded') {
            $visible=$db->fetchOne(synth_middleterm_history_cte($db,$event,'',$actorId)
                .' SELECT history_event_id FROM middleterm_owned WHERE history_event_id='.intval($row['publication_id']));
            if (!$visible) $status['state']='unavailable';
        }
        return $status;
    }
    // The third live attempt has used the admission budget, but has not failed or exhausted execution yet.
    if ($row['job_state']==='running' && $row['lease_live']==='t' && $row['deadline_live']==='t' && $row['owner_live']==='t') {
        $status['state']='running';return $status;
    }
    if ($budget['state']!=='ready') {$status['state']=$budget['state'];return $status;}
    if (intval($row['approval_id'])>$cutoff || intval($row['context_sequence'])>=intval($event['context_sequence'])) {
        $status['state']='awaiting_snapshot';return $status;
    }
    if (!synth_middleterm_regeneration_enabled($db,$actorId)) {$status['state']='disabled';return $status;}
    if ($plan['status']!=='ready' || !hash_equals($row['plan_sha256'],$plan['plan_sha256'])) {
        $status['state']='plan_changed';return $status;
    }
    $status['state']=$row['job_state']===null?'awaiting_admission':
        ($row['owner_live']!=='t' || $row['job_state']==='stale'?'recovering':
            ($row['job_state']==='running' && $row['lease_live']==='t'?'running':
                (intval($row['job_attempts'])>0?'retrying':'pending')));
    return $status;
}
