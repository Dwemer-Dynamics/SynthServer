<?php

require_once __DIR__.'/synth_middleterm.php';

// Build replacement evidence for one saved record; callers still need durable approval and a fresh native worker owner.
function synth_middleterm_regeneration_plan($db,array $event,int $actorId,int $narrativeId): array
{
    if ($actorId<1 || $narrativeId<1) throw new InvalidArgumentException('Regeneration requires exact saved actor and narrative IDs.');
    $cte=synth_middleterm_history_cte($db,$event,'',$actorId);
    $target=$db->fetchOne($cte." SELECT i.history_event_id,i.input_sha256,i.control_revision,i.content_revision,
        i.archived,i.control_unavailable,i.prior_history_event_id,i.input_payload,
        EXISTS(SELECT 1 FROM middleterm_archived_lineage blocked WHERE blocked.history_event_id=i.history_event_id) AS suppressed,
        EXISTS(SELECT 1 FROM middleterm_replacement_fences replaced WHERE replaced.target_history_event_id=i.history_event_id) AS replaced,
        p.history_event_id AS prior_id,p.effective_narrative AS prior_narrative,p.content_revision AS prior_content_revision
        FROM middleterm_effective i LEFT JOIN middleterm_resolved_replacements resolved ON resolved.original_id=i.prior_history_event_id
        LEFT JOIN native_middleterms p ON p.history_event_id=resolved.current_id
        WHERE i.history_event_id=$narrativeId");
    if (!$target) throw new OutOfBoundsException('Regeneration target is not visible in this saved scope.');
    $identity=['narrative_id'=>strval($narrativeId),'actor_identity_id'=>strval($actorId),
        'input_sha256'=>$target['input_sha256'],'control_revision'=>$target['control_revision'],'content_revision'=>$target['content_revision']];
    if ($target['control_unavailable']==='t') return ['status'=>'control_unavailable','target'=>$identity];
    if ($target['replaced']==='t') return ['status'=>'replaced','target'=>$identity];
    if ($target['archived']==='t') return ['status'=>'archived','target'=>$identity];
    if ($target['suppressed']==='t') return ['status'=>'ancestor_suppressed','target'=>$identity];
    if (synth_middleterm_has_unsaved_control($db,$event,$actorId)) return ['status'=>'awaiting_snapshot','target'=>$identity];
    if ($target['prior_history_event_id']!==null && $target['prior_id']===null) return ['status'=>'prior_unavailable','target'=>$identity];
    $frozen=json_decode($target['input_payload'],true,512,JSON_THROW_ON_ERROR);
    $priorId=$frozen['prior']['history_event_id']??null;
    if (($priorId===null)!==($target['prior_history_event_id']===null)
        || ($priorId!==null && strval($priorId)!==$target['prior_history_event_id'])) {
        return ['status'=>'target_integrity_unavailable','target'=>$identity];
    }
    $prior=$target['prior_id']===null?null:['history_event_id'=>intval($target['prior_id']),'narrative'=>$target['prior_narrative']]
        +(intval($target['prior_content_revision'])>0?['content_revision'=>intval($target['prior_content_revision'])]:[]);
    // Only validated replacements of the originally pinned families qualify; never add later unrelated summaries.
    $rows=$db->fetchAll($cte." SELECT d.position,current.id,current.summary_revision,current.summary,b.last_gamets,
            family.root_id AS source_family_id
        FROM middleterm_effective i JOIN synth_meta.middleterm_sources d ON d.input_id=i.input_id
        JOIN synth_meta.memory_summaries original ON original.id=d.summary_id
        JOIN synth_meta.memory_batches original_batch ON original_batch.id=original.batch_id
            AND original_batch.actor_identity_id=i.actor_identity_id AND original_batch.playthrough_id=i.playthrough_id
        JOIN native_memory_batch_families family ON family.id=original.batch_id
        JOIN native_memory_latest_batches latest ON latest.root_id=family.root_id
        JOIN native_memory_batches b ON b.id=latest.id AND b.actor_identity_id=i.actor_identity_id AND b.playthrough_id=i.playthrough_id
        JOIN native_memory_effective_summaries current ON current.batch_id=b.id
        LEFT JOIN synth_meta.memory_summary_revisions pinned ON pinned.summary_id=original.id AND pinned.revision=d.summary_revision
        WHERE i.history_event_id=$narrativeId
            AND i.input_payload->'sources'->d.position->>'id'=d.summary_id::text
            AND i.input_payload->'sources'->d.position->>'revision'=d.summary_revision::text
            AND i.input_payload->'sources'->d.position->>'gamets'=original_batch.last_gamets::text
            AND i.input_payload->'sources'->d.position->>'summary'=CASE WHEN d.summary_revision=0 THEN original.summary ELSE pinned.summary END
            AND d.source_sha256=encode(sha256(convert_to(CASE WHEN d.summary_revision=0 THEN original.summary ELSE pinned.summary END,'UTF8')),'hex')
        ORDER BY d.position");
    $count=count($frozen['sources']);
    if (count($rows)!==$count) return ['status'=>'sources_unavailable','target'=>$identity];
    $families=[];
    foreach ($rows as $position=>$row) {
        if (intval($row['position'])!==$position || in_array($row['source_family_id'],$families,true)) {
            return ['status'=>'target_integrity_unavailable','target'=>$identity];
        }
        $families[]=$row['source_family_id'];
    }
    $sources=synth_middleterm_select_sources($rows,$prior);
    if (count($sources)!==$count) return ['status'=>'source_bound_exceeded','target'=>$identity];
    $plan=['status'=>'ready','target'=>$identity,'prior'=>$prior,'source_family_ids'=>$families,'sources'=>$sources];
    // Bind confirmation to what was reviewed, including source/prior changes that do not alter the target control revision.
    $digest=$db->fetchOne('SELECT encode(sha256(convert_to(('.$db->escapeLiteral(json_encode($plan,JSON_THROW_ON_ERROR))
        ."::jsonb)::text,'UTF8')),'hex') AS digest");
    if (!$digest) throw new RuntimeException('Unable to fingerprint regeneration plan.');
    return $plan+['plan_sha256'=>$digest['digest']];
}
