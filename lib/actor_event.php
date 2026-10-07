<?php

// Closed scalar contract. Source evidence cannot include prose, actions, inventory counts or witness claims.
function synthValidateActorEvents($batch): array
{
    $keys=static function($value,array $expected): bool {
        return is_array($value) && !array_is_list($value) && count($value)===count($expected)
            && !array_diff(array_keys($value),$expected);
    };
    $form=static fn($value): bool => is_string($value) && preg_match('/^0x[0-9A-Fa-f]{8}$/D',$value)
        && !in_array(strtoupper($value),['0X00000000','0XFFFFFFFF'],true);
    $plugin=static fn($value): bool => is_string($value) && strlen($value)<=255
        && !str_contains($value,"\0") && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#D',$value);
    $identity=static function($value)use($keys,$form,$plugin): bool {
        return $keys($value,['form_id','origin_plugin','playthrough_id']) && $form($value['form_id'])
            && $plugin($value['origin_plugin']) && is_string($value['playthrough_id'])
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$value['playthrough_id']);
    };
    if (!$keys($batch,['batch_id','age_ms','events']) || !is_string($batch['batch_id'])
        || !preg_match('/^actor:[1-9][0-9]{0,15}$/D',$batch['batch_id']) || intval(substr($batch['batch_id'],6))>9007199254740991
        || !is_int($batch['age_ms']) || $batch['age_ms']<0 || $batch['age_ms']>9007199254740991
        || !is_array($batch['events']) || !array_is_list($batch['events']) || count($batch['events'])<1 || count($batch['events'])>32)
        throw new DomainException('invalid_actor_events');
    foreach ($batch['events'] as $record) {
        if (!$keys($record,['kind','actor','other_form_id','other_actor','item','original_reference','unique_id','capture_delay_ms','player_knowledge'])
            || !in_array($record['kind'],['death','equipped','unequipped'],true) || !$identity($record['actor'])
            || ($record['other_form_id']!==null && !$form($record['other_form_id']))
            || ($record['other_actor']!==null && (!$identity($record['other_actor'])
                || strcasecmp($record['other_actor']['form_id'],$record['other_form_id']??'')!==0
                || $record['other_actor']['playthrough_id']!==$record['actor']['playthrough_id']))
            || ($record['original_reference']!==null && !$form($record['original_reference']))
            || !is_int($record['unique_id']) || $record['unique_id']<0 || $record['unique_id']>65535
            || !is_int($record['capture_delay_ms']) || $record['capture_delay_ms']<0 || $record['capture_delay_ms']>2000
            || !in_array($record['player_knowledge'],['unavailable','player_equipment'],true))
            throw new DomainException('invalid_actor_event');
        if ($record['kind']==='death') {
            if ($record['item']!==null || $record['original_reference']!==null || $record['unique_id']!==0
                || $record['player_knowledge']!=='unavailable') throw new DomainException('invalid_actor_death');
        } else {
            $item=$record['item'];
            if ($record['other_form_id']!==null || $record['other_actor']!==null
                || !$keys($item,['form_id','origin_plugin','display_name','form_type']) || !$form($item['form_id'])
                || !$plugin($item['origin_plugin']) || !is_string($item['display_name']) || trim($item['display_name'])===''
                || strlen($item['display_name'])>255 || !is_int($item['form_type']) || $item['form_type']<1 || $item['form_type']>255)
                throw new DomainException('invalid_actor_equipment');
        }
    }
    return $batch;
}

// Recheck identity against this owned context; only self equip/unequip can become player knowledge.
function synthBindActorEvents(array $batch,array $payload): array
{
    $identity=static fn(array $actor): array => array_intersect_key($actor,array_flip(['form_id','origin_plugin','playthrough_id']));
    $same=static fn(array $a,array $b): bool => strcasecmp($a['form_id'],$b['form_id'])===0
        && strcasecmp($a['origin_plugin'],$b['origin_plugin'])===0 && $a['playthrough_id']===$b['playthrough_id'];
    $player=$identity($payload['player']);
    $resolve=static function(array $actor)use($payload,$same): array {
        if ($actor['playthrough_id']!==$payload['playthrough_id']) throw new DomainException('actor_event_owner_mismatch');
        $matches=[];
        foreach ($payload['actor_states']??[] as $state) if ($same($actor,$state['actor'])) $matches[]=$state;
        if (count($matches)!==1 || $matches[0]['disabled']) throw new DomainException('actor_event_context_unproven');
        return $matches[0];
    };
    if ($player['playthrough_id']!==$payload['playthrough_id'] || strtoupper($player['form_id'])!=='0X00000014'
        || intval($payload['world']['game_time_ticks']??0)<1) throw new DomainException('actor_event_owner_mismatch');
    $known=[];
    foreach ($batch['events'] as $index=>$record) {
        $state=$resolve($record['actor']);
        if ($record['other_actor']!==null) $resolve($record['other_actor']);
        $self=$same($player,$record['actor']) && $record['kind']!=='death';
        if (($record['player_knowledge']==='player_equipment')!==$self) throw new DomainException('actor_event_knowledge_unproven');
        if ($self) $known[]=['index'=>$index,'event'=>$record,'subject_name'=>$state['actor']['display_name']??$payload['player']['display_name']];
    }
    return $known;
}

