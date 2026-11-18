<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
require_once dirname(__DIR__,2).'/managers/Notification/NotifyConfig.php';

// The init acknowledgement of the optional wake-up channel, through the real HTTP entry point.
final class NotifyWakeInitTest extends DatabaseTestCase
{
    private $server=null;
    private $notify=null;
    private string $address='';
    private string $log='';
    private int $serial=0;
    private int $wsPort=0;
    private int $internalPort=0;

    public function setUp(): void {
        parent::setUp();
        $this->wsPort=$this->freePort();$this->internalPort=$this->freePort();
        $this->address='127.0.0.1:'.$this->freePort();
        $this->log=tempnam(sys_get_temp_dir(),'synth-notify-http-');
        $environment=getenv();unset($environment['PHPUNIT_TEST']);
        $environment['SYNTH_DB_HOST']='localhost';$environment['SYNTH_DB_NAME']='testdb';
        $environment['SYNTH_DB_USER']='dwemer';$environment['SYNTH_DB_PASSWORD']='dwemer';
        $environment['SYNTH_BACKGROUND_START_SCRIPT']='/nonexistent-disposable-background-script';
        $environment['SYNTH_BACKGROUND_PORT']='61999';
        $environment['SYNTH_NOTIFY_PORT']=(string)$this->wsPort;
        $environment['SYNTH_NOTIFY_INTERNAL_PORT']=(string)$this->internalPort;
        $pipes=[];
        $this->server=proc_open([PHP_BINARY,'-d','output_buffering=4096','-d','disable_functions=curl_exec,shell_exec,exec,system,passthru',
            '-S',$this->address,dirname(__DIR__).'/fixtures/context_ack_router.php'],
            [0=>['pipe','r'],1=>['file',$this->log,'a'],2=>['file',$this->log,'a']],$pipes,dirname(__DIR__,2),$environment);
        $this->assertIsResource($this->server);fclose($pipes[0]);$ready=false;
        for($i=0;$i<100;++$i){$probe=@stream_socket_client('tcp://'.$this->address,$errno,$error,0.01);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}
        $this->assertTrue($ready,'Disposable HTTP fixture failed to start');
    }
    public function tearDown(): void {
        foreach([$this->server,$this->notify] as $process) if(is_resource($process)){proc_terminate($process);proc_close($process);}
        if($this->log!==''&&file_exists($this->log))unlink($this->log);
        parent::tearDown();
    }
    private function freePort(): int {
        $socket=stream_socket_server('tcp://127.0.0.1:0');$name=stream_socket_get_name($socket,false);fclose($socket);
        return (int)substr(strrchr((string)$name,':'),1);
    }
    private function startChannel(): void {
        $autoload=dirname(__DIR__,2).'/vendor/autoload.php';
        if(!is_file($autoload))$this->markTestSkipped('vendor/autoload.php is required to run bin/notify-server.php');
        $this->notify=proc_open([PHP_BINARY,dirname(__DIR__,2).'/bin/notify-server.php','--port='.$this->wsPort,'--internal-port='.$this->internalPort],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $this->assertIsResource($this->notify);
        for($i=0;$i<100;++$i){$probe=@stream_socket_client('tcp://127.0.0.1:'.$this->internalPort,$errno,$error,0.05);if($probe){fclose($probe);return;}usleep(50000);}
        $this->fail('The notification channel did not start');
    }
    private function initStatus(array $capabilities): array {
        $event=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'init','session_id'=>'notify-http-'.($this->serial+1),'request_id'=>'notify-http:'.(++$this->serial),
            'turn_id'=>'notify-turn:'.$this->serial,'generation'=>1,'game'=>'fo4','runtime_variant'=>'flat','client_version'=>'synthetic-http',
            'runtime_version'=>'synthetic','capabilities'=>$capabilities,'payload'=>['locale'=>'en-US'],'context_sequence'=>0];
        $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>12,
            'header'=>"Content-Type: application/json\r\nAccept: application/x-ndjson",
            'content'=>json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]);
        $body=file_get_contents('http://'.$this->address.'/main.php',false,$context);
        $this->assertIsString($body,'No HTTP body');
        $lines=array_map(static fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_values(array_filter(explode("\n",trim($body)))));
        foreach($lines as $line) if($line['type']==='status') return $line['payload'];
        $this->fail('No status line: '.$body.(is_file($this->log)?substr(file_get_contents($this->log),-2000):''));
    }

    public function testInitAcknowledgesThePortOnlyWhileTheChannelRunsAndTheClientAsked(): void {
        $asked=['dialogue.text','dialogue.turn_ownership','notify.wake'];
        // Channel absent: init is accepted exactly as before, with no key for the client to act on.
        $absent=$this->initStatus($asked);
        $this->assertSame('init_accepted',$absent['code']);
        $this->assertArrayNotHasKey('notify_wake',$absent);
        $this->startChannel();
        // Channel running and asked for: the acknowledged port is the plugin-facing one.
        $running=$this->initStatus($asked);
        $this->assertSame(['port'=>$this->wsPort],$running['notify_wake']);
        // An older client never lists the capability and so never receives the key.
        $older=$this->initStatus(['dialogue.text','dialogue.turn_ownership']);
        $this->assertSame('init_accepted',$older['code']);
        $this->assertArrayNotHasKey('notify_wake',$older);
    }
}
