<?php declare(strict_types=1);
// Compiled-client loopback dialogue check. Outside unittests/tests, so ordinary PHPUnit discovery never runs it; it
// runs only through run-loopback-dialogue.sh, which owns the private namespaces, the disposable PostgreSQL cluster
// and the compiled client driver (SYNTH_LOOPBACK_DRIVER). The test sends no protocol event itself: every init,
// context, /talk and receipt comes from the client over real HTTP to real main.php. Between the driver's phases it
// registers the actor, scripts the loopback provider's next reply and reads PostgreSQL and the provider requests.
require_once dirname(__DIR__).'/tests/DatabaseTestCase.php';

final class LoopbackDialogueTest extends DatabaseTestCase
{
    private const HEARD='Loopback heard token LHT7731 spoken.';
    private const TOKEN='LHT7731';
    private array $servers=[];
    private $connection=null;
    private string $address='';
    private string $log='';
    private string $llmDir='';
    private string $llmUrl='';
    // Set only once this class has created testdb inside the runner, so no inherited teardown can drop a database or
    // touch conf/ on a direct invocation or after a refused setup.
    private static bool $ownsDatabase=false;

    // Refuse before DatabaseTestCase drops and recreates testdb unless this is the runner's private namespace.
    public static function setUpBeforeClass(): void {
        $interfaces=array_map(static fn($line)=>trim(explode(':',$line)[0]),array_slice(file('/proc/net/dev') ?: [],2));
        if (!is_string(getenv('SYNTH_LOOPBACK_DRIVER')) || $interfaces!==['lo']
            || !str_contains(strval(@file_get_contents('/proc/1/cmdline')),'run-loopback-dialogue.sh')) {
            self::markTestSkipped('Run only through unittests/loopback/run-loopback-dialogue.sh');
        }
        parent::setUpBeforeClass();
        self::$ownsDatabase=true;
    }
    public static function tearDownAfterClass(): void {
        if (!self::$ownsDatabase) return;
        self::$ownsDatabase=false;
        parent::tearDownAfterClass();
    }

