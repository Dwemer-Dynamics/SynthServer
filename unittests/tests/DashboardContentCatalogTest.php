<?php
declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';
require_once dirname(__DIR__,2).'/lib/worldknowledge_catalog.php';

/** Exercise the management pages with their production adapter, only on testdb. */
final class DashboardContentCatalogTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    private function installLibrary(): array
    {
        $root=dirname(__DIR__,2);
        $pack=synthLoadFalloutContentPack($root);
        $catalog=synthWorldKnowledgeLoadFactoryCatalog($root);
        synthInstallFalloutContentBundle($this->db(),$root);
        return [$pack,$catalog];
    }

    /** Child PHP prevents page globals and sql classes from leaking between renders. */
    private function render(string $page,array $get=[],array $post=[],bool $withConf=true): string
    {
        self::assertContains($page,['ui/home.php','ui/index.php','ui/events-memories.php','ui/npc_upload.php','ui/worldknowledge_upload.php']);
        self::assertSame('testdb',self::$testDatabaseName);
        // phpunit.xml sets "true"; an outer runner may already export "1". Both mean the disposable suite.
        self::assertContains(getenv('PHPUNIT_TEST'),['true','1'],'Render tests require the disposable PHPUnit suite flag.');
        $root=dirname(__DIR__,2);
        $conf=$root.'/conf/conf.php';
        // Never open, overwrite or import an existing installation's configuration.
        self::assertFileDoesNotExist($conf,'Render tests require a source-only disposable checkout.');
        // Without the optional override a page must run from the tracked sample and the environment alone.
        if ($withConf) {
            $owned=fopen($conf,'x');
            self::assertIsResource($owned);
            fwrite($owned,"<?php require __DIR__.'/conf.sample.php';\n");
            fclose($owned);
        }
        $environment=getenv();
        $environment['SYNTH_DB_NAME']='testdb';
        $environment['SYNTH_DB_HOST']='127.0.0.1';
        $environment['SYNTH_DB_PORT']='5432';
        $environment['SYNTH_DB_USER']='dwemer';
        $environment['SYNTH_DB_PASSWORD']='dwemer';
        $get=['embed'=>'1','dashboard_regression'=>'1']+$get;
        $bootstrap='$_GET='.var_export($get,true).';$_POST='.var_export($post,true).';'
            .'$_SERVER["SCRIPT_NAME"]='.var_export('/Synthserver/'.$page,true).';'
            .'$_SERVER["REQUEST_METHOD"]='.var_export($post===[]?'GET':'POST',true).';'
            .'$_SERVER["QUERY_STRING"]=http_build_query($_GET);'
            .'$_SERVER["HTTP_HOST"]="127.0.0.1";'
            .'require '.var_export($root.'/'.$page,true).';';
        try {
            $process=proc_open([PHP_BINARY,'-d','allow_url_fopen=0','-d','session.save_path='.sys_get_temp_dir(),'-r',$bootstrap],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$page.' render failed: '.$errors);
            self::assertStringNotContainsString('Fatal error',$output);
            self::assertStringNotContainsString('Warning:',$output);
            // Server-side fetch failures; the events page's own console.error() text is not one.
            self::assertDoesNotMatchRegularExpression("/(?<!console\\.error\\(')Error fetching/",$output);
            return $output;
        } finally { if ($withConf) { unlink($conf); } }
    }

    private function xpath(string $html): DOMXPath
    {
        $document=new DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try { self::assertTrue($document->loadHTML($html)); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        return new DOMXPath($document);
    }

    private function editPayloads(string $html): array
    {
        $payloads=[];
        foreach ($this->xpath($html)->query('//button[contains(@onclick,"openEditModal(")]') as $button) {
            if (preg_match('/^openEditModal\((.*)\)$/s',$button->getAttribute('onclick'),$match)) {
                $payloads[]=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
            }
        }
        return $payloads;
    }

    public function testInstalledBiographiesAndDlcLoreRenderFromCanonicalSchema(): void
    {
        [$pack,$catalog]=$this->installLibrary();
        $db=$this->db();
        self::assertSame('0',$db->fetchOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('bio_templates','combined_bio_templates','worldknowledge','worldknowledge_catalogs')")['n']);
        $myrna=array_values(array_filter($pack['npcs'],static fn(array $row): bool=>strtolower($row['display_name'])==='myrna'));
        self::assertCount(1,$myrna);
        $html=$this->render('ui/npc_upload.php',['search'=>'Myrna']);
        $rows=$this->xpath($html)->query('//div[@id="npc-table-container"]//tr[td]');
        self::assertCount(1,$rows);
        self::assertStringContainsString($myrna[0]['npc_name'],$rows->item(0)->textContent);
        $html=$this->render('ui/worldknowledge_upload.php',['search'=>'far_harbor']);
        $matches=array_values(array_filter($this->editPayloads($html),static fn(array $row): bool=>$row['topic']==='far_harbor'));
        self::assertCount(1,$matches);
        self::assertSame('factory',$matches[0]['source_kind']);
        self::assertStringContainsString('Ships with SYNTH',$html);
        self::assertStringContainsString($catalog['manifest']['catalog_version'],$html);
        self::assertMatchesRegularExpression('/'.count($catalog['rows']).'\s+articles/',$html);
    }

    public function testEffectiveCustomOverrideSearchAndEditPayloadAreLossless(): void
    {
        $this->installLibrary();
        $db=$this->db();
        $description="A & B's \"quoted\" <detail> and literal &lt;entity&gt;.";
        $basic="Plain & simple <basic> \"words\"; literal &amp; stays literal.";
        $aliases='Regression Harbor Alias';$tags='regression harbor tags';
        self::assertNotFalse($db->execQuery('INSERT INTO synth.worldknowledge(topic,canonical_topic,aliases,topic_desc,knowledge_class,topic_desc_basic,knowledge_class_basic,tags,category,source_kind,is_active) VALUES($1::text,$1::text,$2,$3,$4,$5,$6,$7,$8,$9,TRUE)',
            ['far_harbor',$aliases,$description,'far_harbor_lore',$basic,'far_harbor',$tags,'location','custom']));
        foreach (['far_harbor',$aliases,$tags] as $search) {
            $html=$this->render('ui/worldknowledge_upload.php',['search'=>$search]);
            $allPayloads=$this->editPayloads($html);
            $payloads=array_values(array_filter($allPayloads,static fn(array $row): bool=>$row['topic']==='far_harbor'));
            self::assertCount(1,$payloads,'An effective topic must appear once, including alias/tag searches.');
            if ($search!== 'far_harbor') self::assertCount(1,$allPayloads,'The unique fixture alias or tags must find only their owning article.');
            self::assertSame('far_harbor',$payloads[0]['topic']);
            self::assertSame('custom',$payloads[0]['source_kind']);
            self::assertSame($aliases,$payloads[0]['aliases']);
            self::assertSame($description,$payloads[0]['topic_desc']);
            self::assertSame($basic,$payloads[0]['topic_desc_basic']);
            self::assertSame($tags,$payloads[0]['tags']);
            self::assertStringContainsString('Your article',$html);
            self::assertStringNotContainsString('<detail>',$html);
        }
    }

    public function testSavingRenderedFactoryArticleCreatesLosslessCustomOverride(): void
    {
        $this->installLibrary();
        $db=$this->db();
        $factory=$db->fetchOne("SELECT topic_desc,content_hash FROM synth.worldknowledge WHERE topic='far_harbor' AND source_kind='factory'");
        $description="Harbor & ships, \"quotes\", <detail>, and literal &lt;text&gt;.";
        $basic="A fisherman's <town> & literal &amp;entity;.";
        $this->render('ui/worldknowledge_upload.php',['search'=>'far_harbor'],[
            'action'=>'update_single','topic_original'=>'far_harbor','topic_new'=>'forged_rename',
            'aliases_new'=>'Regression Far Harbor','topic_desc_new'=>$description,
            'knowledge_class_new'=>'far_harbor_lore','topic_desc_basic_new'=>$basic,
            'knowledge_class_basic_new'=>'far_harbor','tags_new'=>'regression harbor docks','category_new'=>'location']);
        $custom=$db->fetchOne("SELECT topic,topic_desc,topic_desc_basic FROM synth.worldknowledge WHERE canonical_topic='far_harbor' AND source_kind='custom'");
        self::assertSame('far_harbor',$custom['topic']);
        self::assertSame($description,$custom['topic_desc']);
        self::assertSame($basic,$custom['topic_desc_basic']);
        self::assertSame($factory,$db->fetchOne("SELECT topic_desc,content_hash FROM synth.worldknowledge WHERE topic='far_harbor' AND source_kind='factory'"));
        $payloads=array_values(array_filter($this->editPayloads($this->render('ui/worldknowledge_upload.php',['search'=>'far_harbor'])),
            static fn(array $row): bool=>$row['topic']==='far_harbor'));
        self::assertCount(1,$payloads);
        self::assertSame($description,$payloads[0]['topic_desc']);
        self::assertSame($basic,$payloads[0]['topic_desc_basic']);
    }

    public function testHomeSeparatesInstalledLibrariesFromEncounteredProfiles(): void
    {
        [$pack,$catalog]=$this->installLibrary();
        $db=$this->db();
        self::assertNotFalse($db->execQuery('INSERT INTO synth.core_npc_master(npc_name) VALUES($1),($2)',
            ['Dashboard fixture one','Dashboard fixture two']));
        $profileCount=(int)$db->fetchOne('SELECT COUNT(*) AS n FROM synth.core_npc_master')['n'];
        self::assertLessThan(count($pack['npcs']),$profileCount);
        $xpath=$this->xpath($this->render('ui/home.php'));
        foreach (['Character templates'=>count($pack['npcs']),'World lore articles'=>count($catalog['rows']),'Encountered NPCs'=>$profileCount] as $label=>$expected) {
            $values=$xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," stat-label ") and normalize-space(.)="'.$label.'"]/preceding-sibling::*[contains(concat(" ",normalize-space(@class)," ")," stat-value ")]');
            self::assertCount(1,$values,$label.' must have a distinct visible counter.');
            self::assertSame(number_format($expected),trim($values->item(0)->textContent));
        }
    }

    public function testManagementGuardAcceptsOnlySameOriginBrowserEvidence(): void
    {
        require_once dirname(__DIR__,2).'/lib/management_request_guard.php';
        $host=['HTTP_HOST'=>'127.0.0.1:8087','REQUEST_METHOD'=>'POST'];
        foreach ([
            [['HTTP_ORIGIN'=>'http://127.0.0.1:8087','HTTP_SEC_FETCH_SITE'=>'same-origin'],true],
            [['HTTP_REFERER'=>'http://127.0.0.1:8087/ui/index.php','HTTP_USER_AGENT'=>'Mozilla/5.0'],true],
            [['HTTP_USER_AGENT'=>'SYNTH-native'],true],
            [['HTTP_ORIGIN'=>'http://evil.test'],false],
            [['HTTP_ORIGIN'=>'null'],false],
            [['HTTP_ORIGIN'=>'http://127.0.0.1:9000','HTTP_SEC_FETCH_SITE'=>'same-site'],false],
            [['HTTP_SEC_FETCH_SITE'=>'cross-site','REQUEST_METHOD'=>'GET'],false],
            [['HTTP_REFERER'=>'http://evil.test/','HTTP_USER_AGENT'=>'Mozilla/5.0'],false],
            [['HTTP_USER_AGENT'=>'Mozilla/5.0'],false],
            [['HTTP_ORIGIN'=>'http://127.0.0.1:8087','HTTP_SEC_FETCH_SITE'=>'cross-site'],false],
            [['HTTP_ORIGIN'=>'https://127.0.0.1:8087'],false],
            [['HTTP_REFERER'=>'http://127.0.0.1:9000/ui/index.php','HTTP_USER_AGENT'=>'Mozilla/5.0'],false],
            [['HTTP_SEC_FETCH_SITE'=>'none','HTTP_USER_AGENT'=>'Mozilla/5.0','REQUEST_METHOD'=>'GET'],true],
            [['HTTP_USER_AGENT'=>'Mozilla/5.0','REQUEST_METHOD'=>'GET'],true],
        ] as [$headers,$allowed]) {
            self::assertSame($allowed,synthManagementRequestRefusal($headers+$host)===null,json_encode($headers));
        }
        // A GET with an outbound effect (the Quickstart MiniMe probe) needs browser evidence like a POST.
        $get=['HTTP_HOST'=>'127.0.0.1:8087','REQUEST_METHOD'=>'GET'];
        self::assertNotNull(synthManagementRequestRefusal($get+['HTTP_USER_AGENT'=>'Mozilla/5.0'],'browser',true));
        self::assertNull(synthManagementRequestRefusal($get+['HTTP_USER_AGENT'=>'Mozilla/5.0','HTTP_SEC_FETCH_SITE'=>'same-origin'],'browser',true));
        self::assertNull(synthManagementRequestRefusal($get+['HTTP_USER_AGENT'=>'curl/8'],'browser',true));
        self::assertNotNull(synthManagementRequestRefusal(['REQUEST_METHOD'=>'GET'],'cli-only'));
        self::assertSame('cli-only',synthManagementScriptScope('/srv/s/tools/bootstrap-database.php','/srv/s'));
        self::assertNull(synthManagementScriptScope('/srv/s/main.php','/srv/s'));
    }

    public function testDashboardIndexRefusesDestructiveLinksAndRendersConfirmedPostControls(): void
    {
        $db=$this->db();
        $db->insert('eventlog',['ts'=>'100','gamets'=>'200','type'=>'chat','data'=>'Dashboard fixture','sess'=>'pending',
            'localts'=>10,'people'=>'||','location'=>'','party'=>'[]']);
        $count=static fn(): int => (int)$db->fetchOne('SELECT COUNT(*) AS n FROM eventlog')['n'];
        $before=$count();
        foreach (['reset','cleanlog','delete_last','delete_memory','reinstall'] as $legacy) {
            self::assertStringContainsString('no longer available by link',$this->render('ui/index.php',[$legacy=>'1','table'=>'eventlog']));
        }
        self::assertSame($before,$count());
        $xpath=$this->xpath($this->render('ui/index.php',['table'=>'eventlog']));
        self::assertSame(0,$xpath->query('//*[contains(@onclick,"delete_last=") or contains(@onclick,"reset=")]')->length);
        $form=$xpath->query('//form[@method="post"][.//button[@value="reset_events"]]');
        self::assertCount(1,$form);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$xpath->query('.//input[@name="csrf_token"]/@value',$form->item(0))->item(0)->nodeValue);
        self::assertCount(3,$xpath->query('.//button[@value="delete_last"]',$form->item(0)));
        self::assertSame($before,$count());
    }

    public function testEventsAndMemoriesRunWithoutLocalOverrideAndRefuseUnconfirmedActions(): void
    {
        $db=$this->db();
        $db->insert('eventlog',['ts'=>'100','gamets'=>'200','type'=>'chat','data'=>'Events fixture','sess'=>'pending',
            'localts'=>10,'people'=>'||','location'=>'','party'=>'[]']);
        $count=static fn(): int => (int)$db->fetchOne('SELECT COUNT(*) AS n FROM eventlog')['n'];
        $before=$count();
        foreach (['delete_last'=>'20','reset'=>'true','delete_memory'=>'1','hide_event_type'=>'chat','clear_hidden_event_types'=>'1'] as $key=>$value) {
            self::assertStringContainsString('no longer available by link',
                $this->render('ui/events-memories.php',[$key=>$value,'tab'=>'eventlog'],[],false));
        }
        foreach (['reset_events','delete_last','hide_event_type'] as $action) {
            self::assertStringContainsString('page expired',$this->render('ui/events-memories.php',['tab'=>'eventlog'],
                ['events_action'=>$action,'value'=>'20','csrf_token'=>str_repeat('0',64)],false));
        }
        self::assertSame($before,$count());
        // Without conf/conf.php the page renders from the sample and environment instead of redirecting to setup.
        $html=$this->render('ui/events-memories.php',['tab'=>'memory'],[],false);
        self::assertStringContainsString('window.synthManagementPost=',$html);
        self::assertStringContainsString("postEventLogAction('reset_events'",$html);
        self::assertStringNotContainsString('delete_memory=',$html);
        self::assertStringNotContainsString("params.set('hide_event_type'",$html);
        self::assertSame($before,$count());
    }
}
