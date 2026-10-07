<?php

require_once __DIR__.'/synth_protocol_state.php';

// Called before profile loading with bootstrap settings, never with client/model controls.
function synth_dialogue_origin_policy(array $settings, string $executionMode): array
{
    $executionMode=strtoupper(trim($executionMode));
    if (!in_array($executionMode,['STANDARD','WHISPER','CLOSE','SHOUT','NARRATOR','DIRECTOR',
        'INJECTION_LOG','INJECTION_CHAT','CHEATMODE','AUTOCHAT'],true)) throw new InvalidArgumentException('dialogue_mode_invalid');
    $policy=['private'=>in_array($executionMode,['WHISPER','CLOSE'],true)];
    foreach (['RECHAT_H'=>'rounds','RECHAT_P'=>'probability','RANDOM_NARATION_CHANCE'=>'narration_chance',
        'RANDOM_NARRATION_COOLDOWN'=>'narration_cooldown'] as $key=>$field) {
        if (!is_scalar($settings[$key] ?? null) || !is_numeric($settings[$key])) throw new InvalidArgumentException('dialogue_policy_invalid');
        $policy[$field]=intval($settings[$key]);
    }
    foreach (['RECHAT_ALLOW_ACTIONS'=>'allow_actions','RANDOM_NARATION'=>'random_narration'] as $key=>$field) {
        if (!array_key_exists($key,$settings)) throw new InvalidArgumentException('dialogue_policy_invalid');
        $policy[$field]=filter_var($settings[$key],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if ($policy[$field]===null) throw new InvalidArgumentException('dialogue_policy_invalid');
    }
    $policy['mode']=strtolower(trim(strval($settings['RECHAT_MODE'] ?? '')));
    if (!in_array($policy['mode'],['tight','conversational','group','random'],true)) throw new InvalidArgumentException('dialogue_policy_invalid');
    return $policy;
}

// Audio text comes only from server transcription; typed input remains exactly the canonical client text.
function synth_dialogue_origin_input(array $event, ?string $transcript = null): ?string
{
    $type=$event['type'] ?? '';
    if (!in_array($type,['input_text','input_audio'],true)) return null;
    $text=$type==='input_audio' ? $transcript : ($event['payload']['text'] ?? null);
    if (!is_string($text) || $text==='' || !mb_check_encoding($text,'UTF-8') || str_contains($text,"\0")
        || mb_strlen($text,'UTF-8')>4096
        || ($type==='input_audio' && (trim($text)==='' || strlen($text)>4096))) {
        throw new InvalidArgumentException('dialogue_origin_input_invalid');
    }
    return $text;
}

// Freeze the originating input and mode/defaults; retries cannot replace them with later derived state.
function synth_dialogue_capture_origin_policy($db, array $event, array $settings, string $executionMode, ?string $transcript = null): void
{
    if (!synth_protocol_owns_dialogue_turn($event) || ($event['type'] ?? '')==='action_result'
        || (($event['type'] ?? '')==='trigger' && ($event['payload']['kind'] ?? '')==='rechat')) return;
    $policy=synth_dialogue_origin_policy($settings,$executionMode);
    $inputText=synth_dialogue_origin_input($event,$transcript);
    $db->withNativeRequestLock(static function($connection,array $owner) use($event,$policy,$executionMode,$inputText) {
        if ($owner!==$event) throw new LogicException('dialogue_policy_owner_changed');
        $canonical=synth_protocol_canonical_json($event);
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.dialogue_origin_policies(session_id,request_id,execution_mode,policy,input_text)
            SELECT e.session_id,e.request_id,$3,$4::jsonb,$9 FROM synth_meta.request_executions e
            JOIN synth_meta.protocol_events p USING(session_id,request_id)
            WHERE e.session_id=$1 AND e.request_id=$2 AND e.state IN ('claimed','streaming')
                AND p.canonical_event=$5::jsonb AND p.event_sha256=$6 AND e.request_sha256=p.event_sha256
                AND e.generation=$7 AND p.generation=e.generation AND e.turn_id=$8 AND p.turn_id=e.turn_id
                AND e.event_type=p.event_type
            ON CONFLICT(session_id,request_id) DO NOTHING",
            [$event['session_id'],$event['request_id'],strtoupper(trim($executionMode)),json_encode($policy,JSON_THROW_ON_ERROR),
             $canonical,hash('sha256',$canonical),$event['generation'],$event['turn_id'],$inputText]);
        if (!$result) throw new RuntimeException('dialogue_policy_capture_failed');
        // A no-op is valid only for an already captured, identical canonical live request.
        $result=@pg_query_params($connection,"SELECT 1 FROM synth_meta.dialogue_origin_policies d
            JOIN synth_meta.protocol_events p USING(session_id,request_id)
            JOIN synth_meta.request_executions e USING(session_id,request_id)
            WHERE d.session_id=$1 AND d.request_id=$2 AND p.canonical_event=$3::jsonb
                AND p.event_sha256=$4 AND e.request_sha256=p.event_sha256 AND e.state IN ('claimed','streaming')
                AND e.generation=$5 AND p.generation=e.generation AND e.turn_id=$6 AND p.turn_id=e.turn_id
                AND e.event_type=p.event_type",
            [$event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical),$event['generation'],$event['turn_id']]);
        if (!$result || !pg_fetch_assoc($result)) throw new LogicException('dialogue_policy_canonical_owner_unavailable');
    },2000);
}