    private function startServer(string $router,array $environment): string {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        $this->assertIsResource($listener);$address=stream_socket_get_name($listener,false);fclose($listener);
        $pipes=[];
        $server=proc_open([PHP_BINARY,'-d','output_buffering=4096','-d','disable_functions=curl_exec,shell_exec,exec,system,passthru',
            '-S',$address,$router],[0=>['pipe','r'],1=>['file',$this->log,'a'],2=>['file',$this->log,'a']],$pipes,dirname(__DIR__,2),$environment);
        $this->assertIsResource($server);fclose($pipes[0]);$this->servers[]=$server;
        for($i=0;$i<200;++$i){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.01);if($probe){fclose($probe);return $address;}usleep(10000);}
        $this->fail('Disposable HTTP fixture failed to start');
    }
    public function setUp(): void {
        parent::setUp();
        $this->connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($this->connection);
        $this->log=tempnam(sys_get_temp_dir(),'synth-loopback-http-');
        $this->llmDir=sys_get_temp_dir().'/synth-loopback-llm-'.bin2hex(random_bytes(6));mkdir($this->llmDir,0700);
        $environment=getenv();unset($environment['PHPUNIT_TEST']);
        $llm=$this->startServer(dirname(__DIR__).'/fixtures/loopback_llm_router.php',$environment+['SYNTH_LOOPBACK_LLM_DIR'=>$this->llmDir]);
        $environment['SYNTH_DB_HOST']='localhost';$environment['SYNTH_DB_NAME']='testdb';
        $environment['SYNTH_DB_USER']='dwemer';$environment['SYNTH_DB_PASSWORD']='dwemer';
        $environment['SYNTH_BACKGROUND_START_SCRIPT']='/nonexistent-disposable-background-script';
        $environment['SYNTH_BACKGROUND_PORT']='61999';
        $this->address=$this->startServer(dirname(__DIR__).'/fixtures/context_ack_router.php',$environment);
        $this->llmUrl='http://'.$llm.'/v1/chat/completions';
    }
    public function tearDown(): void {
        foreach($this->servers as $server)if(is_resource($server)){proc_terminate($server);proc_close($server);}
        if($this->connection instanceof PgSql\Connection)pg_close($this->connection);
        if(!$this->status()->isSuccess() && is_file($this->log))fwrite(STDERR,'SERVER_LOG_TAIL='.$this->tail()."\n");
        if($this->log!==''&&file_exists($this->log))unlink($this->log);
        // An empty llmDir means setUp failed before creating it; never glob relative to the filesystem root.
        if($this->llmDir!==''&&is_dir($this->llmDir)){foreach(glob($this->llmDir.'/*') ?: [] as $file)unlink($file);rmdir($this->llmDir);}
        if(self::$ownsDatabase)parent::tearDown();
    }
    // One driver stdout line, or null at end of output. Reads are non-blocking into a private buffer: fgets after
    // stream_select can block on a partial line, and lines PHP has already buffered are invisible to select.
    private function readLine($pipe,string &$buffer): ?string {
        $deadline=hrtime(true)+60_000_000_000;
        while (($end=strpos($buffer,"\n"))===false) {
            $this->assertLessThan(1048576,strlen($buffer),'driver line exceeds 1 MiB');
            if (feof($pipe)) {$line=$buffer;$buffer='';return $line==='' ? null : $line;}
            $left=intdiv($deadline-hrtime(true),1000);
            $this->assertGreaterThan(0,$left,'driver produced no complete line within 60 s');
            $read=[$pipe];$write=$except=null;
            $ready=stream_select($read,$write,$except,intdiv($left,1000000),$left%1000000);
            $this->assertNotFalse($ready,'stream_select on driver stdout');
            if ($ready>0) $buffer.=strval(fread($pipe,65536));
        }
        $line=substr($buffer,0,$end);$buffer=substr($buffer,$end+1);
        return $line;
    }
    private function tail(): string {return is_file($this->log)?substr(file_get_contents($this->log),-4000):'';}
    private function query(string $sql,array $params=[]): array {
        $result=pg_query_params($this->connection,$sql,$params);$this->assertNotFalse($result,pg_last_error($this->connection));
        return pg_fetch_all($result)?:[];
    }
    private function report(string $kind,mixed $value): void {
        fwrite(STDERR,"CHECK[$kind]=".json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
    }
    private function registerActor(array $actor): void {
        $rows=$this->query("SELECT id FROM synth.actor_identities WHERE game='fo4' AND upper(full_form_id)=upper($1) AND playthrough_id=$2",
            [$actor['form_id'],$actor['playthrough_id']]);
        $this->assertCount(1,$rows,'identity for '.$actor['form_id']);
        $llm=$this->query("INSERT INTO synth.core_llm_connector(label,metadata,url,model,driver,reasoning_model,max_tokens,temperature,top_p)
            VALUES('loopback llm','{}',$1,'loopback-model','openaijson',0,300,0.3,0.8) RETURNING id",[$this->llmUrl])[0]['id'];
        $tts=$this->query("INSERT INTO synth.core_tts_connector(driver,label,metadata,url) VALUES('xtts-fastapi','loopback tts','{}','http://127.0.0.1:9') RETURNING id")[0]['id'];
        $profile=$this->query("INSERT INTO synth.core_profiles(label,tts_connector_id,llm_primary_id,metadata) VALUES('loopback profile',$1,$2,'{}') RETURNING id",[$tts,$llm])[0]['id'];
        $this->query("INSERT INTO synth.core_npc_master(npc_name,actor_identity_id,profile_id,personality,voiceid)
            VALUES('Settler',$1,$2,'Loopback settler personality.','loopback_voice')",[$rows[0]['id'],$profile]);
    }
    // The provider request made by the driver's latest /talk and how often its prompt carries the heard line.
    private function prompt(int $expected): array {
        $file=$this->llmDir.'/requests.ndjson';
        $requests=is_file($file) ? array_map(static fn($l)=>json_decode($l,true),array_values(array_filter(explode("\n",file_get_contents($file))))) : [];
        $this->assertCount($expected,$requests,'exactly one provider call per /talk');
        $messages=json_encode(end($requests)['messages'] ?? null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->assertNotSame('null',$messages);
        return ['heard'=>substr_count($messages,self::HEARD),'token'=>substr_count($messages,self::TOKEN),
            'sha256'=>hash('sha256',$messages),'messages'=>json_decode($messages,true)];
    }
    private function frontier(array $anchor): int {
        $rows=$this->query('SELECT history_event_id FROM synth_meta.context_snapshots WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
            [$anchor['session_id'],$anchor['generation'],$anchor['context_sequence']]);
        $this->assertCount(1,$rows,'snapshot '.json_encode($anchor));
        return intval($rows[0]['history_event_id']);
    }

    public function testCompiledClientLoopbackDialogue(): void {
        $driver=getenv('SYNTH_LOOPBACK_DRIVER');
        $this->assertIsString($driver);$this->assertFileIsReadable($driver);
        $stderr=tempnam(sys_get_temp_dir(),'synth-loopback-driver-');
        $pipes=[];
        // stderr goes to a file, so a chatty driver can never block on a full pipe the test is not reading.
        $process=proc_open([$driver,'http://'.$this->address],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$stderr,'w']],$pipes,
            sys_get_temp_dir(),['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        $this->assertIsResource($process);
        $phases=[];$done=false;$buffer='';
        try {
            stream_set_blocking($pipes[1],false);
            // Every line is bounded: the driver's own HTTP timeouts are shorter than this wait.
            while (($line=$this->readLine($pipes[1],$buffer))!==null) {
                fwrite(STDERR,"DRIVER $line\n");
                if ($line==='DONE') {$done=true;continue;}
                $this->assertStringStartsNotWith('FAILED',$line,$this->tail());
                if (!str_starts_with($line,'PHASE ')) continue;
                [,$name,$json]=explode(' ',$line,3);
                $phases[]=$name;
                $this->phase($name,json_decode($json,true,32,JSON_THROW_ON_ERROR));
                fwrite($pipes[0],"GO\n");fflush($pipes[0]);
            }
        } finally {
            // Closing stdin makes a driver waiting at a phase abort; a driver stuck anywhere else is killed.
            fclose($pipes[0]);
            if (!$done) proc_terminate($process,9);
            fclose($pipes[1]);
            $exit=proc_close($process);
            $errors=strval(file_get_contents($stderr));unlink($stderr);
            fwrite(STDERR,"DRIVER_EXIT=$exit\n".($errors!=='' ? "DRIVER_STDERR=$errors\n" : ''));
        }
        $this->assertTrue($done,'driver did not finish');
        $this->assertSame(0,$exit,'driver exit');
        $this->assertSame(['context','checkpoint','older_talk','newer_talk'],$phases);
        // Every client request was claimed and completed by real main.php.
        $requests=$this->query("SELECT event_type,count(*) AS n FROM synth_meta.request_executions WHERE state='complete' GROUP BY event_type ORDER BY event_type");
        $this->report('requests',$requests);
        $this->assertSame(13,intval($this->query('SELECT count(*) AS n FROM synth_meta.request_executions')[0]['n']));
        $this->assertSame(['context'=>5,'init'=>3,'input_text'=>3,'speech_receipt'=>2],
            array_map('intval',array_column($requests,'n','event_type')));
    }

    private function phase(string $name,array $value): void {
        if ($name==='context') {
            $this->registerActor(['form_id'=>$value['target_form'],'playthrough_id'=>$value['playthrough_id']]);
            file_put_contents($this->llmDir.'/reply',self::HEARD);
            $this->assertFileDoesNotExist($this->llmDir.'/requests.ndjson','no provider call before the first /talk');
            return;
        }
        if ($name==='checkpoint') {
            $session=$value['session'];
            $this->assertSame(0,$this->prompt(1)['token'],'the first /talk prompt precedes its own line');
            $heard=$this->query("SELECT h.id,h.request_id,e.data FROM synth_meta.history_events h JOIN synth.eventlog e ON e.rowid=h.eventlog_rowid
                WHERE h.session_id=$1 AND e.type='chat' AND strpos(e.data,$2)>0 ORDER BY h.id",[$session,self::TOKEN]);
            $this->report('heard_rows',$heard);
            $this->assertCount(1,$heard,'exactly one heard row after two accepted receipts');
            $utterance=$this->query('SELECT state,receipt_request_id,request_id FROM synth_meta.dialogue_utterances WHERE session_id=$1 AND line_id=$2',
                [$session,$value['line_id']]);
            $this->report('utterance',$utterance);
            $this->assertCount(1,$utterance);$this->assertSame('spoken',$utterance[0]['state']);
            $this->assertSame($value['dialogue_request_id'],$utterance[0]['request_id']);
            $this->assertSame($value['receipt_request_ids'][0],$utterance[0]['receipt_request_id'],'the first receipt owns the heard state');
            $receipts=$this->query('SELECT request_id,state,error_code FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=ANY($2::text[]) ORDER BY request_id',
                [$session,'{'.implode(',',$value['receipt_request_ids']).'}']);
            $this->report('receipts',$receipts);
            $this->assertSame(['complete','complete'],array_column($receipts,'state'));
            $older=$this->frontier($value['older_anchor']);$newer=$this->frontier($value['newer_anchor']);$row=intval($heard[0]['id']);
            $this->report('anchors',['older'=>$older,'newer'=>$newer,'heard_row'=>$row]);
            $this->assertLessThan($row,$older,'older anchor frontier excludes the heard row');
            $this->assertGreaterThanOrEqual($row,$newer,'newer anchor frontier covers the heard row');
            $this->assertSame(1,intval($this->query("SELECT count(*) AS n FROM synth_meta.history_events h JOIN synth.eventlog e ON e.rowid=h.eventlog_rowid
                WHERE h.session_id=$1 AND e.type='chat' AND e.utterance_id IS NOT NULL",[$session])[0]['n']),'one heard NPC line in the session');
            file_put_contents($this->llmDir.'/reply','Older load reply.');
            return;
        }
        if ($name==='older_talk' || $name==='newer_talk') {
            $prompt=$this->prompt($name==='older_talk' ? 2 : 3);
            $this->report("prompt_$name",['session'=>$value['session'],'loaded_context'=>$value['loaded_context']]+$prompt);
            if ($name==='older_talk') {
                $this->assertSame(0,$prompt['token'],'older anchor must not expose the heard line');
                file_put_contents($this->llmDir.'/reply','Newer load reply.');
            } else {
                $this->assertSame(1,$prompt['heard'],'newer anchor exposes the heard line exactly once');
                $this->assertSame(1,$prompt['token'],'newer prompt carries no second copy of the heard token');
            }
            return;
        }
        $this->fail("unknown phase $name");
    }
}
