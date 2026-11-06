<?php declare(strict_types=1);
// HTTP-only disposable integration fixture. No main/LLM/provider test hooks are installed.
if (PHP_SAPI!=='cli-server' || getenv('PHPUNIT_TEST') || getenv('SYNTH_DB_NAME')!=='testdb') {
    http_response_code(503);exit;
}
ini_set('display_errors','0');error_reporting(E_ALL);
$root=dirname(__DIR__,2);
require_once $root.'/lib/postgresql.class.php';
require_once $root.'/lib/request.php';
require_once $root.'/lib/response.php';
require_once $root.'/lib/player_tts_helpers.php';

try {
    $normalized=synth_decode_event_from_request();
    $GLOBALS['SYNTH_REQUEST_EVENT']=$normalized;
    $GLOBALS['SYNTH_RESPONSE_FORMAT']='json';
    $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=$normalized['json']['capabilities'];
    $GLOBALS['db']=$db=new sql();
    $event=$normalized['json'];
    $claim=synth_protocol_claim_request($db,$event);
    // Clear include-file whitespace, as the web pipeline does before emitting JSON.
    while(ob_get_level()>0)ob_end_clean();
    if(($claim['status']??'')==='replay'){synth_protocol_emit_replay($claim);exit;}
    if(($claim['status']??'')==='pending')synth_reject_json_request(409,'Fixture request pending',['code'=>'request_pending']);
    $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
    if(in_array($event['type'],['init','context','cancel','halt'],true)){
        synth_protocol_emit_ack($event['type'].'_accepted');exit;
    }
    if($event['type']!=='input_text')throw new LogicException('fixture_requires_typed_input');
    $gameRequest=synth_event_to_game_request($normalized);
    $GLOBALS['gameRequest']=&$gameRequest;
    synth_protocol_bind_dialogue_context($db,$event,$gameRequest);
    $context=synth_protocol_current_context($db,$event);
    $GLOBALS['SYNTH_NAME']=$context['target']['display_name'];
    $GLOBALS['PLAYER_NAME']=$context['player']['display_name'];
    $GLOBALS['IS_NPC']=false;
    $GLOBALS['FUNCTIONS_ARE_ENABLED']=true;
    $GLOBALS['JEV_ACTIONS_ENABLED']=true;
    $GLOBALS['JEV_ACTION_MIN_CONFIDENCE']=0.8;
    $GLOBALS['JEV_ACTION_TIMEOUT_MS']=350;
    $GLOBALS['SYNTH_TURN_START_TIME']=microtime(true);
    // Use the real catalog functions, while the fixture selects the eligible two-action catalog.
    require $root.'/functions/functions.php';
    $catalog=[];
    foreach($GLOBALS['FUNCTIONS']??[] as $definition)
        if(in_array(getFunctionCodeName($definition['name']??''),['WaitHere','ReleaseWait'],true))$catalog[]=$definition;
    foreach(['WaitHere','ReleaseWait'] as $code){
        if(!array_filter($catalog,static fn($definition)=>getFunctionCodeName($definition['name']??'')===$code)
            && isset($GLOBALS['BASE_FUNCTIONS'][$code]))$catalog[]=$GLOBALS['BASE_FUNCTIONS'][$code];
    }
    $GLOBALS['FUNCTIONS']=$catalog;
    $GLOBALS['ENABLED_FUNCTIONS']=['WaitHere','ReleaseWait'];
    if (synth_protocol_supports_gift_menu($event)) $GLOBALS['ENABLED_FUNCTIONS'][]='OpenInventory';
    if(($_SERVER['HTTP_X_FIXTURE_DISABLE_ACTION']??'')==='1')$GLOBALS['ENABLED_FUNCTIONS']=[];
    // Preserve the ordinary authenticated input history before considering direct handoff.
    $db->insert('eventlog',['type'=>'inputtext','data'=>$gameRequest[3],'gamets'=>1000,'ts'=>time(),
        'localts'=>time(),'sess'=>$event['session_id'],'people'=>'','location'=>'Synthetic settlement','party'=>'']);
    while(ob_get_level()>0)ob_end_clean();
    $GLOBALS['SYNTH_RESPONSE_STREAMING']=($_SERVER['HTTP_X_FIXTURE_STREAMING']??'1')==='1';
    synth_start_json_response_buffer();
    synth_emit_native_player_transcript($gameRequest);
    if (synth_gift_menu_try_handoff($db,$event)) exit;
    $choice=$_SERVER['HTTP_X_FIXTURE_CHOICE']??'WaitHere';
    $confidence=(float)($_SERVER['HTTP_X_FIXTURE_CONFIDENCE']??'0.97');
    $transport=static function(array $body,string $key,int $budget) use($choice,$confidence): array {
        error_log('JEV_FIXTURE_CLASSIFY');
        if(($_SERVER['HTTP_X_FIXTURE_TIMEOUT']??'')==='1')throw new RuntimeException('timeout');
        if(!isset($body['questions']['action']['criteria']['WaitHere']) && $choice==='WaitHere')
            return ['answers'=>['action'=>['type'=>'choice','choice'=>'abstain','confidence'=>1.0]]];
        return ['answers'=>['action'=>['type'=>'choice','choice'=>$choice,'confidence'=>$confidence]]];
    };
    synth_jev_prepare_actions($db,$event,null,
        static fn()=>['model'=>'typesafe/jev-fixture','key'=>'synthetic-noncredential'],$transport);
    $mutation=$_SERVER['HTTP_X_FIXTURE_POLICY_MUTATION']??'';
    if($mutation==='actor' && isset($GLOBALS['SYNTH_JEV_ACTION_POLICY']))
        $GLOBALS['SYNTH_JEV_ACTION_POLICY']['actor']['form_id']='0x00000016';
    if($mutation==='utterance' && isset($GLOBALS['SYNTH_JEV_ACTION_POLICY']))
        $GLOBALS['SYNTH_JEV_ACTION_POLICY']['utterance_sha256']=hash('sha256','different fictional input');
    $invalidate=$_SERVER['HTTP_X_FIXTURE_INVALIDATE']??'';
    if(in_array($invalidate,['cancel','load'],true)){
        $revocation=$event;
        $revocation['request_id'].=':revoke';$revocation['turn_id'].=':revoke';$revocation['context_sequence']=0;
        if($invalidate==='cancel'){$revocation['type']='cancel';$revocation['payload']=['cancel_request_id'=>$event['request_id']];}
        else{$revocation['type']='init';$revocation['generation']++;$revocation['payload']=['locale'=>'en-US'];}
        synth_protocol_claim_request($db,$revocation);
    }
    if(synth_wait_try_jev_handoff($db,$event))exit;
    // The real dialogue model is deliberately absent. Reaching this marker means fallback was retained.
    error_log('JEV_FIXTURE_LLM_FALLBACK');
    synth_emit_buffered_json_response();
} catch(SYNTHProtocolCancelled $error){
    synth_emit_cancelled_json_response();
    synth_protocol_complete_request($GLOBALS['db'],'cancelled','application/x-ndjson',
        strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']??''),'request_cancelled');
} catch(Throwable $error){
    error_log('JEV_FIXTURE_UNEXPECTED_'.get_class($error).'_'.basename($error->getFile()).':'.$error->getLine());
    synth_emit_failed_json_response();
}
