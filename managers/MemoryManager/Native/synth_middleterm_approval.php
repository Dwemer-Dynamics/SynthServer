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
        SELECT q.* FROM public.core_middleterm_regeneration_approval q
        JOIN public.core_history_event h ON h.id=q.history_event_id AND h.session_id=q.session_id
            AND h.generation=q.generation AND h.context_sequence=q.context_sequence AND h.playthrough_id=q.playthrough_id
        JOIN public.core_runtime_context origin ON origin.session_id=q.session_id AND origin.generation=q.generation
            AND origin.snapshot_sequence=q.context_sequence AND origin.playthrough_id=q.playthrough_id
        JOIN public.core_middleterm_result result ON result.history_event_id=q.narrative_id
        JOIN public.core_middleterm_input target ON target.id=result.input_id
            AND target.actor_identity_id=q.actor_identity_id AND target.playthrough_id=q.playthrough_id
        JOIN public.core_actor_identity actor ON actor.id=q.actor_identity_id AND actor.playthrough_id=q.playthrough_id AND actor.game='fo4'
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
    $npc=\Synth\Core\DbManager\Dao\NpcDao::current($db)->extendedDataWithProfileMetadata($actorId);
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
        if (!\Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao::current($db)->lockSession($input['session_id'])
            || !\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->lockActor($input['actor_identity_id'])) {
            throw new RuntimeException('Unable to lock regeneration approval.');
        }
        $row=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao::current($db)->findReplay(synth_middleterm_approval_cte(),$input['session_id'],
            $input['generation'],$input['actor_identity_id'],json_encode($input,JSON_THROW_ON_ERROR),$input['approval_token']),'Unable to check approval replay.');
        if ($row) {
            if ($row['same_scope']!=='t') throw new LogicException('Confirmation token was already used for a different saved scope.');
            return ['approval_id'=>$row['history_event_id'],'availability'=>'recorded','replayed'=>true];
        }
        $scope=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\RuntimeContextDao::current($db)->approvalScope($input['session_id'],$input['generation'],
            $input['context_sequence'],$input['actor_identity_id']),'Unable to read regeneration approval scope.');
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
        $saved=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao::current($db)->record($payload,$scope['playthrough_id'],time(),
            $scope['session_id'],$scope['generation'],$scope['snapshot_sequence'],$input['actor_identity_id'],$input['narrative_id'],$input['approval_token']),
            'Unable to record regeneration approval.');
        if ($saved===null) throw new RuntimeException('Unable to record regeneration approval.');
        return ['approval_id'=>$saved,'availability'=>'awaiting_new_snapshot','replayed'=>false];
    });
}

// Recheck saved approval inside the caller's transaction; ready is eligibility, not a claim or inherited branch permission.
function synth_middleterm_approved_regeneration_plan($db,array $event,int $actorId,int $approvalId): array
{
    if ($actorId<1 || $approvalId<1) throw new InvalidArgumentException('Approved regeneration requires exact actor and approval IDs.');
    $result=\Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao::current($db)->approvedReceipt(synth_middleterm_approval_cte(),$approvalId,$actorId,
        strval($event['runtime_variant']),strval($event['session_id']),intval($event['generation']),intval($event['context_sequence']));
    if (!$result) throw new OutOfBoundsException('Approval does not belong to this active saved scope.');
    if ($result['superseded']==='t') return ['status'=>'superseded','approval_id'=>strval($approvalId)];
    if (intval($result['cutoff'])<$approvalId || intval($result['context_sequence'])>=intval($event['context_sequence'])) {
        return ['status'=>'awaiting_snapshot','approval_id'=>strval($approvalId)];
    }
    if (!synth_middleterm_regeneration_enabled($db,$actorId)) return ['status'=>'disabled','approval_id'=>strval($approvalId)];
    $plan=synth_middleterm_regeneration_plan($db,$event,$actorId,intval($result['narrative_id']));
    $same=\Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao::current($db)->planMatches(strval($result['approval_payload']),json_encode($plan,JSON_THROW_ON_ERROR));
    if ($same===null) throw new RuntimeException('Unable to compare approved regeneration evidence.');
    if (!$same) return ['status'=>'plan_changed','approval_id'=>strval($approvalId),'plan_status'=>$plan['status']];
    return ['status'=>'ready','approval_id'=>strval($approvalId),'plan'=>$plan];
}

// Owner changes do not reset a confirmation's three-attempt budget or its first two-minute execution deadline.
function synth_middleterm_regeneration_budget($db,int $approvalId): array
{
    if ($approvalId<1) throw new InvalidArgumentException('Regeneration budget requires an approval.');
    $row=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->middletermBudget($approvalId);
    if (!$row) throw new RuntimeException('Unable to read regeneration attempt budget.');
    return ['state'=>$row['published']==='t'?'succeeded':($row['failed']==='t'?'failed':($row['expired']==='t'?'expired':
        (intval($row['attempts'])>=3?'exhausted':'ready'))),'attempts'=>intval($row['attempts']),'deadline_at'=>$row['deadline_at']];
}

// Report execution separately from saved visibility; never expose source text from an unacknowledged publication.
function synth_middleterm_regeneration_status($db,array $event,int $actorId,int $narrativeId,int $cutoff,array $plan): array
{
    $row=\Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao::current($db)->statusRow(synth_middleterm_approval_cte(),strval($event['session_id']),
        intval($event['generation']),$actorId,$narrativeId);
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
            $visible=\Synth\Core\DbManager\Dao\MiddletermResultDao::current($db)->ownedVisible(synth_middleterm_history_cte($db,$event,'',$actorId),intval($row['publication_id']));
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