// Context admission holds the session transaction lock. Raw source batches never enter eventlog or NPC memory.
function synthPersistActorEvents($db,array $event): void
{
    $payload=$event['payload'];
    if (!isset($payload['actor_events'])) return;
    if (($event['protocol_version']??1)!==2 || ($event['runtime_variant']??'')!=='flat'
        || !in_array('context.actor_events',$event['capabilities']??[],true)) throw new DomainException('actor_events_capability_required');
    $batch=synthValidateActorEvents($payload['actor_events']);
    $input=$batch;unset($input['age_ms']);
    $player=array_intersect_key($payload['player'],array_flip(['form_id','origin_plugin','playthrough_id']));
    $owner=[$event['session_id'],$event['generation'],$payload['playthrough_id'],$batch['batch_id']];
    $result=$db->execQuery("SELECT b.player_payload,b.input_payload FROM synth_meta.actor_event_batches b
        JOIN synth_meta.runtime_sessions s ON s.session_id=b.session_id
        WHERE b.session_id=$1 AND b.generation=$2 AND b.playthrough_id=$3 AND b.batch_id=$4
          AND s.active_generation=b.generation AND s.playthrough_id=b.playthrough_id AND s.state='active'",$owner);
    if ($result===false) throw new RuntimeException('actor_event_batch_read_failed');
    if ($row=pg_fetch_assoc($result)) {
        // JSONB reorders object keys; canonicalize keys, never coerce scalar types or numeric-looking strings.
        if (synth_protocol_sort_keys(json_decode($row['player_payload'],true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_sort_keys($player)
            || synth_protocol_sort_keys(json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_sort_keys($input))
            throw new DomainException('actor_event_batch_conflict');
        return; // A retry never changes the original context, age or knowledge projection.
    }
    $known=synthBindActorEvents($batch,$payload);
    $result=$db->execQuery("INSERT INTO synth_meta.actor_event_batches
        (session_id,generation,playthrough_id,batch_id,context_sequence,request_id,player_payload,input_payload,capture_age_ms)
        SELECT c.session_id,c.generation,c.playthrough_id,$4,c.snapshot_sequence,$6,$7::jsonb,$8::jsonb,$9
          FROM synth_meta.context_snapshots c JOIN synth_meta.runtime_sessions s USING(session_id)
         WHERE c.session_id=$1 AND c.generation=$2 AND c.playthrough_id=$3 AND c.snapshot_sequence=$5
           AND s.active_generation=c.generation AND s.playthrough_id=c.playthrough_id AND s.state='active' RETURNING batch_id",
        [...$owner,$payload['snapshot_sequence'],$event['request_id'],json_encode($player,JSON_THROW_ON_ERROR),
            json_encode($input,JSON_THROW_ON_ERROR),$batch['age_ms']]);
    if ($result===false || pg_fetch_assoc($result)===false) throw new RuntimeException('actor_event_batch_insert_failed');
    if (!$known) return;
    $descriptions=[];
    foreach ($known as $record) $descriptions[]=$record['subject_name'].' '.$record['event']['kind'].' '.$record['event']['item']['display_name'];
    $metadata=['schema'=>'synth.actor.equipment.v1','batch_id'=>$batch['batch_id'],'player'=>$player,'events'=>$known,
        'capture_age_ms'=>$batch['age_ms'],'snapshot_sequence'=>$payload['snapshot_sequence']];
    $name=str_replace('|',' ',trim($payload['player']['display_name']));
    synth_protocol_persist_observation_history($db,$event,'actor_equipment',implode('; ',$descriptions).
        '. Native equip/unequip observations; item labels describe base forms. Inventory counts, consumption and NPC witnesses are not established.',
        '|'.$name.'|','',$metadata,$payload['world']['game_time_ticks']);
}
