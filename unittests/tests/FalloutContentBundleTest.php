<?php
declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';
require_once dirname(__DIR__,2).'/lib/worldknowledge_catalog.php';

/** Forward to disposable PostgreSQL, intercepting only the selected failure point. */
final class FalloutContentBundleTestAdapter
{
    public array $queries=[];
    private int $matches=0;
    public function __construct(private sql $db,private ?string $failurePrefix=null,private int $failureMatch=1) {}
    public function isTransactionIdle(): bool { return $this->db->isTransactionIdle(); }
    public function execQuery(string $query,array $parameters=[])
    {
        $this->queries[]=$query;
        if ($this->failurePrefix!==null && str_starts_with($query,$this->failurePrefix)
            && ++$this->matches===$this->failureMatch) return false;
        return $this->db->execQuery($query,$parameters);
    }
    public function fetchOne(string $query): array { return $this->db->fetchOne($query); }
    public function fetchAll(string $query): array { return $this->db->fetchAll($query); }
    public function escapeLiteral(string $value): string { return $this->db->escapeLiteral($value); }
    public function escape(string $value): string { return $this->db->escape($value); }
}

final class FalloutContentBundleTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    private function fixture(): array
    {
        $root=sys_get_temp_dir().'/synth-content-bundle-'.bin2hex(random_bytes(6));
        mkdir($root.'/data',0700,true);
        $source=dirname(__DIR__,2);
        $pack=synthLoadFalloutContentPack($source);
        $prototype=$pack['npcs'][0];
        $pack['npcs']=[];$pack['items']=[];
        foreach (['existing','new'] as $index=>$kind) {
            $row=$prototype;
            $row['plugin']=$index===0 ? 'DLCRobot.esm' : 'DLCCoast.esm';
            $row['baseid']='00F0020'.($index+1);
            $row['npc_name']='fo4_bundle_'.$kind;
            $row['display_name']='Bundle fixture '.$kind;
            $row['source_url']='https://example.invalid/bundle-fixture';
            foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) $row['fields'][$field]='Fixture '.$kind.' '.$field.'.';
            $row['fields']['worldknowledge_tags']='commonwealth';
            $pack['npcs'][]=$row;
            $pack['items'][]=['plugin'=>'Fallout4.esm','baseid'=>'00F0030'.($index+1),
                'name'=>'Bundle fixture '.$kind.' item','description'=>'Fixture item description.',
                'source_url'=>'https://example.invalid/bundle-fixture'];
        }
        file_put_contents($root.'/data/fallout4_content_pack.json',json_encode($pack,JSON_THROW_ON_ERROR));
        $loaded=synthWorldKnowledgeLoadFactoryCatalog($source);
        $manifest=$loaded['manifest'];
        $manifest['catalog_id']='bundle-fixture';$manifest['catalog_version']='fixture-2';$manifest['row_count']=2;
        $manifest['csv_file']='bundle-fixture.csv';
        $rows=[];
        foreach (['first','second'] as $kind) {
            $row=$loaded['rows'][0];$row['topic']='bundle_fixture_'.$kind;$row['aliases']='';
            $rows[]=$row;
        }
        $this->writeCsv($root.'/data/'.$manifest['csv_file'],SYNTH_WORLDKNOWLEDGE_CATALOG_FIELDS,$rows);
        $manifest['checksum_sha256']=hash_file('sha256',$root.'/data/'.$manifest['csv_file']);
        $vocabulary=basename($manifest['knowledge_vocabulary']['file']);
        copy($source.'/data/'.$vocabulary,$root.'/data/'.$vocabulary);
        file_put_contents($root.'/data/fallout_worldknowledge_manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        $this->writeCsv($root.'/data/fallout_worldknowledge_npc_tags.csv',
            ['npc_name','worldknowledge_tags','prior_seed_sha256'],[
                ['npc_name'=>'fo4_bundle_existing','worldknowledge_tags'=>'commonwealth','prior_seed_sha256'=>''],
                ['npc_name'=>'fo4_bundle_new','worldknowledge_tags'=>'commonwealth','prior_seed_sha256'=>'']]);
        $legacy=$rows[0];$legacy['topic']='bundle_fixture_legacy_seed';
        $this->writeCsv($root.'/data/fallout_worldknowledge_basic.csv',SYNTH_WORLDKNOWLEDGE_CATALOG_FIELDS,[$legacy]);
        return [$root,$pack,$rows,$legacy];
    }

    private function writeCsv(string $path,array $header,array $rows): void
    {
        $handle=fopen($path,'wb');
        if ($handle===false) throw new RuntimeException('Unable to create disposable content fixture');
        try {
            fputcsv($handle,$header,',','"','\\');
            foreach ($rows as $row) fputcsv($handle,array_map(static fn($field)=>$row[$field],$header),',','"','\\');
        } finally { fclose($handle); }
    }

    private function removeFixture(string $root): void
    {
        foreach (glob($root.'/data/*') ?: [] as $path) unlink($path);
        rmdir($root.'/data');rmdir($root);
    }

    private function seedPopulatedState(array $pack,array $legacy): array
    {
        $db=$this->db();
        $existing=$pack['npcs'][0];
        self::assertNotFalse($db->execQuery('INSERT INTO synth.bio_templates(npc_name,npc_static_bio,personality,worldknowledge_tags) VALUES($1,$2,$3,$4)',
            [$existing['npc_name'],'Operator-authored standard background.','Operator-authored standard personality.','commonwealth,bundle_authored_tag']));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.bio_templates_custom(npc_name,npc_static_bio) VALUES($1,$2)',
            [$existing['npc_name'],'Operator-authored custom background.']));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.bio_template_actor_map(template_name,base_plugin,base_local_formid,exact_name,is_nonverbal_creature) VALUES($1,$2,$3,$4,false)',
            [$existing['npc_name'],$existing['plugin'],$existing['baseid'],$existing['display_name']]));
        self::assertNotFalse($db->execQuery("INSERT INTO synth.descriptions(plugin,baseid,name,description) VALUES('Fallout4.esm','00F00301','Authored item','Authored standard description.')"));
        self::assertNotFalse($db->execQuery("INSERT INTO synth.descriptions_custom(plugin,baseid,name,description) VALUES('Fallout4.esm','00F00301','Authored custom item','Authored custom description.')"));
        $legacyFields=array_intersect_key($legacy,array_flip(SYNTH_WORLDKNOWLEDGE_CATALOG_FIELDS));
        $legacyFields['canonical_topic']=$legacy['topic'];$legacyFields['source_kind']='custom';
        self::assertNotFalse($db->execQuery('INSERT INTO synth.worldknowledge('.implode(',',array_keys($legacyFields)).') VALUES('.
            implode(',',array_map(static fn($i)=>'$'.$i,range(1,count($legacyFields)))).')',array_values($legacyFields)));
        self::assertNotFalse($db->execQuery("INSERT INTO synth.worldknowledge(topic,canonical_topic,topic_desc,source_kind,is_active) VALUES('bundle_authored','bundle_authored','Operator-authored World Knowledge.','custom',true)"));
        self::assertNotFalse($db->execQuery('ALTER TABLE synth.database_versioning DROP CONSTRAINT IF EXISTS database_versioning_pkey'));
        self::assertNotFalse($db->execQuery("DELETE FROM synth.database_versioning WHERE tablename='fallout4_content_pack'"));
        self::assertNotFalse($db->execQuery("INSERT INTO synth.database_versioning(tablename,version) VALUES('fallout4_content_pack',20261007001)"));
        $ids=[];
        foreach ([0,1,1] as $index=>$rowIndex) {
            $row=$pack['npcs'][$rowIndex];$reference='0x03F0040'.($index+1);
            $result=$db->execQuery('INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name) VALUES($1,$2,$3,$4,$5) RETURNING id',
                ['fo4','bundle-fixture',$row['plugin'],$reference,$row['display_name']]);
            self::assertNotFalse($result);$identity=pg_fetch_assoc($result);$id=intval($identity['id']);$ids[]=$id;
            synth_upsert_npc_profile($db,$row['display_name'],$reference,
                ['baseid'=>$row['plugin'].'|'.$row['baseid'],'reference_origin_plugin'=>$row['plugin']],$id);
            self::assertNotFalse($db->execQuery('UPDATE synth.core_npc_master SET core=NULL,npc_static_bio=NULL,worldknowledge_tags=NULL,
                occupation=NULL,appearance=NULL,skills=NULL,speechstyle=NULL,goals=NULL,personality=$1,voiceid=$2,relationships=$3,lock_profile=$4
                WHERE actor_identity_id=$5',['Authored profile personality.','preserved_fixture_voice','Authored profile relationships.',$index===2 ? 1 : 0,$id]));
        }
        return $ids;
    }

    private function snapshot(): array
    {
        $result=[];
        foreach (['bio_templates','bio_templates_custom','bio_template_actor_map','descriptions','descriptions_custom',
            'core_npc_master','worldknowledge','worldknowledge_catalogs','database_versioning'] as $table)
            $result[$table]=$this->db()->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.'.$table.' t ORDER BY body');
        return $result;
    }

    private function transactionCommands(FalloutContentBundleTestAdapter $db): array
    {
        return array_values(array_filter($db->queries,static fn($query)=>in_array($query,['BEGIN','COMMIT','ROLLBACK'],true)));
    }

    public function testMidWorldFailureRestoresEntirePopulatedBundle(): void
    {
        [$root,$pack,,$legacy]=$this->fixture();
        try {
            $this->seedPopulatedState($pack,$legacy);$before=$this->snapshot();
            $db=new FalloutContentBundleTestAdapter($this->db(),'INSERT INTO synth.worldknowledge (topic,canonical_topic',2);
            try { synthInstallFalloutContentBundle($db,$root,true);self::fail('Second World Knowledge insert must fail'); }
            catch (RuntimeException $error) { self::assertStringContainsString('article bundle_fixture_second',$error->getMessage()); }
            self::assertSame(['BEGIN','ROLLBACK'],$this->transactionCommands($db));
            self::assertTrue($this->db()->isTransactionIdle());
            self::assertSame($before,$this->snapshot());
            self::assertNotEmpty(array_filter($db->queries,static fn($query)=>str_starts_with($query,'UPDATE synth.core_npc_master SET')));
            self::assertContains("DELETE FROM synth.worldknowledge WHERE source_kind='factory'",$db->queries);
        } finally { $this->removeFixture($root); }
    }

    public function testLateVersionFailureRestoresImportedCharactersWorldAndProfiles(): void
    {
        [$root,$pack,,$legacy]=$this->fixture();
        try {
            $this->seedPopulatedState($pack,$legacy);$before=$this->snapshot();
            $db=new FalloutContentBundleTestAdapter($this->db(),'UPDATE synth.database_versioning SET version=GREATEST');
            try { synthInstallFalloutContentBundle($db,$root,true);self::fail('Version update must fail'); }
            catch (RuntimeException $error) { self::assertStringContainsString('bundle version',$error->getMessage()); }
            self::assertSame(['BEGIN','ROLLBACK'],$this->transactionCommands($db));
            self::assertSame($before,$this->snapshot());
            self::assertCount(2,array_filter($db->queries,static fn($query)=>str_starts_with($query,'INSERT INTO synth.worldknowledge (topic,canonical_topic')));
        } finally { $this->removeFixture($root); }
    }

    public function testSuccessfulRepeatedLegacyUpgradePreservesAuthoredAndLockedContent(): void
    {
        [$root,$pack,,$legacy]=$this->fixture();
        try {
            $ids=$this->seedPopulatedState($pack,$legacy);$before=$this->snapshot();
            for ($attempt=0;$attempt<2;++$attempt) {
                $db=new FalloutContentBundleTestAdapter($this->db());
                $report=synthInstallFalloutContentBundle($db,$root,true);
                self::assertSame(['BEGIN','COMMIT'],$this->transactionCommands($db));
                self::assertSame($attempt===0 ? 2 : 0,$report['profiles_filled']);
                self::assertSame(2,$report['worldknowledge']['row_count']);
                self::assertSame(SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION,$report['content_version']);
                self::assertTrue($this->db()->isTransactionIdle());
            }
            $version=$this->db()->fetchOne("SELECT count(*) AS n,min(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_content_pack'");
            self::assertSame(['n'=>'1','version'=>strval(SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION)],$version);
            $after=$this->snapshot();
            self::assertSame($before['bio_templates_custom'],$after['bio_templates_custom']);
            self::assertSame($before['descriptions_custom'],$after['descriptions_custom']);
            $template=$this->db()->fetchOne("SELECT personality,worldknowledge_tags FROM synth.bio_templates WHERE npc_name='fo4_bundle_existing'");
            self::assertSame('Operator-authored standard personality.',$template['personality']);
            self::assertSame('commonwealth,bundle_authored_tag',$template['worldknowledge_tags']);
            self::assertSame('Authored standard description.',$this->db()->fetchOne("SELECT description FROM synth.descriptions WHERE baseid='00F00301'")['description']);
            self::assertSame('Operator-authored World Knowledge.',$this->db()->fetchOne("SELECT topic_desc FROM synth.worldknowledge WHERE canonical_topic='bundle_authored'")['topic_desc']);
            self::assertSame('0',$this->db()->fetchOne("SELECT count(*) AS n FROM synth.worldknowledge WHERE canonical_topic='bundle_fixture_legacy_seed'")['n']);
            foreach ($ids as $index=>$id) {
                $profile=$this->db()->fetchOne('SELECT npc_static_bio,personality,voiceid,relationships FROM synth.core_npc_master WHERE actor_identity_id='.$id);
                self::assertSame('Authored profile personality.',$profile['personality']);
                self::assertSame('preserved_fixture_voice',$profile['voiceid']);
                self::assertSame('Authored profile relationships.',$profile['relationships']);
                if ($index===0) self::assertSame('Operator-authored custom background.',$profile['npc_static_bio']);
                elseif ($index===1) self::assertSame($pack['npcs'][1]['fields']['npc_static_bio'],$profile['npc_static_bio']);
                else self::assertNull($profile['npc_static_bio']);
            }
            self::assertSame('2',$this->db()->fetchOne("SELECT count(*) AS n FROM synth.bio_template_actor_map WHERE template_name LIKE 'fo4_bundle_%'")['n']);
        } finally { $this->removeFixture($root); }
    }

    public function testInvalidCatalogPackOrTagsFailsBeforeDatabaseWrites(): void
    {
        [$root]=$this->fixture();
        try {
            $before=$this->snapshot();
            foreach (['pack','catalog','tags'] as $kind) {
                $path=$root.'/data/'.match($kind) {
                    'pack'=>'fallout4_content_pack.json','catalog'=>'bundle-fixture.csv','tags'=>'fallout_worldknowledge_npc_tags.csv'};
                $contents=file_get_contents($path);
                if ($kind==='pack') {
                    $invalid=json_decode($contents,true,512,JSON_THROW_ON_ERROR);
                    $invalid['npcs'][0]['fields']['voiceid']='forbidden';
                    file_put_contents($path,json_encode($invalid,JSON_THROW_ON_ERROR));
                } elseif ($kind==='catalog') file_put_contents($path,$contents."checksum corruption\n");
                else file_put_contents($path,"npc_name,worldknowledge_tags,prior_seed_sha256\nfo4_bundle_new,commonwealth,invalid_hash\n");
                $db=new FalloutContentBundleTestAdapter($this->db());
                try { synthInstallFalloutContentBundle($db,$root,true);self::fail('Malformed '.$kind.' must fail'); }
                catch (RuntimeException) { self::assertSame([],$db->queries); }
                finally { file_put_contents($path,$contents); }
                self::assertSame($before,$this->snapshot());
            }
        } finally { $this->removeFixture($root); }
    }

    public function testBorrowedModesRequireCallerTransactionAndNeverCommitIt(): void
    {
        [$root,$pack,,$legacy]=$this->fixture();
        try {
            $this->seedPopulatedState($pack,$legacy);$before=$this->snapshot();
            $db=new FalloutContentBundleTestAdapter($this->db());
            foreach ([static fn()=>synthInstallFalloutContentPack($db,$root,false,false),
                static fn()=>synthWorldKnowledgeInstallFactoryCatalog($db,$root,false)] as $import) {
                try { $import();self::fail('Borrowed mode requires an active transaction'); }
                catch (LogicException) { self::assertSame([],$db->queries); }
            }
            self::assertNotFalse($db->execQuery('BEGIN'));
            foreach ([static fn()=>synthInstallFalloutContentBundle($db,$root,true),
                static fn()=>synthInstallFalloutContentPack($db,$root,true),
                static fn()=>synthWorldKnowledgeInstallFactoryCatalog($db,$root)] as $import) {
                try { $import();self::fail('Owned mode cannot commit an existing transaction'); }
                catch (LogicException) { self::assertSame(['BEGIN'],$this->transactionCommands($db)); }
            }
            synthInstallFalloutContentPack($db,$root,true,false);
            synthWorldKnowledgeInstallFactoryCatalog($db,$root,false);
            self::assertSame(['BEGIN'],$this->transactionCommands($db));
            self::assertFalse($this->db()->isTransactionIdle());
            self::assertNotSame($before,$this->snapshot());
            self::assertNotFalse($db->execQuery('ROLLBACK'));
            self::assertSame($before,$this->snapshot());
        } finally {
            if (!$this->db()->isTransactionIdle()) $this->db()->execQuery('ROLLBACK');
            $this->removeFixture($root);
        }
    }

    public function testBorrowedFailureLeavesRollbackToTheCaller(): void
    {
        [$root,$pack,,$legacy]=$this->fixture();
        try {
            $this->seedPopulatedState($pack,$legacy);$before=$this->snapshot();
            $db=new FalloutContentBundleTestAdapter($this->db(),'INSERT INTO synth.worldknowledge (topic,canonical_topic',2);
            self::assertNotFalse($db->execQuery('BEGIN'));
            synthInstallFalloutContentPack($db,$root,true,false);
            try { synthWorldKnowledgeInstallFactoryCatalog($db,$root,false);self::fail('Borrowed World Knowledge insert must fail'); }
            catch (RuntimeException) { self::assertSame(['BEGIN'],$this->transactionCommands($db)); }
            self::assertFalse($this->db()->isTransactionIdle());
            self::assertNotSame($before,$this->snapshot());
            self::assertNotFalse($db->execQuery('ROLLBACK'));
            self::assertSame($before,$this->snapshot());
        } finally {
            if (!$this->db()->isTransactionIdle()) $this->db()->execQuery('ROLLBACK');
            $this->removeFixture($root);
        }
    }
}
