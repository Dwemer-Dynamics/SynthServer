<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/synth_diary_browser.php';

final class DiaryBrowserTest extends DatabaseTestCase
{
    private $connection;
    private int $serial=0;

    public function setUp(): void
    {
        parent::setUp();
        $this->connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse($this->connection);
    }

    public function tearDown(): void
    {
        if ($this->connection) pg_close($this->connection);
        parent::tearDown();
    }

    private function query(string $sql,array $parameters=[]): array
    {
        $r=pg_query_params($this->connection,$sql,$parameters);
        self::assertNotFalse($r);
        return pg_fetch_all($r) ?: [];
    }

    private function database(): object
    {
        return new class($this->connection) {
            public function __construct(private $connection) {}
            public function execQuery(string $sql,array $parameters=[]) { return pg_query_params($this->connection,$sql,$parameters); }
        };
    }

    private function hash(array $value): string
    {
        return $this->query("SELECT encode(sha256(convert_to($1::jsonb::text,'UTF8')),'hex') AS hash",[json_encode($value)]) [0]['hash'];
    }

    private function seed(string $save,string $role,string $author,string $content,bool $valid=true): string
    {
        $n=++$this->serial;
        $reference=$role==='npc' ? '0x0001A4D8' : '0x00000014';
        $existing=$this->query('SELECT id FROM synth.actor_identities WHERE playthrough_id=$1 AND full_form_id=$2',[$save,$reference]);
        $actor=(int)($existing[0]['id'] ?? $this->query("INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name)
            VALUES('fo4',$1,'Fallout4.esm',$2,$3) RETURNING id",[$save,$reference,$author])[0]['id']);
        $session='diary-browser:'.$n;$request='diary:'.$n;
        $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation)
            VALUES($1,$2,'fo4','flat','fixture','fixture',1)",[$session,$save]);
        $this->query("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state)
            VALUES($1,$2,'turn',1,$3,'diary_request','complete')",[$session,$request,str_repeat('a',64)]);
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,context_payload)
            VALUES($1,1,1,$2,$3,'{}')",[$session,$save,$actor]);
        $input=['schema'=>'synth.diary.input.v1','actor_identity_id'=>$actor,'diary_role'=>$role,
            'binding'=>['name'=>$author],'context'=>['player'=>['display_name'=>'Fixture Player'],'world'=>['location'=>'Sanctuary']]];
        $inputHash=$this->hash($input);
        $inputId=$this->query("INSERT INTO synth_meta.diary_inputs(session_id,request_id,generation,context_sequence,actor_identity_id,diary_role,input_payload,input_sha256)
            VALUES($1,$2,1,1,$3,$4,$5,$6) RETURNING id",[$session,$request,$actor,$role,json_encode($input),$inputHash])[0]['id'];
        $value=['topic'=>'A day in Sanctuary '.$n,'content'=>$content];$resultHash=$this->hash($value);
        $history=['type'=>'diary_result','delivery_state'=>'derived','gamets'=>123,
            'diary_result'=>$value,'diary_result_sha256'=>$valid?$resultHash:str_repeat('0',64),'diary_input_sha256'=>$inputHash];
        $id=$this->query("INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload,recorded_at)
            VALUES($1,1,1,$2,$3,$4,$5,'2026-11-03 12:00:00+00'::timestamptz+($6::integer*interval '1 minute')) RETURNING id",
            [$session,$request,$save,900000+$n,json_encode($history),$n])[0]['id'];
        $this->query("INSERT INTO synth_meta.diary_results(history_event_id,input_id,result_payload,result_sha256) VALUES($1,$2,$3,$4)",
            [$id,$inputId,json_encode($value),$resultHash]);
        return $role.':'.$actor;
    }

    public function testArchiveReadsPublishedDiariesWithExactAuthorsRolesAndPlaythroughsWithoutWriting(): void
    {
        $first=$this->seed('diary-save-a','npc','Sturges','I repaired the water pump.');
        $second=$this->seed('diary-save-b','npc','Sturges','I worked on a generator.');
        $this->seed('diary-save-a','player','Fixture Player','I explored Sanctuary.');
        $this->seed('diary-save-a','narrator','The Narrator','A quiet day.');
        $this->seed('diary-save-a','npc','Sturges','This receipt is mismatched.',false);
        $db=$this->database();
        $before=$this->query('SELECT history_event_id,result_sha256 FROM synth_meta.diary_results ORDER BY history_event_id');
        $this->query('BEGIN READ ONLY');
        try {
            $all=synth_diary_browser_load($db,[]);
            self::assertSame(4,$all['total']);
            self::assertCount(4,$all['entries']);
            self::assertCount(2,$all['saves']);
            self::assertCount(4,$all['authors']);
            self::assertSame('narrator',$all['entries'][0]['role']);
            $one=synth_diary_browser_load($db,['author'=>$first]);
            self::assertSame(1,$one['total']);
            self::assertSame('I repaired the water pump.',$one['entries'][0]['content']);
            self::assertNotSame($first,$second);
            $save=synth_diary_browser_load($db,['save'=>substr(hash('sha256','diary-save-a'),0,12),'role'=>'npc']);
            self::assertSame(1,$save['total']);
            self::assertSame('I repaired the water pump.',$save['entries'][0]['content']);
            self::assertSame(1,synth_diary_browser_load($db,['search'=>'generator'])['total']);
            self::assertSame(0,synth_diary_browser_load($db,['search'=>"%') OR 1=1 --"])['total']);
            $summary=synth_diary_browser_summary($db);
            self::assertSame(4,$summary['total']);self::assertSame('The Narrator',$summary['latest']['author']);
        } finally {$this->query('ROLLBACK');}
        self::assertSame($before,$this->query('SELECT history_event_id,result_sha256 FROM synth_meta.diary_results ORDER BY history_event_id'));
    }

    public function testPaginationEscapesWrittenTextAndRetainsFiltersInEmbeddedView(): void
    {
        $author='';
        for($i=0;$i<23;++$i) $author=$this->seed('diary-save-a','npc','Sturges <b>','Entry '.$i.' <script>alert("text")</script>');
        $db=$this->database();
        $view=synth_diary_browser_load($db,['author'=>$author,'page'=>'2']);
        self::assertCount(3,$view['entries']);self::assertSame(2,$view['pages']);
        $webRoot='/Synthserver';$isEmbed=true;$error='';
        ob_start();include dirname(__DIR__,2).'/ui/tmpl/diary_entries.php';$html=ob_get_clean();
        self::assertStringNotContainsString('<script>',$html);
        self::assertStringContainsString('&lt;script&gt;',$html);
        self::assertStringContainsString('Sturges &lt;b&gt;',$html);
        self::assertStringContainsString('embed=1',$html);
        self::assertStringContainsString('author=npc%3A',$html);
        self::assertStringContainsString('Page 2 of 2',$html);
        self::assertSame(2,synth_diary_browser_load($db,['page'=>'99999'])['filters']['page']);
        foreach ([['page'=>'0'],['page'=>[]],['role'=>'admin'],['author'=>"npc:1'"],['save'=>'wrong'],['search'=>str_repeat('x',513)]] as $invalid) {
            try {synth_diary_browser_filters($invalid);self::fail('Invalid diary filter accepted');}
            catch(InvalidArgumentException) {self::assertTrue(true);}
        }
        $view=synth_diary_browser_load($db,['search'=>'no matching entry']);
        ob_start();include dirname(__DIR__,2).'/ui/tmpl/diary_entries.php';$html=ob_get_clean();
        self::assertStringContainsString('No matching entries',$html);
    }
}
