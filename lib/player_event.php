<?php

// Typed sampled facts only; a transition cannot contain narration, an action, or a claimed witness.
function synthValidatePlayerEvent($event): array
{
    if (!is_array($event) || array_is_list($event)
        || count($event)!==6 || array_diff(array_keys($event),['event_id','kind','before','after','interval_ms','age_ms'])
        || !is_string($event['event_id'] ?? null) || !preg_match('/^player:[1-9][0-9]{0,15}$/D',$event['event_id'])
        || intval(substr($event['event_id'],7))>9007199254740991
        || !in_array($event['kind'] ?? '',['level_up','combat_end'],true)) {
        throw new DomainException('invalid_player_event');
    }
    foreach (['interval_ms'=>1,'age_ms'=>0] as $field=>$minimum) {
        if (!is_int($event[$field]) || $event[$field]<$minimum || $event[$field]>9007199254740991)
            throw new DomainException('invalid_player_event_time');
    }
    foreach (['before','after'] as $field) {
        $state=$event[$field];
        if (!is_array($state) || array_is_list($state) || count($state)!==3
            || array_diff(array_keys($state),['game_time_ticks','level','in_combat'])
            || !is_int($state['game_time_ticks'] ?? null) || $state['game_time_ticks']<1 || $state['game_time_ticks']>9007199254740991
            || !is_int($state['level'] ?? null) || $state['level']<1 || $state['level']>32767
            || !is_bool($state['in_combat'] ?? null)) throw new DomainException('invalid_player_event_state');
    }
    $before=$event['before'];$after=$event['after'];
    if ($after['game_time_ticks']<$before['game_time_ticks'] || $after['level']<$before['level']
        || ($event['kind']==='level_up' ? $after['level']<=$before['level'] : (!$before['in_combat'] || $after['in_combat'])))
        throw new DomainException('unproven_player_transition');
    return $event;
}

// Runs inside context admission's session lock/transaction; duplicate delivery never moves its original save frontier.
function synthPersistPlayerEvent($db, array $event): void
{
    $payload=$event['payload'];
    if (!isset($payload['player_event'])) return;
    if (($event['protocol_version'] ?? 1)!==2 || ($event['runtime_variant'] ?? '')!=='flat'
        || !in_array('context.player_events',$event['capabilities'] ?? [],true)) throw new DomainException('player_events_capability_required');
    $transition=synthValidatePlayerEvent($payload['player_event']);
    $player=array_intersect_key($payload['player'],array_flip(['form_id','origin_plugin','playthrough_id']));
    $input=$transition;unset($input['age_ms']);
    $owner=[$event['session_id'],$event['generation'],$payload['playthrough_id']];
    $result=$db->execQuery("SELECT (event_payload->>'party')::jsonb AS party FROM synth_meta.history_events
        WHERE session_id=$1 AND generation=$2 AND playthrough_id=$3 AND event_payload->>'type'='player_event'
          AND (event_payload->>'party')::jsonb->>'event_id'=$4 LIMIT 1",[...$owner,$transition['event_id']]);
    if ($result===false) throw new RuntimeException('player_event_history_read_failed');
    if ($row=pg_fetch_assoc($result)) {
        $previous=json_decode($row['party'],true,flags:JSON_THROW_ON_ERROR);
        if (($previous['player'] ?? null)!=$player || ($previous['input_event'] ?? null)!=$input)
            throw new DomainException('player_event_conflict');
        return;
    }
    $observed=null;
    foreach ($payload['actor_states'] ?? [] as $state) {
        $identity=array_intersect_key($state['actor'] ?? [],$player);
        if ($identity==$player) {$observed=$state;break;}
    }
    if (!$observed || $payload['player']['playthrough_id']!==$payload['playthrough_id']
        || intval($payload['world']['game_time_ticks'] ?? 0)<$transition['after']['game_time_ticks']
        || intval($observed['level'] ?? 0)<$transition['after']['level']) throw new DomainException('player_event_context_unproven');
    $name=str_replace('|',' ',trim($payload['player']['display_name']));
    $data=$transition['kind']==='level_up'
        ? $name.' reached level '.$transition['after']['level'].' (previous observed level '.$transition['before']['level'].').'
        : 'Combat ended near '.$name.'. This observation does not establish victory, kills, enemies or weapons.';
    $metadata=['schema'=>'synth.player.event.v1','event_id'=>$transition['event_id'],'player'=>$player,
        'input_event'=>$input,'capture_age_ms'=>$transition['age_ms'],'snapshot_sequence'=>$payload['snapshot_sequence']];
    synth_protocol_persist_observation_history($db,$event,'player_event',$data,'|'.$name.'|','',$metadata,$transition['after']['game_time_ticks']);
}
