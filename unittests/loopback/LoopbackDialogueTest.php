<?php declare(strict_types=1);
// Compiled-client loopback dialogue check. Outside unittests/tests, so ordinary PHPUnit discovery never runs it; it
// runs only through run-loopback-dialogue.sh, which owns the private namespaces, the disposable PostgreSQL cluster
// and the compiled client driver (SYNTH_LOOPBACK_DRIVER). Every init, context, /talk and receipt comes from the
// client over real HTTP to real main.php. Between the driver's phases it registers the actor, scripts the loopback
// provider's next reply and reads PostgreSQL and the provider requests.
//
// Saved dynamic profiles: the driver has no profile refresh, so the test sends its own profile_refresh events into
// the client's session (a second main.php instance, session capabilities widened only for that request; see
// refresh()). Real main.php admits each one and queues its batch; the production profile worker (service/manager.php
// dynamicprofile) drains it against a loopback JSON provider. The test runs no admission, queue or revision step
// itself and inserts no revision. One revision precedes the older anchor; one is published while the first /talk
// waits on its provider (the reply file is a FIFO), between the older and newer anchors; one follows the newer anchor
// and no snapshot ever acknowledges it. Each /talk prompt must carry exactly the revision its save makes visible.
require_once dirname(__DIR__).'/tests/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/synth_profile_state.php';
require_once dirname(__DIR__,2).'/core/dbmanager/repositories/CharacterRepository.php';

