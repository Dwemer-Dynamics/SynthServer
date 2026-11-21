<?php

require_once dirname(__DIR__, 3).'/lib'.'/synth_protocol_state.php';

// Called before profile loading with bootstrap settings, never with client/model controls.
function synth_dialogue_origin_policy(array $settings, string $executionMode): array
{
    $executionMode=strtoupper(trim($executionMode));
    if (!in_array($executionMode,['STANDARD','WHISPER','CLOSE','SHOUT','NARRATOR','DIRECTOR',
        'INJECTION_LOG','INJECTION_CHAT','CHEATMODE','AUTOCHAT'],true)) throw new InvalidArgumentException('dialogue_mode_invalid');
    // Close is a captured small group: bystanders may hear and continue within the configured budget.
    $policy=['private'=>$executionMode==='WHISPER'];
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
    // Narration would speak to listeners outside the captured hearing group.
    if (in_array($executionMode,['WHISPER','CLOSE'],true)) $policy['random_narration']=false;
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
    // Like Close, an Everyone turn keeps its continuations inside the captured group, so no random narrator.
    require_once __DIR__.'/synth_control.php';
    if (synth_control_everyone_input($event)) $policy['random_narration']=false;
    $inputText=synth_dialogue_origin_input($event,$transcript);
    $db->withNativeRequestLock(static function($connection,array $owner) use($event,$policy,$executionMode,$inputText) {
        if ($owner!==$event) throw new LogicException('dialogue_policy_owner_changed');
        $canonical=synth_protocol_canonical_json($event);
        $policies=\Synth\Core\DbManager\Dao\DialogueOriginPolicyDao::current();
        if (!$policies->captureForClaim(strval($event['session_id']),strval($event['request_id']),strtoupper(trim($executionMode)),json_encode($policy,JSON_THROW_ON_ERROR),
            $canonical,hash('sha256',$canonical),intval($event['generation']),strval($event['turn_id']),$inputText)) throw new RuntimeException('dialogue_policy_capture_failed');
        // A no-op is valid only for an already captured, identical canonical live request.
        if (!$policies->isCapturedForClaim(strval($event['session_id']),strval($event['request_id']),$canonical,hash('sha256',$canonical),intval($event['generation']),strval($event['turn_id'])))
            throw new LogicException('dialogue_policy_canonical_owner_unavailable');
    },2000);
}
