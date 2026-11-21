<?php

require_once __DIR__.'/synth_middleterm.php';

// Build replacement evidence for one saved record; callers still need durable approval and a fresh native worker owner.
function synth_middleterm_regeneration_plan($db,array $event,int $actorId,int $narrativeId): array
{
    if ($actorId<1 || $narrativeId<1) throw new InvalidArgumentException('Regeneration requires exact saved actor and narrative IDs.');
    $cte=synth_middleterm_history_cte($db,$event,'',$actorId);
    $target=\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->regenerationTarget($cte,$narrativeId);
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
    $rows=\Synth\Core\DbManager\Dao\MiddletermSourceDao::current($db)->regenerationSources($cte,$narrativeId);
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
    $digest=\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->planDigest(json_encode($plan,JSON_THROW_ON_ERROR));
    if ($digest===null) throw new RuntimeException('Unable to fingerprint regeneration plan.');
    return $plan+['plan_sha256'=>$digest];
}
