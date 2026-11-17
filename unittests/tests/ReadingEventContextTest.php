<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';

final class ReadingEventContextTest extends DatabaseTestCase
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
        $this->template['capabilities']=array_values(array_unique([...$this->template['capabilities'],'social.tasks.v1','context.saved_anchor','context.reading_events']));
        $payload=&$this->template['payload'];$performer=$payload['target'];
        $recipient=$performer;$recipient['form_id']='0x00000016';$recipient['display_name']='Jun';
        $payload['audience']=[$performer,$recipient];
        $state=$payload['actor_states'][0];unset($state['combat_target'],$state['combat_contacts']);
        $state['inventory']=[];$state['factions']=[];$payload['actor_states']=[$state];
        $state['actor']=$recipient;$state['base_form_id']=$recipient['form_id'];$state['position']['x']+=100;$state['teammate']=false;
        $payload['actor_states'][]=$state;$payload['nearby_items']=[];$payload['points_of_interest']=[];$payload['active_quests']=[];
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        $this->assertIsResource($listener);$this->address=stream_socket_get_name($listener,false);fclose($listener);
        $this->log=tempnam(sys_get_temp_dir(),'synth-context-http-');
        $environment=getenv();unset($environment['PHPUNIT_TEST']);
        $environment['SYNTH_DB_HOST']='localhost';$environment['SYNTH_DB_NAME']='testdb';
        $environment['SYNTH_DB_USER']='dwemer';$environment['SYNTH_DB_PASSWORD']='dwemer';
        $environment['SYNTH_BACKGROUND_START_SCRIPT']='/nonexistent-disposable-background-script';
        $environment['SYNTH_BACKGROUND_PORT']='61999';
        $pipes=[];
        $this->server=proc_open([PHP_BINARY,'-d','output_buffering=4096','-d','disable_functions=curl_exec,shell_exec,exec,system,passthru',
            '-S',$this->address,dirname(__DIR__).'/fixtures/context_ack_router.php'],
            [0=>['pipe','r'],1=>['file',$this->log,'a'],2=>['file',$this->log,'a']],$pipes,dirname(__DIR__,2),$environment);
        $this->assertIsResource($this->server);fclose($pipes[0]);$ready=false;
        for($i=0;$i<100;++$i){$probe=@stream_socket_client('tcp://'.$this->address,$errno,$error,0.01);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}
        $this->assertTrue($ready,'Disposable HTTP fixture failed to start');
    }
    public function tearDown(): void {
        if(is_resource($this->server)){proc_terminate($this->server);proc_close($this->server);}
        if(is_resource($this->connection)||$this->connection instanceof PgSql\Connection)pg_close($this->connection);
        if($this->log!==''&&file_exists($this->log))unlink($this->log);
        parent::tearDown();
    }
    private function event(string $type,string $session='context-http',int $generation=1,array $payload=[],int $sequence=0): array {
        return ['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>$type,'session_id'=>$session,
            'request_id'=>'context-http:'.(++$this->serial),'turn_id'=>'context-turn:'.$this->serial,'generation'=>$generation,
            'game'=>'fo4','runtime_variant'=>'flat','client_version'=>'synthetic-http','runtime_version'=>'synthetic',
            'capabilities'=>$this->template['capabilities'],'payload'=>$payload,'context_sequence'=>$sequence];
    }
    private function http(array $event,string $path='/main.php',array $headers=[]): string {
        $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>12,
            'header'=>implode("\r\n",['Content-Type: application/json','Accept: application/x-ndjson',...$headers]),
            'content'=>json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]);
        $body=file_get_contents('http://'.$this->address.$path,false,$context);
        $this->assertIsString($body,'No HTTP body');
        $this->assertStringNotContainsString('SQL: Error',$body,$this->diagnostic());
        return $body;
    }
    private function diagnostic(): string {
        return is_file($this->log)?substr(file_get_contents($this->log),-5000):'';
    }
    private function lines(string $body): array {
        return array_map(static fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_values(array_filter(explode("\n",trim($body)))));
    }
    private function row(array $event): array {
        $result=pg_query_params($this->connection,'SELECT state,error_code,response_body FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',[$event['session_id'],$event['request_id']]);
        return pg_fetch_assoc($result)?:[];
    }
    private function init(string $session='context-http',int $generation=1): void {
        $event=$this->event('init',$session,$generation,['locale'=>'en-US']);$body=$this->http($event);
        $this->assertSame('complete',$this->row($event)['state']??null,$body.$this->diagnostic());
        $this->assertSame('complete',array_slice($this->lines($body),-1)[0]['payload']['status']);
    }
    private function context(int $sequence=1,string $session='context-http',int $generation=1,?array $anchor=null): array {
        $payload=$this->template['payload'];$payload['snapshot_sequence']=$sequence;
        if($anchor!==null)$payload['loaded_context']=$anchor;
        return $this->event('context',$session,$generation,$payload);
    }
    private function completeContext(array $event): array {
        $body=$this->http($event);$lines=$this->lines($body);$row=$this->row($event);
        $this->assertSame('context_accepted',$lines[1]['payload']['code']??null,$body.$this->diagnostic());
        $this->assertSame('complete',array_slice($lines,-1)[0]['payload']['status']);
        $this->assertSame('complete',$row['state']??null,$body.$this->diagnostic());
        $this->assertSame($body,synth_protocol_decode_bytea($row['response_body']));
        $result=pg_query_params($this->connection,"SELECT p.canonical_event,c.context_payload FROM synth_meta.protocol_events p
            JOIN synth_meta.context_snapshots c ON c.session_id=p.session_id AND c.generation=p.generation
                AND c.snapshot_sequence=$3 WHERE p.session_id=$1 AND p.request_id=$2",[$event['session_id'],$event['request_id'],$event['payload']['snapshot_sequence']]);
        $capture=pg_fetch_assoc($result);$canonical=json_decode($capture['canonical_event'],true);
        $this->assertSame(0,$canonical['context_sequence']);$this->assertSame($event['payload']['snapshot_sequence'],$canonical['payload']['snapshot_sequence']);
        $this->assertEquals($canonical['payload'],json_decode($capture['context_payload'],true));
        return [$body,$lines];
    }
    private function reading(string $text='Dear Nate, the key is under the mat.'): array {
        return ['event_id'=>'reading:1','kind'=>'note','title'=>'Sanctuary Hills Note','source'=>['form_id'=>'0x0001F2A3','origin_plugin'=>'Fallout4.esm'],
            'text'=>$text,'text_truncated'=>false,'age_ms'=>120];
    }
    private function rows(string $type): int {
        $result=pg_query($this->connection,"SELECT count(*) AS n FROM synth_meta.history_events WHERE event_payload->>'type'='".$type."'");
        return intval(pg_fetch_assoc($result)['n']);
    }
    private function promptRows(string $actor): array {
        $db=$GLOBALS['db'] ?? new sql();
        $event=$this->event('input_text','context-http',1,[],1);
        return $db->fetchAll(synth_protocol_history_cte($db,$event,$actor)." SELECT type,data FROM native_history WHERE type='reading_event'");
    }
    public function testInitGrantsReadingEventsAndAReadReachesTheTargetPromptHistoryOnce(): void {
        $event=$this->event('init','context-http',1,['locale'=>'en-US']);
        $lines=$this->lines($this->http($event));
        $status=array_values(array_filter($lines,static fn($line)=>$line['type']==='status'))[0]['payload'];
        $this->assertTrue($status['reading_events']??false);
        $context=$this->context(1);$context['payload']['reading_event']=$this->reading();
        $this->completeContext($context);
        $this->assertSame(1,$this->rows('reading_event'));
        $target=$context['payload']['target']['display_name'];
        $rows=$this->promptRows($target);
        $this->assertCount(1,$rows);
        $this->assertStringContainsString('read the note "Sanctuary Hills Note"',$rows[0]['data']);
        $this->assertStringContainsString('the key is under the mat',$rows[0]['data']);
        $this->assertSame([],$this->promptRows('Nobody Nearby'));
        // A later context repeating the same reading is idempotent; changed content for the same id is refused.
        $again=$this->context(2);$again['payload']['reading_event']=$this->reading();
        $this->completeContext($again);
        $this->assertSame(1,$this->rows('reading_event'));
        $conflict=$this->context(3);$conflict['payload']['reading_event']=$this->reading('Something else entirely.');
        $this->http($conflict);
        $this->assertNotSame('complete',$this->row($conflict)['state']??'complete');
        $this->assertSame(1,$this->rows('reading_event'));
    }
    public function testReadingWithoutNegotiatedCapabilityIsRejectedAndStoresNothing(): void {
        $this->init();
        $context=$this->context(1);$context['payload']['reading_event']=$this->reading();
        $context['capabilities']=array_values(array_diff($context['capabilities'],['context.reading_events']));
        $this->http($context);
        $this->assertSame(0,$this->rows('reading_event'));
    }
}
