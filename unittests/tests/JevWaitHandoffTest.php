<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';

/** Real HTTP/decode/admission/production sql/response; fictional input and injected JEV only. */
final class JevWaitHandoffTest extends DatabaseTestCase
{
    private $server=null;
    private $connection=null;
    private string $address='';
    private string $log='';
    private int $serial=0;
    private array $template=[];

    public function setUp(): void {
        parent::setUp();
        $this->connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($this->connection);
        $this->template=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-wait-released.json'),true,32,JSON_THROW_ON_ERROR);
        $this->template['capabilities']=array_values(array_unique([...$this->template['capabilities'],
            'action.wait_here','action.release_wait','action.wait_verified','context.wait_state','control.menu',
            'action.open_inventory','action.gift_menu_inventory','action.give_item_to',
            'action.transfer_inventory','action.inventory_observation']));
        $payload=&$this->template['payload'];
        $payload['actor_states'][0]['inventory']=[];$payload['actor_states'][0]['factions']=[];
        unset($payload['actor_states'][0]['combat_target'],$payload['actor_states'][0]['combat_contacts']);
        $payload['actor_states'][0]['life_state']='alive';$payload['actor_states'][0]['line_of_sight']='visible';
        $payload['actor_states'][0]['teammate']=true;$payload['actor_states'][0]['wait_held']=false;
        $payload['nearby_items']=[];$payload['points_of_interest']=[];$payload['active_quests']=[];
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        $this->assertIsResource($listener);$this->address=stream_socket_get_name($listener,false);fclose($listener);
        $this->log=tempnam(sys_get_temp_dir(),'synth-jev-wait-http-');
        $environment=getenv();unset($environment['PHPUNIT_TEST']);
        $environment['SYNTH_DB_HOST']='localhost';$environment['SYNTH_DB_NAME']='testdb';
        $environment['SYNTH_DB_USER']='dwemer';$environment['SYNTH_DB_PASSWORD']='dwemer';
        $environment['SYNTH_BACKGROUND_START_SCRIPT']='/nonexistent-disposable-background-script';
        $pipes=[];
        $this->server=proc_open([PHP_BINARY,'-d','output_buffering=4096','-d',
            'disable_functions=curl_exec,shell_exec,exec,system,passthru','-S',$this->address,
            dirname(__DIR__).'/fixtures/jev_wait_router.php'],
            [0=>['pipe','r'],1=>['file',$this->log,'a'],2=>['file',$this->log,'a']],$pipes,dirname(__DIR__,2),$environment);
        $this->assertIsResource($this->server);fclose($pipes[0]);$ready=false;
        for($i=0;$i<100;++$i){$probe=@stream_socket_client('tcp://'.$this->address,$errno,$error,0.01);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}
        $this->assertTrue($ready,'Disposable JEV HTTP fixture failed to start');
    }
    public function tearDown(): void {
        if(is_resource($this->server)){proc_terminate($this->server);proc_close($this->server);}
        if(is_resource($this->connection)||$this->connection instanceof PgSql\Connection)pg_close($this->connection);
        if($this->log!==''&&file_exists($this->log))unlink($this->log);
        parent::tearDown();
    }
    private function event(string $type,array $payload,int $sequence=0,int $generation=1): array {
        return ['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>$type,'session_id'=>'jev-wait-http',
            'request_id'=>'jev-wait-http:'.(++$this->serial),'turn_id'=>'jev-wait-turn:'.$this->serial,
            'generation'=>$generation,'context_sequence'=>$sequence,'game'=>'fo4','runtime_variant'=>'flat',
            'client_version'=>'synthetic-http','runtime_version'=>'synthetic','capabilities'=>$this->template['capabilities'],'payload'=>$payload];
    }
    private function http(array $event,array $headers=[]): string {
        $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>12,
            'header'=>implode("\r\n",['Content-Type: application/json',...$headers]),
            'content'=>json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]);
        $body=file_get_contents('http://'.$this->address.'/fixture',false,$context);
        $this->assertIsString($body,'No HTTP response');
        $this->assertStringNotContainsString('SQL: Error',$body,$this->diagnostic());return $body;
    }
    private function diagnostic(): string {return is_file($this->log)?substr(file_get_contents($this->log),-6000):'';}
    private function calls(string $marker): int {return substr_count($this->diagnostic(),$marker);}
    private function lines(string $body): array {
        $lines=array_map(static fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_values(array_filter(explode("\n",trim($body)))));
        $this->assertNotEmpty($lines,$body.$this->diagnostic());
        foreach($lines as $line)$this->assertArrayHasKey('type',$line,$body.$this->diagnostic());
        return $lines;
    }
    private function query(string $sql,array $params=[]): array {
        $result=$params?pg_query_params($this->connection,$sql,$params):pg_query($this->connection,$sql);
        $this->assertNotFalse($result);return pg_fetch_all($result)?:[];
    }
    private function init(int $generation=1): void {
        $event=$this->event('init',['locale'=>'en-US'],0,$generation);$body=$this->http($event);
        $this->assertSame('complete',array_slice($this->lines($body),-1)[0]['payload']['status'],$body.$this->diagnostic());
        $this->assertSame('complete',$this->query('SELECT state FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',[$event['session_id'],$event['request_id']])[0]['state']);
    }
    private function context(array $stateChanges=[],int $sequence=1,bool $gift=false): void {
        $payload=$this->template['payload'];$payload['snapshot_sequence']=$sequence;
        $payload['actor_states'][0]=array_replace($payload['actor_states'][0],$stateChanges);
        if(array_key_exists('wait_held',$stateChanges) && $stateChanges['wait_held']===null)unset($payload['actor_states'][0]['wait_held']);
        if ($gift) {
            $payload['actor_states'][]=array_replace($payload['actor_states'][0],[
                'actor'=>$payload['player'],'distance'=>0,'inventory_observation'=>'complete','inventory'=>[],
            ]);
        }
        $event=$this->event('context',$payload);$body=$this->http($event);$lines=$this->lines($body);
        $this->assertSame('context_accepted',$lines[1]['payload']['code']??null,$body.$this->diagnostic());
        $this->assertSame('complete',array_slice($lines,-1)[0]['payload']['status'],$body.$this->diagnostic());
        $this->assertSame('complete',$this->query('SELECT state FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',[$event['session_id'],$event['request_id']])[0]['state']);
    }
    private function input(string $text='Wait here.'): array {
        return $this->event('input_text',['text'=>$text,'control'=>['mode'=>'STANDARD','model_slot'=>1]],1);
    }
    private function assertCompleteStored(array $event,string $body): array {
        $lines=$this->lines($body);
        $this->assertSame(1,count(array_filter($lines,static fn($line)=>$line['type']==='response_start')),$body.$this->diagnostic());
        $this->assertSame(1,count(array_filter($lines,static fn($line)=>$line['type']==='response_end')));
        $this->assertSame('complete',array_slice($lines,-1)[0]['payload']['status'],$body.$this->diagnostic());
        $rows=$this->query('SELECT state,response_body FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',[$event['session_id'],$event['request_id']]);
        $this->assertSame('complete',$rows[0]['state']);$this->assertSame($body,synth_protocol_decode_bytea($rows[0]['response_body']));return $lines;
    }
    private function intents(array $event): array {
        return $this->query('SELECT i.*,a.full_form_id,a.origin_plugin,a.playthrough_id FROM synth_meta.action_intents i JOIN synth.actor_identities a ON a.id=i.actor_identity_id WHERE i.session_id=$1 AND i.request_id=$2',[$event['session_id'],$event['request_id']]);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('handoffCases')]
    public function testRealHttpCompletesOneOwnedActionBeforeAnyDialogueInferenceAndReplays(bool $streaming,bool $release): void {
        $this->init();$this->context(['wait_held'=>$release]);
        $event=$this->input($release?'Stop waiting.':'Wait here.');$code=$release?'ReleaseWait':'WaitHere';
        $headers=['X-Fixture-Streaming: '.($streaming?'1':'0'),'X-Fixture-Choice: '.$code];
        $body=$this->http($event,$headers);$lines=$this->assertCompleteStored($event,$body);
        $actions=array_values(array_filter($lines,static fn($line)=>$line['type']==='action'));
        $this->assertCount(1,$actions,$body.$this->diagnostic());$action=$actions[0]['payload']['action'];
        $this->assertSame($release?'release_wait':'wait_here',$action['name']);
        $expectedActor=$this->template['payload']['target'];$actualActor=$action['actor'];
        ksort($expectedActor,SORT_STRING);ksort($actualActor,SORT_STRING);
        $this->assertSame($expectedActor,$actualActor);
        $this->assertSame([],$action['arguments']);
        $this->assertArrayNotHasKey('target',$action);
        $intents=$this->intents($event);$this->assertCount(1,$intents);$this->assertSame($action['action_id'],$intents[0]['action_id']);
        $this->assertSame($this->template['payload']['target']['form_id'],$intents[0]['full_form_id']);
        $this->assertSame($code,$intents[0]['action_name']);$this->assertNull($intents[0]['target_identity_id']);
        $this->assertInstanceOf(stdClass::class,json_decode($intents[0]['arguments']));
        $this->assertSame([],get_object_vars(json_decode($intents[0]['arguments'])));
        $history=$this->query("SELECT event_payload->>'data' AS data FROM synth_meta.history_events WHERE session_id=$1 AND request_id=$2 AND event_payload->>'type'='inputtext'",[$event['session_id'],$event['request_id']]);
        $this->assertCount(1,$history);$this->assertSame($event['payload']['text'],json_decode($history[0]['data'],true)['text']);
        foreach($lines as $line)if($line['type']==='dialogue')$this->assertSame('0x00000014',$line['payload']['speaker']['form_id']);
        $this->assertSame(0,$this->calls('JEV_FIXTURE_LLM_FALLBACK'));$this->assertSame(1,$this->calls('JEV_FIXTURE_CLASSIFY'));
        $this->assertSame($body,$this->http($event,$headers));$this->assertCount(1,$this->intents($event));
        $this->assertSame(1,$this->calls('JEV_FIXTURE_CLASSIFY'),'Replay must not classify or issue again');
    }
    public static function handoffCases(): array {return [[true,false],[false,false],[true,true],[false,true]];}

    #[PHPUnit\Framework\Attributes\DataProvider('fallbackCases')]
    public function testRealHttpUncertainOrIneligibleCommandRetainsOrdinaryDialogue(string $text,array $state,array $headers): void {
        $this->init();$this->context($state);$event=$this->input($text);
        $lines=$this->assertCompleteStored($event,$this->http($event,$headers));
        $this->assertSame([],array_values(array_filter($lines,static fn($line)=>$line['type']==='action')));
        $this->assertSame([],$this->intents($event));$this->assertSame(1,$this->calls('JEV_FIXTURE_LLM_FALLBACK'));
    }
    public static function fallbackCases(): array {
        return [['Wait here.',['teammate'=>false],[]],['Wait here.',['in_combat'=>true],[]],
            ['Wait here.',['wait_held'=>null],[]],['"Wait here."',[],[]],["Don't wait here.",[],[]],
            ['Wait here and attack the raider.',[],[]],['What would happen if you wait here?',[],[]],
            ['Wait here.',[],['X-Fixture-Confidence: 0.79']],['Wait here.',[],['X-Fixture-Timeout: 1']],
            ['Wait here.',[],['X-Fixture-Policy-Mutation: actor']],['Wait here.',[],['X-Fixture-Policy-Mutation: utterance']],
            ['Wait here.',[],['X-Fixture-Disable-Action: 1']]];
    }
    #[PHPUnit\Framework\Attributes\DataProvider('giftMenuCases')]
    public function testGiftMenuDoesNotDependOnTheDialogueModel(bool $streaming,string $text,bool $enabled,bool $expectMenu): void {
        $this->init();
        $this->context(gift:true);
        $event=$this->input($text);
        $headers=['X-Fixture-Streaming: '.($streaming?'1':'0'),'X-Fixture-Disable-Action: '.($enabled?'0':'1')];
        $body=$this->http($event,$headers);
        $lines=$this->assertCompleteStored($event,$body);
        $actions=array_values(array_filter($lines,static fn($line)=>$line['type']==='action'));
        $this->assertCount($expectMenu?1:0,$actions,$body.$this->diagnostic());
        $this->assertSame($expectMenu?0:1,$this->calls('JEV_FIXTURE_LLM_FALLBACK'));
        if (!$expectMenu) return;

        $action=$actions[0]['payload']['action'];
        $this->assertSame('open_inventory',$action['name']);
        $this->assertSame($this->template['payload']['target']['form_id'],$action['actor']['form_id']);
        $this->assertSame($this->template['payload']['player']['form_id'],$action['target']['form_id']);
        $this->assertSame([],$action['arguments']);
        $this->assertCount(1,$this->intents($event));
        $this->assertSame($body,$this->http($event,$headers));
        $this->assertCount(1,$this->intents($event));
    }
    public static function giftMenuCases(): array {
        return [
            [true,'Hey, I have a gift for you.',true,true],
            [false,'I have a gift for you.',true,true],
            [true,'I have a gift for you, a pistol.',true,false],
            [true,"I don't have a gift for you.",true,false],
            [true,'I have a gift for you.',false,false],
        ];
    }
    public function testAgedContextCannotAuthorizeDirectFollowerExecution(): void {
        $this->init();$this->context();
        $this->query("UPDATE synth_meta.context_snapshots SET received_at=clock_timestamp()-INTERVAL '6 seconds' WHERE session_id='jev-wait-http'");
        $event=$this->input();$this->assertCompleteStored($event,$this->http($event));$this->assertSame([],$this->intents($event));
        $this->assertSame(1,$this->calls('JEV_FIXTURE_LLM_FALLBACK'));
    }
    public function testNewerCapturePreventsDirectExecutionFromAnOtherwiseValidOlderFollowerFrame(): void {
        $this->init();$this->context();$this->context(['teammate'=>false],2);$event=$this->input();
        // A positive immutable snapshot alone must not authorize a direct command after a newer capture.
        $body=$this->http($event);$this->assertCompleteStored($event,$body);$this->assertSame([],$this->intents($event));
        $this->assertStringNotContainsString('"type":"action"',$body);$this->assertSame(1,$this->calls('JEV_FIXTURE_LLM_FALLBACK'));
    }
    #[PHPUnit\Framework\Attributes\DataProvider('revocations')]
    public function testStopOrLoadBetweenSelectionAndHandoffCannotDispatch(string $revocation): void {
        $this->init();$this->context();$event=$this->input();
        $body=$this->http($event,['X-Fixture-Invalidate: '.$revocation]);$lines=$this->lines($body);
        $this->assertSame('cancelled',array_slice($lines,-1)[0]['payload']['status'],$body.$this->diagnostic());
        $this->assertSame([],$this->intents($event));
    }
    public static function revocations(): array {return [['cancel'],['load']];}
    public function testProductionPipelineInvokesHandoffAfterOwnedInputAndBeforeModelWork(): void {
        $source=file_get_contents(dirname(__DIR__,2).'/main_synth_pipeline.php');
        $input=strpos($source,'$db->insert(\'eventlog\', $eventlogInsert)');
        $decision=strpos($source,'synth_jev_prepare_actions($db,');
        $handoff=strpos($source,'synth_wait_try_jev_handoff($db,');
        $refresh=strpos($source,'synthRefreshJsonResponseState();',$decision);
        $disabled=strpos($source,"if (!empty(\$GLOBALS['PROMPTS'][\$gameRequest[0]]['extra']['dontuse'])) terminate();");
        $this->assertNotFalse($input);$this->assertNotFalse($decision);$this->assertNotFalse($handoff);$this->assertNotFalse($refresh);$this->assertNotFalse($disabled);
        $this->assertLessThan($decision,$input);$this->assertLessThan($handoff,$decision);$this->assertLessThan($refresh,$handoff);
        $this->assertLessThan($decision,$disabled);
        foreach(['$combatIdentification=synth_combat_identification_reply(',
            'Logger::phaseStart("context_history_fetch",',
            'Logger::phaseStart("current_task_fetch",',
            'Logger::phaseStart("memory_offer",'] as $work) {
            $position=strpos($source,$work);
            $this->assertNotFalse($position);
            $this->assertLessThan($position,$handoff,'Direct commands must finish before '.$work);
        }
        $this->assertSame(1,substr_count($source,'synth_jev_prepare_actions($db,'));
        $this->assertSame(1,substr_count($source,'synth_wait_try_jev_handoff($db,'));
    }
}