final class LoopbackDialogueTest extends DatabaseTestCase
{
    private const HEARD='Loopback heard token LHT7731 spoken.';
    private const TOKEN='LHT7731';
    private const BASE='Loopback settler personality.';
    private const SIBLING='Loopback sibling personality.';
    // Profile provider reply prefixes: one per revision.
    private const OLDER='LPR-OLDER';
    private const NEWER='LPR-NEWER';
    private const UNACKNOWLEDGED='LPR-UNACKNOWLEDGED';
    private array $servers=[];
    private $connection=null;
    private string $address='';
    private string $sideAddress='';
    private string $log='';
    private string $llmDir='';
    private string $llmUrl='';
    private string $profileUrl='';
    private array $environment=[];
    private int $refreshes=0;
    private bool $windowArmed=false;
    private array $identities=[];
    private array $revisions=[];
    private array $profileEvidence=[];
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
        // The profile worker's fast_request expects one non-streamed chat.completion: "<prefix> <field>." per call.
        file_put_contents($this->llmDir.'/profile_router.php',<<<'PHP'
            <?php declare(strict_types=1);
            $dir=getenv('SYNTH_LOOPBACK_LLM_DIR');
            $body=json_decode(strval(file_get_contents('php://input')),true);
            file_put_contents($dir.'/profile_requests.ndjson',json_encode($body)."\n",FILE_APPEND|LOCK_EX);
            $field=preg_match('/update ONLY the (\w+)/',strval($body['messages'][0]['content'] ?? ''),$m)===1 ? strtolower($m[1]) : 'unknown';
            header('Content-Type: application/json');
            echo json_encode(['id'=>'loopback-profile','object'=>'chat.completion','model'=>$body['model'] ?? null,'choices'=>[['index'=>0,
                'message'=>['role'=>'assistant','content'=>trim(strval(file_get_contents($dir.'/profile_prefix')))." $field."],'finish_reason'=>'stop']]]);
            PHP);
        $profile=$this->startServer($this->llmDir.'/profile_router.php',$environment+['SYNTH_LOOPBACK_LLM_DIR'=>$this->llmDir]);
        $environment['SYNTH_DB_HOST']='localhost';$environment['SYNTH_DB_NAME']='testdb';
        $environment['SYNTH_DB_USER']='dwemer';$environment['SYNTH_DB_PASSWORD']='dwemer';
        $environment['SYNTH_BACKGROUND_START_SCRIPT']='/nonexistent-disposable-background-script';
        $environment['SYNTH_BACKGROUND_PORT']='61999';
        $this->address=$this->startServer(dirname(__DIR__).'/fixtures/context_ack_router.php',$environment);
        // The cli-server is single-threaded: test-sent refreshes use their own instance so one can be admitted while
        // the client's /talk is in flight.
        $this->sideAddress=$this->startServer(dirname(__DIR__).'/fixtures/context_ack_router.php',$environment);
        $this->environment=$environment;
        $this->llmUrl='http://'.$llm.'/v1/chat/completions';
        $this->profileUrl='http://'.$profile.'/v1/chat/completions';
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
        // Dynamic profiles on for this profile only, with its own connector on the JSON provider.
        $profile=$this->query("INSERT INTO synth.core_profiles(label,tts_connector_id,llm_primary_id,metadata) VALUES('loopback profile',$1,$2,$3) RETURNING id",
            [$tts,$llm,json_encode(['DYNAMIC_PROFILE_ENABLED'=>1,'DYNAMIC_PROFILE_FIELDS'=>['personality','speechstyle']])])[0]['id'];
        $profiles=$this->query("INSERT INTO synth.core_llm_connector(label,metadata,url,model,driver,reasoning_model,max_tokens,temperature,top_p)
            VALUES('loopback profile llm','{}',$1,'loopback-profile-model','openaijson',0,200,0.3,0.8) RETURNING id",[$this->profileUrl])[0]['id'];
        $this->query("INSERT INTO synth.general_settings(id,value,description) VALUES('CORE_CONNECTOR_PROFILES',$1,'loopback')
            ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value",[strval($profiles)]);
        $this->query("INSERT INTO synth.core_npc_master(npc_name,actor_identity_id,profile_id,personality,voiceid)
            VALUES('Settler',$1,$2,$3,'loopback_voice')",[$rows[0]['id'],$profile,self::BASE]);
        // A same-named sibling in the same save, with a different plugin-local reference so the target stays admissible.
        $sibling=$this->query("INSERT INTO synth.actor_identities(game,full_form_id,origin_plugin,playthrough_id,display_name)
            VALUES('fo4','0x0001A4E1','Fallout4.esm',$1,'Settler') RETURNING id",[$actor['playthrough_id']])[0]['id'];
        $this->query("INSERT INTO synth.core_npc_master(npc_name,actor_identity_id,profile_id,personality,voiceid)
            VALUES('Settler',$1,$2,$3,'loopback_sibling_voice')",[$sibling,$profile,self::SIBLING]);
        $this->identities=['target'=>intval($rows[0]['id']),'sibling'=>intval($sibling)];
    }
    private function npcRow(int $identity): array {
        $rows=$this->query('SELECT * FROM synth.core_npc_master WHERE actor_identity_id=$1',[$identity]);
        $this->assertCount(1,$rows);
        return $rows[0];
    }
    private function canonical(string $session,string $requestId): array {
        $rows=$this->query('SELECT canonical_event FROM synth_meta.protocol_events WHERE session_id=$1 AND request_id=$2',[$session,$requestId]);
        $this->assertCount(1,$rows,"canonical $requestId");
        return json_decode($rows[0]['canonical_event'],true,64,JSON_THROW_ON_ERROR);
    }
    // One test-owned profile_refresh for the snapshot's target, admitted by real main.php into the client's session.
    // Ingress requires the session's initialized capabilities and the compiled driver does not declare
    // profiles.dynamic.refresh. A test-owned init cannot supply it: an init must advance the client's generation, which
    // cancels its in-flight /talk and makes its next requests stale, and a revision in any other session is outside the
    // client's saved ancestry. So the capability is widened for this one request only and the exact set is restored.
    private function refresh(string $session,int $sequence): array {
        $runtime=$this->query('SELECT capabilities::text AS capabilities,active_generation,runtime_variant,client_version,runtime_version
            FROM synth_meta.runtime_sessions WHERE session_id=$1',[$session])[0];
        $target=$this->query("SELECT context_payload->'target' AS target FROM synth_meta.context_snapshots
            WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3",[$session,$runtime['active_generation'],$sequence]);
        $this->assertCount(1,$target,"snapshot $session/$sequence");
        $declared=json_decode($runtime['capabilities'],true,8,JSON_THROW_ON_ERROR);
        $this->assertNotContains('profiles.dynamic.refresh',$declared,'the client never declares profile refresh');
        $capabilities=[...$declared,'profiles.dynamic.refresh'];
        $id='loopback-profile-refresh-'.++$this->refreshes;
        $event=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'profile_refresh','session_id'=>$session,'request_id'=>$id,
            'turn_id'=>$id,'generation'=>intval($runtime['active_generation']),'game'=>'fo4','runtime_variant'=>$runtime['runtime_variant'],
            'client_version'=>$runtime['client_version'],'runtime_version'=>$runtime['runtime_version'],'capabilities'=>$capabilities,
            'payload'=>['actors'=>[json_decode($target[0]['target'],true)],'include_narrator'=>false],'context_sequence'=>$sequence];
        $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$2::jsonb WHERE session_id=$1',[$session,json_encode($capabilities)]);
        try {
            $body=file_get_contents('http://'.$this->sideAddress.'/main.php',false,stream_context_create(['http'=>['method'=>'POST',
                'ignore_errors'=>true,'timeout'=>10,'header'=>"Content-Type: application/json\r\nAccept: application/x-ndjson",
                'content'=>json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]]));
        } finally {
            $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$2::jsonb WHERE session_id=$1',[$session,$runtime['capabilities']]);
        }
        $this->assertSame([['capabilities'=>$runtime['capabilities']]],$this->query('SELECT capabilities::text AS capabilities
            FROM synth_meta.runtime_sessions WHERE session_id=$1',[$session]),'declared capabilities restored');
        $this->assertIsString($body,$this->tail());
        $state=$this->query('SELECT state FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',[$session,$id]);
        $this->assertSame([['state'=>'complete']],$state,$body.$this->tail());
        // The HTTP admission itself queued exactly this owner's batch, keyed by the target's stable identity only.
        $queued=$this->query("SELECT value FROM synth.conf_opts WHERE id LIKE 'dynamic_profiles_queue_%'");
        $this->assertCount(1,$queued,'the admission queued one batch '.$body.$this->tail());
        $batch=json_decode($queued[0]['value'],true,64,JSON_THROW_ON_ERROR);
        $key='actor:'.$this->identities['target'];
        $this->assertSame([[$key],$id,[$key]],[$batch['npcs'],$batch['native_owner']['request_id'],array_keys($batch['native_bindings'])]);
        $this->profileEvidence['admission_queued'][$id]=$key;
        return $event;
    }
    // One fresh PHP CLI process in the server tree with the runtime environment; bounded, and it must exit 0.
    private function runPhp(array $command,string $label): void {
        $output=$this->llmDir."/$label.out";$pipes=[];
        $argv=[PHP_BINARY,...$command];
        $process=proc_open($argv,[0=>['pipe','r'],1=>['file',$output,'w'],2=>['file',$output,'a']],$pipes,dirname(__DIR__,2),$this->environment);
        $this->assertIsResource($process);fclose($pipes[0]);
        $deadline=hrtime(true)+15_000_000_000;
        while (($status=proc_get_status($process))['running'] && hrtime(true)<$deadline) usleep(20000);
        if ($status['running']) proc_terminate($process,9);
        proc_close($process);
        $this->assertFalse($status['running'],"$label exceeded 15 s ".file_get_contents($output));
        $this->assertSame(0,$status['exitcode'],"$label ".file_get_contents($output));
    }
    // The production worker in a fresh process (manager.php's dynamicprofile task, inline), then its one revision.
    private function publish(array $refresh,string $prefix): array {
        file_put_contents($this->llmDir.'/profile_prefix',$prefix);
        $this->runPhp(['-d','disable_functions=pcntl_fork','service/manager.php','dynamicprofile'],'profile_worker');
        $diagnostic=strval(file_get_contents($this->llmDir.'/profile_worker.out')).json_encode($this->query("SELECT state,attempts,last_error
            FROM synth_meta.worker_jobs WHERE job_type='profile' AND session_id=$1 AND request_id=$2",[$refresh['session_id'],$refresh['request_id']]));
        $rows=$this->query('SELECT history_event_id,prior_history_event_id,actor_identity_id,profile_role,context_sequence,result_fields
            FROM synth_meta.profile_revisions WHERE session_id=$1 AND request_id=$2',[$refresh['session_id'],$refresh['request_id']]);
        $this->assertCount(1,$rows,'one accepted revision '.$diagnostic);
        $this->assertSame([],$this->query("SELECT id FROM synth.conf_opts WHERE id LIKE 'dynamic_profiles_queue_%'"),'the worker drained the batch');
        $this->assertSame([strval($this->identities['target']),'npc',strval($refresh['context_sequence'])],
            [$rows[0]['actor_identity_id'],$rows[0]['profile_role'],$rows[0]['context_sequence']]);
        $fields=json_decode($rows[0]['result_fields'],true,4,JSON_THROW_ON_ERROR);
        $this->assertSame(["$prefix personality.","$prefix speechstyle."],[$fields['personality'],$fields['speechstyle']]);
        return ['id'=>intval($rows[0]['history_event_id']),'prior'=>$rows[0]['prior_history_event_id']===null ? null : intval($rows[0]['prior_history_event_id']),
            'request_id'=>$refresh['request_id'],'context_sequence'=>$refresh['context_sequence'],'fields'=>synth_profile_fields($fields)];
    }
    // The first /talk is held at its provider (a blocked FIFO read) after the client acknowledged the older anchor:
    // a revision published now lies after the older anchor's frontier and before the newer one's.
    private function window(string $session,int $sequence): void {
        $fifo=$this->llmDir.'/reply';$requests=$this->llmDir.'/requests.ndjson';
        $deadline=hrtime(true)+10_000_000_000;
        while (!is_file($requests) && hrtime(true)<$deadline) usleep(10000);
        try {
            $this->assertFileExists($requests,'the first /talk reached its provider');
            $this->revisions['newer']=$this->publish($this->refresh($session,$sequence),self::NEWER);
        } finally {
            // Release the provider (only once it is reading, so this cannot block on a FIFO nobody opens).
            if (is_file($requests)) file_put_contents($fifo,self::HEARD);
            unlink($fifo);
        }
    }
    // The native repository's strict read and the ordinary route's projection for one /talk, over the test process's
    // testdb sql adapter (the same execQuery/fetchOne failure contract as production sql).
    private function savedReads(array $event): array {
        $db=new sql();
        $repository=new \Synth\Core\CharacterRepository($db);
        $globals=[$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null,$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null];
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        try {
            $legacy=[];
            foreach ($this->identities as $name=>$identity) $legacy[$name]=synth_profile_fields(synth_profile_effective_data($db,$this->npcRow($identity)));
        } finally {
            unset($GLOBALS['SYNTH_REQUEST_EVENT'],$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
            if ($globals[0]!==null) $GLOBALS['SYNTH_REQUEST_EVENT']=$globals[0];
            if ($globals[1]!==null) $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$globals[1];
        }
        return ['native'=>$repository->savedProfile($event,$this->identities['target']),
            'sibling'=>$repository->savedProfile($event,$this->identities['sibling']),'legacy'=>$legacy];
    }
    // The prompt carries exactly the expected revision (or the base profile); no other revision or the sibling leaks.
    private function profilePrompt(string $label,array $prompt,?string $expected,array $event): void {
        $messages=json_encode($prompt['messages'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $visible=$expected===null ? null : $this->revisions[$expected];
        foreach ([self::OLDER=>'older',self::NEWER=>'newer',self::UNACKNOWLEDGED=>'unacknowledged'] as $prefix=>$name) {
            if ($name!==$expected) $this->assertStringNotContainsString($prefix,$messages,"$label prompt");
        }
        if ($visible!==null) {
            $this->assertStringContainsString('Personality: '.$visible['fields']['personality'],$prompt['messages'][0]['content'],$label);
            $this->assertStringContainsString('Speechstyle: '.$visible['fields']['speechstyle'],$prompt['messages'][0]['content'],$label);
            $this->assertStringNotContainsString(self::BASE,$messages,"$label base personality");
        } else $this->assertStringContainsString('Personality: '.self::BASE,$prompt['messages'][0]['content'],$label);
        $this->assertStringNotContainsString(self::SIBLING,$messages,"$label sibling");
        $reads=$this->savedReads($event);
        $base=synth_profile_fields($this->npcRow($this->identities['target']));
        $this->assertSame($visible===null ? null : ['revision'=>$visible['id'],'fields'=>$visible['fields']],$reads['native'],"$label native read");
        $this->assertSame($visible['fields'] ?? $base,$reads['legacy']['target'],"$label ordinary route parity");
        $this->assertNull($reads['sibling'],"$label sibling native read");
        $this->assertSame(synth_profile_fields($this->npcRow($this->identities['sibling'])),$reads['legacy']['sibling'],"$label sibling ordinary route");
        $this->profileEvidence[$label]=['expected'=>$expected,'native_revision'=>$reads['native']['revision'] ?? null,
            'legacy_personality'=>$reads['legacy']['target']['personality'],'sibling'=>$reads['sibling']];
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
                // The first context ACK after the context phase is the older anchor; its /talk is next.
                if ($this->windowArmed && str_starts_with($line,'LOOPBACK ')) {
                    $evidence=json_decode(substr($line,9),true,32,JSON_THROW_ON_ERROR);
                    if (($evidence['kind'] ?? '')==='context_ack') {
                        $this->windowArmed=false;
                        $this->window($evidence['session'],intval($evidence['sequence']));
                    }
                }
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
        // Every client request (and the three test-sent refreshes) was claimed and completed by real main.php.
        $requests=$this->query("SELECT event_type,count(*) AS n FROM synth_meta.request_executions WHERE state='complete' GROUP BY event_type ORDER BY event_type");
        $this->report('requests',$requests);
        $this->assertSame(16,intval($this->query('SELECT count(*) AS n FROM synth_meta.request_executions')[0]['n']));
        $this->assertSame(['context'=>5,'init'=>3,'input_text'=>3,'profile_refresh'=>3,'speech_receipt'=>2],
            array_map('intval',array_column($requests,'n','event_type')));
        // Exactly the three published revisions, one chain, two provider calls each; base rows never written.
        $revisions=$this->query('SELECT history_event_id,prior_history_event_id,request_id FROM synth_meta.profile_revisions ORDER BY history_event_id');
        $this->assertSame([[strval($this->revisions['older']['id']),null,$this->revisions['older']['request_id']],
            [strval($this->revisions['newer']['id']),strval($this->revisions['older']['id']),$this->revisions['newer']['request_id']],
            [strval($this->revisions['unacknowledged']['id']),strval($this->revisions['newer']['id']),$this->revisions['unacknowledged']['request_id']]],
            array_map('array_values',$revisions));
        $this->assertCount(6,array_filter(explode("\n",strval(file_get_contents($this->llmDir.'/profile_requests.ndjson')))));
        $this->assertSame([self::BASE,self::SIBLING],[$this->npcRow($this->identities['target'])['personality'],
            $this->npcRow($this->identities['sibling'])['personality']]);
        $this->report('profiles',['identities'=>$this->identities,'revisions'=>array_map(static fn($r)=>array_diff_key($r,['fields'=>0]),$this->revisions),
            'reads'=>$this->profileEvidence]);
    }

    private function phase(string $name,array $value): void {
        if ($name==='context') {
            $this->registerActor(['form_id'=>$value['target_form'],'playthrough_id'=>$value['playthrough_id']]);
            $this->assertFileDoesNotExist($this->llmDir.'/requests.ndjson','no provider call before the first /talk');
            // Published now: before the older anchor's frontier, so every later save sees it until superseded.
            $this->revisions['older']=$this->publish($this->refresh($value['session'],intval($value['sequence'])),self::OLDER);
            $this->assertNull($this->revisions['older']['prior']);
            // The first /talk's reply is released by window() once that /talk is waiting on its provider.
            $this->assertTrue(posix_mkfifo($this->llmDir.'/reply',0600));
            $this->windowArmed=true;
            return;
        }
        if ($name==='checkpoint') {
            $session=$value['session'];
            $first=$this->prompt(1);
            $this->assertSame(0,$first['token'],'the first /talk prompt precedes its own line');
            $this->assertArrayHasKey('newer',$this->revisions,'a revision was published while the first /talk was in flight');
            $this->profilePrompt('first_talk',$first,'older',$this->canonical($session,$value['dialogue_request_id']));
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
            // Accepted after the newer anchor; no snapshot of this session will ever acknowledge it.
            $this->revisions['unacknowledged']=$this->publish($this->refresh($session,intval($value['newer_anchor']['context_sequence'])),self::UNACKNOWLEDGED);
            $this->assertSame([$this->revisions['older']['id'],$this->revisions['newer']['id']],
                [$this->revisions['newer']['prior'],$this->revisions['unacknowledged']['prior']],'one revision chain');
            $order=[$this->revisions['older']['id'],$older,$this->revisions['newer']['id'],$newer,$this->revisions['unacknowledged']['id']];
            $this->report('revision_frontiers',array_combine(['older_revision','older_anchor','newer_revision','newer_anchor','unacknowledged_revision'],$order));
            $this->assertLessThanOrEqual($older,$order[0],'the older revision precedes the older anchor');
            $this->assertGreaterThan($older,$order[2],'the newer revision follows the older anchor');
            $this->assertLessThanOrEqual($newer,$order[2],'the newer revision precedes the newer anchor');
            $this->assertGreaterThan($newer,$order[4],'the unacknowledged revision follows the newer anchor');
            file_put_contents($this->llmDir.'/reply','Older load reply.');
            return;
        }
        if ($name==='older_talk' || $name==='newer_talk') {
            $prompt=$this->prompt($name==='older_talk' ? 2 : 3);
            $this->report("prompt_$name",['session'=>$value['session'],'loaded_context'=>$value['loaded_context']]+$prompt);
            $this->profilePrompt($name,$prompt,$name==='older_talk' ? 'older' : 'newer',$this->canonical($value['session'],$value['request_id']));
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
