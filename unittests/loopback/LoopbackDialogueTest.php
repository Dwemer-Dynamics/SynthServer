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
//
// testCompiledClientCompanionLifecycle is an independent run of the same driver in its "companion" scenario (see the
// companion section below): Piper with SYNTHETIC captured state and a SIMULATED "unavailable" executor outcome.
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
    // Companion scenario: the driver's fixture companion and its labelled SIMULATED executor outcome.
    private const PIPER='0x00002F1F';
    private const SIMULATED='SIMULATED loopback executor: no game runtime, nothing was executed';
    private const QUESTION='Piper, what do you make of this place?';
    private const CONVERSATION='Piper, tell me about Diamond City.';
    private const SUPERSEDING='Piper, are you still with me?';
    private const COMMAND='Wait here.';
    // The legacy openaijson connector's response instruction; the native prompt never carries it.
    private const LEGACY_JSON='Use only this JSON object';
    private const WAIT_OBSERVED='[Native Wait observer for Piper] The fresh selected-actor capture confirms no SYNTH-owned Wait hold.';
    private const WAIT_UNVERIFIED='This result does not establish a verified Wait or Release postcondition';
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
    // Companion scenario: Piper's identity and seeded profile, driver evidence by kind, provider requests already seen.
    private array $companion=[];
    private array $driverEvidence=[];
    private int $providerSeen=0;
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
        $requests=$this->providerRequests();
        $this->assertCount($expected,$requests,'exactly one provider call per /talk');
        $messages=json_encode(end($requests)['messages'] ?? null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->assertNotSame('null',$messages);
        return ['heard'=>substr_count($messages,self::HEARD),'token'=>substr_count($messages,self::TOKEN),
            'sha256'=>hash('sha256',$messages),'messages'=>json_decode($messages,true)];
    }
    // Every request body the loopback dialogue provider has received, in order.
    private function providerRequests(): array {
        $file=$this->llmDir.'/requests.ndjson';
        return is_file($file) ? array_map(static fn($l)=>json_decode($l,true),array_values(array_filter(explode("\n",file_get_contents($file))))) : [];
    }
    private function frontier(array $anchor): int {
        $rows=$this->query('SELECT history_event_id FROM synth_meta.context_snapshots WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
            [$anchor['session_id'],$anchor['generation'],$anchor['context_sequence']]);
        $this->assertCount(1,$rows,'snapshot '.json_encode($anchor));
        return intval($rows[0]['history_event_id']);
    }

    // Runs the compiled driver against the main server. Every stdout line goes to $line in order (before a PHASE line
    // is handled); each PHASE line goes to $phase and is then answered with GO. Returns the phases once the driver has
    // printed DONE and exited 0.
    private function runDriver(array $arguments,callable $phase,?callable $line=null): array {
        $driver=getenv('SYNTH_LOOPBACK_DRIVER');
        $this->assertIsString($driver);$this->assertFileIsReadable($driver);
        $stderr=tempnam(sys_get_temp_dir(),'synth-loopback-driver-');
        $pipes=[];
        // stderr goes to a file, so a chatty driver can never block on a full pipe the test is not reading.
        $process=proc_open([$driver,'http://'.$this->address,...$arguments],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$stderr,'w']],$pipes,
            sys_get_temp_dir(),['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        $this->assertIsResource($process);
        $phases=[];$done=false;$buffer='';
        try {
            stream_set_blocking($pipes[1],false);
            // Every line is bounded: the driver's own HTTP timeouts are shorter than this wait.
            while (($text=$this->readLine($pipes[1],$buffer))!==null) {
                fwrite(STDERR,"DRIVER $text\n");
                if ($text==='DONE') {$done=true;continue;}
                $this->assertStringStartsNotWith('FAILED',$text,$this->tail());
                if ($line!==null) $line($text);
                if (!str_starts_with($text,'PHASE ')) continue;
                [,$name,$json]=explode(' ',$text,3);
                $phases[]=$name;
                $phase($name,json_decode($json,true,32,JSON_THROW_ON_ERROR));
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
        return $phases;
    }

    public function testCompiledClientLoopbackDialogue(): void {
        $phases=$this->runDriver([],$this->phase(...),function(string $line): void {
            // The first context ACK after the context phase is the older anchor; its /talk is next.
            if ($this->windowArmed && str_starts_with($line,'LOOPBACK ')) {
                $evidence=json_decode(substr($line,9),true,32,JSON_THROW_ON_ERROR);
                if (($evidence['kind'] ?? '')==='context_ack') {
                    $this->windowArmed=false;
                    $this->window($evidence['session'],intval($evidence['sequence']));
                }
            }
        });
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
            $serialized=json_encode($prompt['messages'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString($name==='older_talk' ? 'Strength 4/5' : 'Strength 4/6',$serialized);
            $this->assertStringNotContainsString('Strength 4/4',$serialized,'saved actor stats cannot replace fresh capture');
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

    // ---- Companion scenario: an independent run of the same driver with the second argument "companion" ----
    // The driver's captured actor state is SYNTHETIC and its executor outcome is SIMULATED ("unavailable"); nothing runs
    // in a game. Every init, context, activate, question, conversation, command and result below is the compiled client's
    // over real HTTP to real main.php. The test only seeds loopback connectors and a default_npc profile, scripts the
    // loopback provider's reply file between phases and reads PostgreSQL and the provider requests.

    public function testCompiledClientCompanionLifecycle(): void {
        $phases=$this->runDriver(['companion'],$this->companionPhase(...),function(string $line): void {
            if (!str_starts_with($line,'LOOPBACK ')) return;
            $evidence=json_decode(substr($line,9),true,32,JSON_THROW_ON_ERROR);
            $this->driverEvidence[strval($evidence['kind'] ?? '')][]=$evidence;
        });
        $this->assertSame(['companion_context','companion_question','companion_conversation','companion_action','companion_result',
            'companion_done'],$phases);
        $this->report('companion',['synthetic_state'=>'driver-captured Piper actor state and scene are SYNTHETIC',
            'executor'=>'SIMULATED unavailable result; no physical Wait was executed or verified',
            'identity'=>$this->companion['piper'],'npc'=>$this->companion['npc'],'profile'=>$this->companion['profile'],
            'provider_calls'=>$this->companion['provider_calls'],'accounting'=>$this->companion['accounting']]);
    }

    private function companionPhase(string $name,array $value): void {
        match ($name) {
            'companion_context'=>$this->companionContext($value),
            'companion_question'=>$this->companionQuestion($value),
            'companion_conversation'=>$this->companionConversation($value),
            'companion_action'=>$this->companionAction($value),
            'companion_result'=>$this->companionResult($value),
            'companion_done'=>$this->companionDone($value),
            default=>$this->fail("unknown companion phase $name"),
        };
    }

    private static function actorKey($actor): ?array {
        return is_array($actor) ? [strtoupper(strval($actor['form_id'] ?? '')),strtolower(strval($actor['origin_plugin'] ?? '')),
            $actor['playthrough_id'] ?? null] : null;
    }
    private function execution(string $session,string $request): array {
        return $this->query('SELECT event_type,state,error_code FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',
            [$session,$request]);
    }
    private function completed(string $session,string $request,string $type): void {
        $this->assertSame([['event_type'=>$type,'state'=>'complete','error_code'=>null]],$this->execution($session,$request),"$type $request ".$this->tail());
    }
    // A refusal the client reported happened before any claim: no execution and no canonical event exist.
    private function neverClaimed(string $session,string $request,string $label): void {
        $this->assertNotSame('',$request,"$label request id");
        $this->assertSame([],$this->execution($session,$request),"$label was never claimed");
        $this->assertSame([],$this->query('SELECT id FROM synth_meta.protocol_events WHERE session_id=$1 AND request_id=$2',[$session,$request]));
    }
    private function utterances(string $session,?string $request=null): array {
        return $this->query('SELECT request_id,line_id,generation,context_sequence,speaker_identity_id,text FROM synth_meta.dialogue_utterances
            WHERE session_id=$1 AND ($2::text IS NULL OR request_id=$2) ORDER BY created_at,request_id',[$session,$request]);
    }
    private function evidenceOf(string $kind): array {
        $this->assertArrayHasKey($kind,$this->driverEvidence,"driver evidence $kind");
        $this->assertCount(1,$this->driverEvidence[$kind],"one $kind evidence line");
        return $this->driverEvidence[$kind][0];
    }
    private function script(string $reply): void {file_put_contents($this->llmDir.'/reply',$reply);}
    // One legacy openaijson answer for Piper. An empty action keeps the turn speech-only.
    private function ordinaryReply(string $message,string $action=''): string {
        return json_encode(['character'=>'Piper','listener'=>'Sole Survivor','message'=>$message,'action'=>$action,'target'=>''],
            JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    // Provider requests since the previous call, each as its encoded messages.
    private function newProviderRequests(): array {
        $all=$this->providerRequests();
        $new=array_slice($all,$this->providerSeen);$this->providerSeen=count($all);
        return array_map(static fn($request)=>json_encode($request['messages'] ?? null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$new);
    }
    // The scripted answer's own request comes first in its window, carries its player text and has its route's shape:
    // the legacy openaijson instruction, or a native prompt without it. Only scripted answers are counted exactly.
    private function scriptedRequest(string $label,string $text,bool $legacy,bool $exactlyOne=false): string {
        $new=$this->newProviderRequests();
        $this->companion['provider_calls'][$label]=count($new);
        $this->assertNotEmpty($new,"$label made no provider call ".$this->tail());
        if ($exactlyOne) $this->assertCount(1,$new,"$label: exactly one provider call");
        $this->assertStringContainsString($text,$new[0],"$label prompt carries its player text");
        if ($legacy) $this->assertStringContainsString(self::LEGACY_JSON,$new[0],"$label stays on the legacy route");
        else $this->assertStringNotContainsString(self::LEGACY_JSON,$new[0],"$label is a native turn");
        return $new[0];
    }
    // Loopback connectors and one default_npc profile only. No NPC row is inserted for Piper: the server's ordinary
    // route must create it from her captured identity and this default profile.
    private function seedCompanionProfile(): int {
        $llm=$this->query("INSERT INTO synth.core_llm_connector(label,metadata,url,model,driver,reasoning_model,max_tokens,temperature,top_p)
            VALUES('loopback companion llm','{}',$1,'loopback-model','openaijson',0,300,0.3,0.8) RETURNING id",[$this->llmUrl])[0]['id'];
        $tts=$this->query("INSERT INTO synth.core_tts_connector(driver,label,metadata,url)
            VALUES('xtts-fastapi','loopback companion tts','{}','http://127.0.0.1:9') RETURNING id")[0]['id'];
        $this->query("UPDATE synth.core_profiles SET default_npc='0' WHERE default_npc='1'");
        $profile=intval($this->query("INSERT INTO synth.core_profiles(label,default_npc,tts_connector_id,llm_primary_id,metadata)
            VALUES('loopback companion default','1',$1,$2,'{}') RETURNING id",[$tts,$llm])[0]['id']);
        $this->assertSame([['id'=>strval($profile)]],$this->query("SELECT id FROM synth.core_profiles WHERE default_npc='1'"));
        return $profile;
    }

    private function companionContext(array $value): void {
        $this->assertTrue($value['synthetic_state'],'the driver labels its captured actor state SYNTHETIC');
        $session=$value['session'];$playthrough=$value['playthrough_id'];
        $this->assertSame([1,strtoupper(self::PIPER),'Piper'],[$value['generation'],strtoupper($value['target_form']),$value['target_name']]);
        $this->completed($session,$value['context_request_id'],'context');
        $runtime=$this->query('SELECT active_generation,runtime_variant,state,capabilities::text AS capabilities FROM synth_meta.runtime_sessions
            WHERE session_id=$1',[$session]);
        $this->assertSame(['1','flat','active'],[$runtime[0]['active_generation'],$runtime[0]['runtime_variant'],$runtime[0]['state']]);
        $capabilities=json_decode($runtime[0]['capabilities'],true,8,JSON_THROW_ON_ERROR);
        foreach (['dialogue.native_turn','dialogue.turn_ownership','dialogue.turn_cancel','action.wait_here','action.release_wait',
            'action.wait_verified','context.wait_state'] as $capability) $this->assertContains($capability,$capabilities);
        $this->assertNotContains('action.inventory_observation',$capabilities,'no inventory observation is negotiated');
        // Piper's identity comes from the client's context, keyed by form, origin plugin and playthrough.
        $identity=$this->query("SELECT id,display_name FROM synth.actor_identities WHERE game='fo4' AND upper(full_form_id)=upper($1)
            AND lower(origin_plugin)='fallout4.esm' AND playthrough_id=$2",[self::PIPER,$playthrough]);
        $this->assertCount(1,$identity,'Piper identity');
        $this->assertSame('Piper',$identity[0]['display_name']);
        $piper=intval($identity[0]['id']);$key=[strtoupper(self::PIPER),'fallout4.esm',$playthrough];
        $snapshot=$this->query('SELECT target_identity_id,playthrough_id,context_payload FROM synth_meta.context_snapshots
            WHERE session_id=$1 AND generation=1 AND snapshot_sequence=$2',[$session,intval($value['sequence'])]);
        $this->assertCount(1,$snapshot);
        $this->assertSame([strval($piper),$playthrough],[$snapshot[0]['target_identity_id'],$snapshot[0]['playthrough_id']]);
        $payload=json_decode($snapshot[0]['context_payload'],true,64,JSON_THROW_ON_ERROR);
        $this->assertSame([$key,'Piper'],[self::actorKey($payload['target'] ?? null),$payload['target']['display_name'] ?? null]);
        $this->assertSame([$key],array_map(self::actorKey(...),$payload['audience'] ?? []),'audience');
        $this->assertSame('complete',$payload['audience_observation'] ?? null);
        // The SYNTHETIC captured facts, persisted as sent.
        $this->assertCount(1,$payload['actor_states'] ?? [],'one captured actor state');
        $state=$payload['actor_states'][0];
        $facts=['actor'=>self::actorKey($state['actor'] ?? null),'base_form_id'=>strtoupper(strval($state['base_form_id'] ?? '')),
            'base_origin_plugin'=>$state['base_origin_plugin'] ?? null,'alive'=>$state['alive'] ?? null,'level'=>$state['level'] ?? null,
            'race'=>$state['race'] ?? null,'sex'=>$state['sex'] ?? null,'teammate'=>$state['teammate'] ?? null,
            'line_of_sight'=>$state['line_of_sight'] ?? null,'life_state'=>$state['life_state'] ?? null,'wait_held'=>$state['wait_held'] ?? null];
        $this->assertSame(['actor'=>$key,'base_form_id'=>'0X00002F1E','base_origin_plugin'=>'Fallout4.esm','alive'=>true,'level'=>12,
            'race'=>'HumanRace','sex'=>'female','teammate'=>true,'line_of_sight'=>'visible','life_state'=>'alive','wait_held'=>false],$facts);
        $this->assertSame([],$this->query('SELECT id FROM synth.core_npc_master WHERE actor_identity_id=$1',[$piper]),'no NPC row for Piper yet');
        $this->assertSame([],$this->providerRequests(),'no provider call before the first question');
        $this->companion=['session'=>$session,'playthrough'=>$playthrough,'piper'=>$piper,'key'=>$key,
            'profile'=>$this->seedCompanionProfile(),'npc'=>null,'provider_calls'=>[],'accounting'=>[]];
        $this->script($this->ordinaryReply('Loopback companion answer CMP4400.'));
        $this->report('companion_context',['synthetic_state'=>true,'identity'=>$piper,'facts'=>$facts]);
    }

    private function companionQuestion(array $value): void {
        ['session'=>$session,'piper'=>$piper,'key'=>$key,'profile'=>$profile]=$this->companion;
        $this->assertSame($session,$value['session']);
        // The manual activation bound to context 1.
        $this->completed($session,$value['activate_request_id'],'activate');
        $activation=$this->canonical($session,$value['activate_request_id']);
        $this->assertSame(['manual',$key],[$activation['payload']['source'] ?? null,self::actorKey($activation['payload']['actor'] ?? null)]);
        $this->assertSame([['generation'=>'1','actor_identity_id'=>strval($piper),'source'=>'manual','state'=>'active']],
            $this->query('SELECT generation,actor_identity_id,source,state FROM synth_meta.active_agents WHERE session_id=$1',[$session]));
        // The ordinary route created Piper's NPC row, bound to her identity with the default profile.
        $npc=$this->query('SELECT id,npc_name,profile_id FROM synth.core_npc_master WHERE actor_identity_id=$1',[$piper]);
        $this->assertCount(1,$npc,'server-created Piper NPC row '.$this->tail());
        $this->assertSame(['Piper',strval($profile)],[$npc[0]['npc_name'],$npc[0]['profile_id']]);
        $this->companion['npc']=intval($npc[0]['id']);
        // Unmarked question: legacy route, no native utterance, the scripted ordinary JSON answer.
        $this->completed($session,$value['request_id'],'input_text');
        $event=$this->canonical($session,$value['request_id']);
        $this->assertSame(self::QUESTION,$event['payload']['text']);
        $this->assertArrayNotHasKey('intent',$event['payload'],'the question is unmarked');
        $this->assertSame([],$this->utterances($session,$value['request_id']),'the legacy route records no native utterance');
        $this->assertGreaterThanOrEqual(1,$value['dialogue_lines']);
        $prompt=$this->scriptedRequest('question',self::QUESTION,true);
        $this->assertStringContainsString('Strength 0/1.5',$prompt);
        $this->assertStringContainsString('Luck unknown',$prompt);
        $this->assertSame(1,substr_count($prompt,'Investigation, interviewing, writing and public advocacy.'));
        $this->assertStringNotContainsString('actor_stats',$prompt);
        $this->report('biography_ordinary_prompt',['bytes'=>strlen($prompt),'prompt'=>$prompt]);
        $this->script('Loopback companion reply CMP4410.');
        $this->report('companion_question',['npc'=>$this->companion['npc'],'request_id'=>$value['request_id'],
            'wait_observer'=>str_contains($prompt,self::WAIT_OBSERVED)]);
    }

    private function companionConversation(array $value): void {
        ['session'=>$session,'piper'=>$piper,'playthrough'=>$playthrough,'npc'=>$npcId,'profile'=>$profile]=$this->companion;
        $this->completed($session,$value['request_id'],'input_text');
        $event=$this->canonical($session,$value['request_id']);
        $this->assertSame([self::CONVERSATION,'conversation'],[$event['payload']['text'],$event['payload']['intent'] ?? null]);
        // Native route: exactly one utterance spoken by Piper's identity, carrying the scripted reply.
        $this->assertSame([['request_id'=>$value['request_id'],'line_id'=>$value['line_id'],'generation'=>'1',
            'context_sequence'=>strval($value['context_sequence']),'speaker_identity_id'=>strval($piper),'text'=>$value['text']]],
            $this->utterances($session,$value['request_id']));
        $this->assertStringContainsString('CMP4410',$value['text']);
        $prompt=$this->scriptedRequest('conversation',self::CONVERSATION,false,true);
        foreach (['Short description:','Background:','Appearance:','Personality:','Occupation:','Speechstyle:','Goals:',
            'Strength 0/1.5','Luck unknown'] as $section) $this->assertStringContainsString($section,$prompt);
        $this->assertSame(1,substr_count($prompt,'Investigation, interviewing, writing and public advocacy.'));
        $this->report('biography_native_prompt',['bytes'=>strlen($prompt),'prompt'=>$prompt]);
        // The native turn resolved the server-created row and profile, and no saved dynamic profile replaced it.
        $repository=new \Synth\Core\CharacterRepository(new sql());
        $registered=$repository->registered(self::PIPER,'Fallout4.esm',$playthrough);
        $this->assertSame([strval($piper),strval($npcId),strval($profile)],
            [$registered['identity_id'] ?? null,$registered['npc_id'] ?? null,$registered['profile_id'] ?? null]);
        $this->assertNull($repository->savedProfile($event,$piper),'no saved dynamic profile for Piper');
        $this->script($this->ordinaryReply('Alright, I will wait here.','Wait_Here'));
    }

    private function companionAction(array $value): void {
        ['session'=>$session,'piper'=>$piper]=$this->companion;
        // Local negatives the driver ran with the shipped validator: SYNTHETIC scenes, never sent.
        $negatives=$this->evidenceOf('companion_wait_negatives');
        $this->assertSame([true,true,true,0,true],[$negatives['absent_actor'],$negatives['other_plugin'],$negatives['vr_scene'],
            $negatives['vr_capabilities'],$negatives['cancelled_turn']]);
        $this->assertSame(1,$this->evidenceOf('companion_command')['actions']);
        $this->assertSame(['wait_here',strtoupper(self::PIPER)],[$value['name'],strtoupper($value['actor_form'])]);
        // Unmarked "Wait here.": legacy route, no native utterance, exactly one pending intent owned by Piper.
        $this->completed($session,$value['request_id'],'input_text');
        $event=$this->canonical($session,$value['request_id']);
        $this->assertSame(self::COMMAND,$event['payload']['text']);
        $this->assertArrayNotHasKey('intent',$event['payload'],'the command is unmarked');
        $this->assertSame([],$this->utterances($session,$value['request_id']),'the legacy route records no native utterance');
        $intents=$this->query('SELECT action_id,idempotency_key,request_id,turn_id,generation,action_name,capability,actor_identity_id,
                target_identity_id,arguments::text AS arguments,state,deadline_at>created_at AS deadline
            FROM synth_meta.action_intents WHERE session_id=$1',[$session]);
        $this->assertSame([['action_id'=>$value['action_id'],'idempotency_key'=>$value['idempotency_key'],'request_id'=>$value['request_id'],
            'turn_id'=>$value['turn_id'],'generation'=>'1','action_name'=>'WaitHere','capability'=>'action.wait_here',
            'actor_identity_id'=>strval($piper),'target_identity_id'=>null,'arguments'=>'{}','state'=>'pending','deadline'=>'t']],$intents,$this->tail());
        $this->assertSame([],$this->query('SELECT action_id FROM synth_meta.action_terminal_results'));
        // The scripted model Wait answer, prompted with the Wait observer's reading of the captured wait_held=false.
        $prompt=$this->scriptedRequest('command',self::COMMAND,true);
        $this->assertStringContainsString(self::WAIT_OBSERVED,$prompt,'the Wait prompt observer consumed the captured state');
        // The prompt offers the catalog's public label Wait_Here; the scripted answer uses it and the persisted intent keeps the canonical WaitHere code.
        $this->assertStringContainsString('AVAILABLE ACTION: Wait_Here (',$prompt,'Wait_Here is offered');
        $this->companion['action']=$value;
        // Any continuation the result requests stays speech-only.
        $this->script($this->ordinaryReply('Understood, Blue.'));
    }

    private function companionResult(array $value): void {
        ['session'=>$session,'action'=>$action]=$this->companion;
        $this->assertSame([true,'unavailable',$action['action_id']],[$value['simulated'],$value['status'],$value['action_id']]);
        // The SIMULATED outcome sent twice: two claims, identical results, both complete.
        $ids=$value['result_request_ids'];
        $this->assertCount(2,$ids);$this->assertNotSame($ids[0],$ids[1]);
        $results=[];
        foreach ($ids as $id) {
            $this->completed($session,$id,'action_result');
            $results[]=$this->canonical($session,$id)['payload']['result'];
        }
        $this->assertSame($results[0],$results[1],'identical duplicate results');
        $this->assertSame([$action['action_id'],$action['idempotency_key'],'unavailable',self::SIMULATED],
            [$results[0]['action_id'],$results[0]['idempotency_key'],$results[0]['status'],$results[0]['detail']]);
        // The contradicting "failed" result was refused before any claim.
        $conflict=$this->evidenceOf('companion_conflict');
        $this->assertSame([$value['conflict_request_id'],'HTTP 409: action_result_conflict'],[$conflict['request_id'],$conflict['detail']]);
        $this->neverClaimed($session,$value['conflict_request_id'],'conflicting result');
        // One terminal result, the intent complete, and one unverified Wait consequence: no physical Wait is claimed.
        $this->assertSame([['status'=>'unavailable','reported'=>'unavailable','detail'=>self::SIMULATED]],
            $this->query("SELECT status,result->>'status' AS reported,result->>'detail' AS detail FROM synth_meta.action_terminal_results
                WHERE action_id=$1",[$action['action_id']]));
        $this->assertSame([['state'=>'complete']],$this->query('SELECT state FROM synth_meta.action_intents WHERE action_id=$1',[$action['action_id']]));
        $consequence=$this->query("SELECT request_id,event_payload#>>'{synth_wait_consequence,verified}' AS verified,
                event_payload#>>'{synth_wait_consequence,result,status}' AS status,
                (event_payload->'synth_wait_consequence') ? 'proof' AS proof,event_payload->>'data' AS message
            FROM synth_meta.history_events WHERE session_id=$1 AND event_payload ? 'synth_wait_consequence'",[$session]);
        $this->assertCount(1,$consequence,'one Wait consequence');
        $this->assertSame([$ids[0],'false','unavailable','f'],[$consequence[0]['request_id'],$consequence[0]['verified'],
            $consequence[0]['status'],$consequence[0]['proof']]);
        $this->assertStringContainsString('No successful physical waiting or release was verified',$consequence[0]['message']);
        // Unscripted continuations are counted, not required. Any continuation prompt sees only an unverified receipt.
        $continuations=$this->newProviderRequests();
        $this->companion['provider_calls']['result_continuations']=count($continuations);
        foreach ($continuations as $prompt) $this->assertStringNotContainsString('was verified to enter',$prompt);
        if ($continuations!==[]) $this->assertStringContainsString(self::WAIT_UNVERIFIED,$continuations[0],'the result observer reports an unverified receipt');
        $this->script('Loopback companion reply CMP4420.');
        $this->report('companion_result',['executor'=>'SIMULATED','status'=>'unavailable','verified'=>false,'result_request_ids'=>$ids,
            'continuations'=>count($continuations)]);
    }

    private function companionDone(array $value): void {
        ['session'=>$session,'piper'=>$piper,'action'=>$action]=$this->companion;
        $this->assertSame(2,$value['generation']);
        // The superseding conversation is a native Piper turn with the scripted reply.
        $this->completed($session,$value['superseding_request_id'],'input_text');
        $superseding=$this->utterances($session,$value['superseding_request_id']);
        $this->assertCount(1,$superseding);
        $this->assertSame(strval($piper),$superseding[0]['speaker_identity_id']);
        $this->assertStringContainsString('CMP4420',$superseding[0]['text']);
        $this->scriptedRequest('superseding',self::SUPERSEDING,false,true);
        // Server refusals the driver reported: the stale result and the conversation bound to the cancelled context.
        $stale=$this->evidenceOf('companion_stale');
        $this->assertSame([$value['stale_request_id'],'HTTP 409: superseded_turn'],[$stale['request_id'],$stale['detail']]);
        $this->neverClaimed($session,$value['stale_request_id'],'superseded result');
        $cancel=$this->evidenceOf('companion_cancel');
        $this->assertSame([$value['cancel_request_id'],$value['cancelled_context_request_id'],$value['refused_request_id'],'HTTP 409: turn_cancelled'],
            [$cancel['cancel_request_id'],$cancel['anchor_request_id'],$cancel['refused_request_id'],$cancel['detail']]);
        $this->neverClaimed($session,$value['refused_request_id'],'cancelled-turn conversation');
        foreach ($this->providerRequests() as $request)
            $this->assertStringNotContainsString('Piper, never mind.',json_encode($request['messages'] ?? null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        // Server cancellation: the cancel completed and its context anchor is cancelled as the turn.
        $this->completed($session,$value['cancel_request_id'],'cancel');
        $this->assertSame($value['cancelled_context_request_id'],$this->canonical($session,$value['cancel_request_id'])['payload']['cancel_request_id']);
        $this->assertSame([['event_type'=>'context','state'=>'cancelled','error_code'=>'turn_cancelled']],
            $this->execution($session,$value['cancelled_context_request_id']));
        // Local refusals never reached the server: the owner-cancelled result and the stale-generation result.
        $local=$this->evidenceOf('companion_local_cancel');
        $this->assertSame('request cancelled before send',$local['detail']);
        if ($local['request_id']!=='') $this->neverClaimed($session,$local['request_id'],'locally cancelled result');
        $this->assertSame('turn owner belongs to another runtime generation',$this->evidenceOf('companion_generation')['refusal']);
        // Generation invalidation: the re-initialization is generation 2 and the session now runs it.
        $this->completed($session,$value['reinit_request_id'],'init');
        $this->assertSame('2',$this->query('SELECT generation FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',
            [$session,$value['reinit_request_id']])[0]['generation']);
        $this->assertSame([['active_generation'=>'2','state'=>'active']],
            $this->query('SELECT active_generation,state FROM synth_meta.runtime_sessions WHERE session_id=$1',[$session]));
        // Nothing after the result changed the intent, its one terminal result or the one unverified consequence.
        $this->assertSame([[$action['action_id'],'complete']],array_map('array_values',$this->query('SELECT action_id,state FROM synth_meta.action_intents')));
        $this->assertSame([['status'=>'unavailable']],$this->query('SELECT status FROM synth_meta.action_terminal_results'));
        $this->assertSame('1',$this->query("SELECT count(*) AS n FROM synth_meta.history_events WHERE event_payload ? 'synth_wait_consequence'")[0]['n']);
        $this->assertSame([strval($piper),strval($piper)],array_column($this->utterances($session),'speaker_identity_id'),'two native Piper turns');
        // Request accounting: 16 claims; only the cancelled context anchor is not complete.
        $counts=array_map('intval',array_column($this->query('SELECT event_type,count(*) AS n FROM synth_meta.request_executions
            GROUP BY event_type ORDER BY event_type'),'n','event_type'));
        $this->assertSame(['action_result'=>2,'activate'=>1,'cancel'=>1,'context'=>6,'init'=>2,'input_text'=>4],$counts);
        $this->assertSame([['request_id'=>$value['cancelled_context_request_id'],'state'=>'cancelled']],
            $this->query("SELECT request_id,state FROM synth_meta.request_executions WHERE state<>'complete'"));
        $this->companion['accounting']=['requests'=>$counts,'refused_before_claim'=>3,
            'active_agents'=>$this->query('SELECT generation,source,state FROM synth_meta.active_agents WHERE session_id=$1',[$session])];
    }
}
