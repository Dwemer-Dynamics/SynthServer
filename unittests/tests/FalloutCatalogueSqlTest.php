<?php
declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_catalogue_sql.php';

/** Real disposable PostgreSQL, with bounded failure injection for version/commit handling. */
final class FalloutCatalogueSqlTestAdapter extends SynthFalloutCatalogueSqlConnection
{
    public array $queries=[];
    private $beforeQuery;
    public function __construct(sql $db,private ?string $failPrefix=null,private bool $commitThenLoseAck=false,?callable $beforeQuery=null)
    {
        parent::__construct($db->getCatalogueSqlConnection());
        $this->beforeQuery=$beforeQuery;
    }
    public function execQuery(string $query,array $parameters=[])
    {
        $this->queries[]=$query;
        if ($this->beforeQuery!==null) ($this->beforeQuery)($query,$this->connection);
        if ($this->failPrefix!==null && str_starts_with($query,$this->failPrefix)) return false;
        $result=parent::execQuery($query,$parameters);
        return $query==='COMMIT' && $this->commitThenLoseAck ? false : $result;
    }
}

final class FalloutCatalogueSqlTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }
    private function root(): string { return dirname(__DIR__,2); }

    private function fixture(): string
    {
        $root=sys_get_temp_dir().'/synth-catalogue-sql-'.bin2hex(random_bytes(6));
        mkdir($root.'/data',0700,true);
        $files=array_merge(SYNTH_FALLOUT_CATALOGUE_SQL_SOURCES,[
            'data/fallout4_catalogue_seed.sql','data/fallout4_catalogue_sql_manifest.json',
            'data/fallout_worldknowledge_vocabulary.json']);
        foreach ($files as $relative)
            if (!copy($this->root().'/'.$relative,$root.'/'.$relative)) throw new RuntimeException('Unable to copy disposable release fixture');
        return $root;
    }

    private function removeFixture(string $root): void
    {
        foreach (glob($root.'/data/*') ?: [] as $path) unlink($path);
        rmdir($root.'/data');rmdir($root);
    }

    private function rewriteManifest(string $root,callable $change): void
    {
        $path=$root.'/data/fallout4_catalogue_sql_manifest.json';
        $manifest=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        $change($manifest);
        file_put_contents($path,json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private function appendSql(string $root,string $sql): void
    {
        file_put_contents($root.'/data/fallout4_catalogue_seed.sql',$sql,FILE_APPEND);
        $this->rewriteManifest($root,static function(array &$manifest) use ($root): void {
            $manifest['sql_sha256']=hash_file('sha256',$root.'/data/fallout4_catalogue_seed.sql');
        });
    }

    private function snapshot(): array
    {
        $tables=['bio_templates','bio_templates_custom','bio_template_actor_map','descriptions','descriptions_custom',
            'worldknowledge','worldknowledge_catalogs','core_npc_master','core_npc_master_history','actor_identities','database_versioning'];
        $state=[];
        foreach ($tables as $table)
            $state[$table]=$this->db()->fetchAll('SELECT to_jsonb(row) AS row FROM synth.'.$table.' row ORDER BY to_jsonb(row)::text');
        return $state;
    }

    private function transactions(FalloutCatalogueSqlTestAdapter $db): array
    {
        return array_values(array_filter($db->queries,static fn(string $query): bool => in_array($query,['BEGIN','COMMIT','ROLLBACK'],true)));
    }

    private function clearFactoryCatalogue(): void
    {
        self::assertNotFalse($this->db()->execQuery("DELETE FROM synth.worldknowledge WHERE source_kind='factory'"));
        self::assertNotFalse($this->db()->execQuery('DELETE FROM synth.worldknowledge_catalogs'));
        self::assertNotFalse($this->db()->execQuery("DELETE FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'"));
    }

    public function testAppearanceRefinementUpdatesOnlyExactEarlierFactoryRows(): void
    {
        $db=$this->db();$pack=synthLoadFalloutContentPack($this->root());
        $rows=[];foreach ($pack['npcs'] as $row) $rows[$row['npc_name']]=$row;
        self::assertCount(18,$pack['factory_appearance_repairs']);
        foreach ($pack['factory_appearance_repairs'] as $repair) {
            $fields=$rows[$repair['npc_name']]['fields'];$fields['appearance']=$repair['previous_appearance'];
            $assignments=[];$parameters=[];
            foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) { $parameters[]=$fields[$field];$assignments[]=$field.'=$'.count($parameters); }
            $parameters[]=$repair['npc_name'];
            self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET '.implode(',',$assignments).' WHERE npc_name=$'.count($parameters),$parameters));
        }
        $first=$pack['factory_appearance_repairs'][0];$second=$pack['factory_appearance_repairs'][1];
        self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET core=$1 WHERE npc_name=$2',['Operator edited core.',$first['npc_name']]));
        self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET appearance=$1 WHERE npc_name=$2',['Operator edited appearance.',$second['npc_name']]));
        $customBefore=$db->fetchAll('SELECT to_jsonb(row) AS row FROM synth.bio_templates_custom row ORDER BY npc_name');
        synthInstallFalloutCatalogueSql($db,$this->root());
        foreach ($pack['factory_appearance_repairs'] as $repair) {
            $actual=$db->fetchOne('SELECT appearance FROM synth.bio_templates WHERE npc_name='.$db->escapeLiteral($repair['npc_name']));
            $expected=$repair['npc_name']===$first['npc_name'] ? $first['previous_appearance']
                : ($repair['npc_name']===$second['npc_name'] ? 'Operator edited appearance.' : $rows[$repair['npc_name']]['fields']['appearance']);
            self::assertSame($expected,$actual['appearance']);
        }
        self::assertSame($customBefore,$db->fetchAll('SELECT to_jsonb(row) AS row FROM synth.bio_templates_custom row ORDER BY npc_name'));
        $before=$this->snapshot();synthInstallFalloutCatalogueSql($db,$this->root());self::assertSame($before,$this->snapshot());
    }

    public function testReleaseSourceCountsAndAutomaticMigrationVersion(): void
    {
        $release=synthLoadFalloutCatalogueSql($this->root());
        self::assertEquals(['npcs'=>204,'mappings'=>207,'items'=>201,'lore'=>125],$release['manifest']['counts']);
        $version=$this->db()->fetchOne("SELECT min(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'");
        self::assertSame(strval(SYNTH_FALLOUT_CATALOGUE_SQL_VERSION),$version['version']);
        synthFalloutCatalogueSqlAssertInstalled($this->db(),$release);
    }

    public function testBootstrapSchedulesEarlierCatalogueAndSkipsInstalledRelease(): void
    {
        $db=$this->db();
        // DatabaseTestCase recreates schemas between classes, while the production
        // view helper caches its first run for the process. Restore that unrelated
        // fixture prerequisite with the production projection and migration marker.
        self::assertNotFalse($db->execQuery(<<<'SQL'
            CREATE OR REPLACE VIEW synth.synthnpcs AS
            SELECT id,npc_name,npc_favorite,lock_profile,prompt_head,npc_static_bio,
                   worldknowledge_tags,emote_moods,refid,base,gender,race,voiceid,core,
                   profile_id,dynamic_profile,personality,speechstyle,occupation,
                   appearance,skills,goals,metadata,extended_data,gamets_last_updated,
                   tags,md5
            FROM synth.core_npc_master
            WHERE npc_name <> 'The Narrator'
            SQL));
        self::assertNotFalse($db->execQuery(
            "INSERT INTO synth.database_versioning SELECT 'synthnpcs_view',20260713002 "
            ."WHERE NOT EXISTS (SELECT 1 FROM synth.database_versioning WHERE tablename='synthnpcs_view')"
        ));
        self::assertNotFalse($db->execQuery(
            "UPDATE synth.database_versioning SET version=20260713002 WHERE tablename='synthnpcs_view'"
        ));
        self::assertFalse(synthRuntimeNeedsDbUpdates(), 'A fully migrated disposable fixture must be current.');
        self::assertNotFalse($db->execQuery(
            "UPDATE synth.database_versioning SET version=20261009001 WHERE tablename='fallout4_catalogue_sql'"
        ));
        self::assertSame('20261009001', $db->fetchOne(
            "SELECT min(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'"
        )['version']);
        self::assertTrue(synthRuntimeNeedsDbUpdates(), 'The earlier catalogue must schedule the new migration.');

        $installed=synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertSame(20261009002,$installed['migration_version']);
        self::assertSame('20261009002', $db->fetchOne(
            "SELECT min(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'"
        )['version']);
        self::assertFalse(synthRuntimeNeedsDbUpdates(), 'The installed release must not schedule another migration.');
    }

    public function testEmptyTablesSeedAllKeysAndStableReferenceAndAliases(): void
    {
        $this->clearFactoryCatalogue();
        self::assertNotFalse($this->db()->execQuery('TRUNCATE synth.bio_templates,synth.bio_templates_custom,synth.bio_template_actor_map,synth.descriptions,synth.descriptions_custom'));
        $report=synthInstallFalloutCatalogueSql($this->db(),$this->root());
        self::assertSame(0,$report['profiles_filled']);
        self::assertSame('204',$this->db()->fetchOne('SELECT count(*) AS n FROM synth.bio_templates')['n']);
        self::assertSame('207',$this->db()->fetchOne('SELECT count(*) AS n FROM synth.bio_template_actor_map')['n']);
        self::assertSame('201',$this->db()->fetchOne('SELECT count(*) AS n FROM synth.descriptions')['n']);
        $pack=synthLoadFalloutContentPack($this->root());$references=0;$aliases=0;
        foreach ($pack['npcs'] as $row) {
            if (($row['mapping_kind'] ?? 'base')==='reference') {
                ++$references;
                $reference=$row['plugin'].'|'.$row['reference_local_formid'];
                self::assertSame($row['npc_name'],synth_fetch_bio_template($this->db(),'Renamed reference',$reference,'')['npc_name'] ?? null);
                self::assertNull(synth_fetch_bio_template($this->db(),$row['display_name']));
                self::assertNull(synth_fetch_bio_template($this->db(),$row['display_name'],'',$reference));
            }
            foreach ($row['base_aliases'] ?? [] as $alias) {
                ++$aliases;
                self::assertSame($row['npc_name'],synth_fetch_bio_template($this->db(),'Renamed physical form','',$row['plugin'].'|'.$alias)['npc_name'] ?? null);
            }
        }
        self::assertSame(3,$references);self::assertSame(3,$aliases);
    }

    public function testPopulatedUpgradePreservesAuthoredInactiveProfilesAndRepeatedBytes(): void
    {
        $this->clearFactoryCatalogue();$db=$this->db();
        $pack=synthLoadFalloutContentPack($this->root());$npc=$pack['npcs'][0]['npc_name'];$item=$pack['items'][0];
        self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET personality=$1 WHERE npc_name=$2',['Operator standard edit.',$npc]));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.bio_templates_custom(npc_name,npc_static_bio) VALUES($1,$2) ON CONFLICT(npc_name) DO UPDATE SET npc_static_bio=EXCLUDED.npc_static_bio',[$npc,'Operator custom biography.']));
        self::assertNotFalse($db->execQuery('UPDATE synth.descriptions SET description=$1 WHERE plugin=$2 AND baseid=$3',['Operator standard item.',$item['plugin'],$item['baseid']]));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.descriptions_custom(plugin,baseid,name,description) VALUES($1,$2,$3,$4) ON CONFLICT(plugin,baseid) DO UPDATE SET description=EXCLUDED.description',[$item['plugin'],$item['baseid'],'Custom item','Operator custom item.']));
        foreach ([['tato',true],['catalogue_inactive_custom',false]] as [$topic,$active])
            self::assertNotFalse($db->execQuery('INSERT INTO synth.worldknowledge(topic,canonical_topic,topic_desc,source_kind,is_active) VALUES($1,$2,$3,\'custom\',$4)',[$topic,$topic,'Operator lore retained.',$active ? 'true' : 'false']));
        // Deliberate remapping is an existing operator choice; the source identity must still exist.
        $base=$pack['npcs'][0];
        self::assertNotFalse($db->execQuery('UPDATE synth.bio_template_actor_map SET exact_name=$1 WHERE lower(base_plugin)=lower($2) AND base_local_formid=$3',['Operator mapping label.',$base['plugin'],$base['baseid']]));
        $identity=$db->fetchOne("INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name) VALUES('fo4','catalogue-sql-fixture','Fallout4.esm','0x00F00901','Catalogue fixture') RETURNING id");
        $actor=intval($identity['id']);
        self::assertNotFalse($db->execQuery("INSERT INTO synth.core_npc_master(npc_name,actor_identity_id,npc_static_bio,personality,voiceid,relationships,lock_profile) VALUES('Catalogue fixture',$1,NULL,'Operator profile.','fixture_voice','Operator relationship.',1)",[$actor]));
        $before=$this->snapshot();
        $adapter=new FalloutCatalogueSqlTestAdapter($db);
        synthInstallFalloutCatalogueSql($adapter,$this->root());
        $after=$this->snapshot();
        foreach (['bio_templates','bio_templates_custom','bio_template_actor_map','descriptions','descriptions_custom','core_npc_master','core_npc_master_history','actor_identities'] as $table)
            self::assertSame($before[$table],$after[$table],$table.' must retain operator and save-owned bytes');
        self::assertSame('Operator lore retained.',$db->fetchOne("SELECT topic_desc FROM synth.worldknowledge_effective WHERE canonical_topic='tato'")['topic_desc']);
        self::assertSame('f',$db->fetchOne("SELECT is_active FROM synth.worldknowledge WHERE canonical_topic='catalogue_inactive_custom'")['is_active']);
        self::assertSame(['BEGIN','COMMIT'],$this->transactions($adapter));
        synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertSame($after,$this->snapshot(),'Repeated release must not churn row contents/timestamps');
    }

    public function testForeignActiveCatalogueAndOldVersionsRemainIntact(): void
    {
        $this->clearFactoryCatalogue();$db=$this->db();
        self::assertNotFalse($db->execQuery("INSERT INTO synth.worldknowledge_catalogs(catalog_id,catalog_version,display_name,checksum_sha256,row_count,is_active) VALUES('operator-selected','custom-release','Operator selected',repeat('a',64),1,true)"));
        self::assertNotFalse($db->execQuery("INSERT INTO synth.worldknowledge(topic,canonical_topic,topic_desc,source_kind,catalog_id,catalog_version,content_hash) VALUES('foreign_topic','foreign_topic','Selected catalogue.','factory','operator-selected','custom-release',repeat('b',64))"));
        $foreignBefore=$db->fetchOne("SELECT to_jsonb(row) AS row FROM synth.worldknowledge_catalogs row WHERE catalog_id='operator-selected'");
        synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertSame($foreignBefore,$db->fetchOne("SELECT to_jsonb(row) AS row FROM synth.worldknowledge_catalogs row WHERE catalog_id='operator-selected'"));
        self::assertSame('operator-selected',$db->fetchOne('SELECT catalog_id FROM synth.worldknowledge_catalogs WHERE is_active')['catalog_id']);
        self::assertSame('Selected catalogue.',$db->fetchOne("SELECT topic_desc FROM synth.worldknowledge_effective WHERE canonical_topic='foreign_topic'")['topic_desc']);
        $own=synthLoadFalloutCatalogueSql($this->root())['manifest']['catalog'];
        self::assertSame('f',$db->fetchOne('SELECT is_active FROM synth.worldknowledge_catalogs WHERE catalog_id='.$db->escapeLiteral($own['catalog_id']).' AND catalog_version='.$db->escapeLiteral($own['catalog_version']))['is_active']);
        self::assertNotFalse($db->execQuery("UPDATE synth.worldknowledge_catalogs SET is_active=false WHERE catalog_id='operator-selected'"));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.worldknowledge_catalogs(catalog_id,catalog_version,display_name,checksum_sha256,row_count,is_active) VALUES($1,\'old-retained\',\'Old factory\',repeat(\'c\',64),1,true)',[$own['catalog_id']]));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.worldknowledge(topic,canonical_topic,topic_desc,source_kind,catalog_id,catalog_version) VALUES(\'old_topic\',\'old_topic\',\'Old retained article.\',\'factory\',$1,\'old-retained\')',[$own['catalog_id']]));
        synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertEquals($own,$db->fetchOne('SELECT catalog_id,catalog_version FROM synth.worldknowledge_catalogs WHERE is_active'));
        self::assertSame('Old retained article.',$db->fetchOne("SELECT topic_desc FROM synth.worldknowledge WHERE canonical_topic='old_topic'")['topic_desc']);
    }

    public function testMalformedManifestPathsHashesCountsAndTransactionCommandsWriteNothing(): void
    {
        $before=$this->snapshot();
        foreach (['sql_path','source_path','source_hash','sql_hash','counts','transaction'] as $kind) {
            $root=$this->fixture();$adapter=new FalloutCatalogueSqlTestAdapter($this->db());
            try {
                if ($kind==='source_hash') file_put_contents($root.'/data/fallout4_named_bios.csv',"\ntamper",FILE_APPEND);
                elseif ($kind==='sql_hash') file_put_contents($root.'/data/fallout4_catalogue_seed.sql',"\nSELECT 1;",FILE_APPEND);
                elseif ($kind==='transaction') $this->appendSql($root,"\nCOMMIT;\n");
                else $this->rewriteManifest($root,static function(array &$manifest) use ($kind): void {
                    if ($kind==='sql_path') $manifest['sql_file']='../unexpected.sql';
                    elseif ($kind==='source_path') $manifest['source_files']['../unexpected.csv']=str_repeat('a',64);
                    else ++$manifest['counts']['mappings'];
                });
                try { synthInstallFalloutCatalogueSql($adapter,$root);self::fail('Malformed release must fail: '.$kind); }
                catch (RuntimeException) { self::assertSame([],$adapter->queries,$kind); }
                self::assertSame($before,$this->snapshot());
            } finally { $this->removeFixture($root); }
        }
    }

    public function testSqlSyntaxAndMidImportFailureRestoreAllRows(): void
    {
        $this->clearFactoryCatalogue();$before=$this->snapshot();
        foreach (["\nSELECT FROM ;\n","\nSELECT 1/0;\n"] as $failure) {
            $root=$this->fixture();$adapter=new FalloutCatalogueSqlTestAdapter($this->db());
            try {
                $this->appendSql($root,$failure);
                try { synthInstallFalloutCatalogueSql($adapter,$root);self::fail('Invalid SQL must fail'); }
                catch (RuntimeException $error) { self::assertStringContainsString('execute cleanly',$error->getMessage()); }
                self::assertSame(['BEGIN','ROLLBACK'],$this->transactions($adapter));
                self::assertTrue($this->db()->isTransactionIdle());
                self::assertSame($before,$this->snapshot());
            } finally { $this->removeFixture($root); }
        }
    }

    public function testVersionFailureRollsBackImportedLoreAndSchemaUpgrade(): void
    {
        $this->clearFactoryCatalogue();$db=$this->db();
        self::assertNotFalse($db->execQuery('DELETE FROM synth.bio_template_actor_map WHERE base_plugin IS NULL'));
        self::assertNotFalse($db->execQuery('ALTER TABLE synth.bio_template_actor_map ALTER COLUMN base_plugin SET NOT NULL,ALTER COLUMN base_local_formid SET NOT NULL'));
        $before=$this->snapshot();$adapter=new FalloutCatalogueSqlTestAdapter($db,'UPDATE synth.database_versioning SET version=GREATEST');
        try { synthInstallFalloutCatalogueSql($adapter,$this->root());self::fail('Late version failure must fail'); }
        catch (RuntimeException $error) { self::assertStringContainsString('migration version',$error->getMessage()); }
        self::assertSame(['BEGIN','ROLLBACK'],$this->transactions($adapter));
        self::assertSame($before,$this->snapshot());
        self::assertSame('NO',$db->fetchOne("SELECT is_nullable FROM information_schema.columns WHERE table_schema='synth' AND table_name='bio_template_actor_map' AND column_name='base_plugin'")['is_nullable']);
    }

    public function testLegacyVersionTableWithoutUniqueKeyRemainsIdempotent(): void
    {
        $db=$this->db();
        self::assertNotFalse($db->execQuery('ALTER TABLE synth.database_versioning DROP CONSTRAINT IF EXISTS database_versioning_pkey'));
        self::assertNotFalse($db->execQuery("DELETE FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'"));
        for ($i=0;$i<2;++$i) synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertSame(['n'=>'1','version'=>strval(SYNTH_FALLOUT_CATALOGUE_SQL_VERSION)],$db->fetchOne("SELECT count(*) AS n,min(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'"));
    }

    public function testNewerInstalledVersionRejectsPayloadWithoutChangingCatalogueSelection(): void
    {
        $db=$this->db();
        self::assertNotFalse($db->execQuery("UPDATE synth.database_versioning SET version=$1 WHERE tablename='fallout4_catalogue_sql'",[SYNTH_FALLOUT_CATALOGUE_SQL_VERSION+1]));
        $before=$this->snapshot();$adapter=new FalloutCatalogueSqlTestAdapter($db);
        try { synthInstallFalloutCatalogueSql($adapter,$this->root());self::fail('Older release must not overwrite newer state'); }
        catch (RuntimeException $error) { self::assertStringContainsString('newer',$error->getMessage()); }
        self::assertNotContains(synthLoadFalloutCatalogueSql($this->root())['sql'],$adapter->queries);
        self::assertSame(['BEGIN','ROLLBACK'],$this->transactions($adapter));
        self::assertSame($before,$this->snapshot());
    }

    public function testOnlyExactSixPriorVault118FactoryRowsReceiveTagRepair(): void
    {
        $db=$this->db();$pack=synthLoadFalloutContentPack($this->root());
        $names=['fo4_pearl','fo4_maxwell','fo4_bert_riggs','fo4_gilda_broscoe','fo4_keith_mckinney','fo4_santiago_avida'];
        $rows=[];
        foreach ($pack['npcs'] as $row) if (in_array($row['npc_name'],$names,true)) $rows[$row['npc_name']]=$row;
        self::assertCount(6,$rows);
        foreach ($rows as $name=>$row) {
            $fields=$row['fields'];$fields['worldknowledge_tags']='prewar,'.$fields['worldknowledge_tags'];
            $assignments=[];$parameters=[];
            foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) { $parameters[]=$fields[$field];$assignments[]=$field.'=$'.count($parameters); }
            $parameters[]=$name;
            self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET '.implode(',',$assignments).' WHERE npc_name=$'.count($parameters),$parameters));
        }
        synthInstallFalloutCatalogueSql($db,$this->root());
        foreach ($rows as $name=>$row) {
            $actual=$db->fetchOne('SELECT '.implode(',',SYNTH_FALLOUT_CONTENT_FIELDS).' FROM synth.bio_templates WHERE npc_name='.$db->escapeLiteral($name));
            self::assertEquals($row['fields'],$actual,$name.' must receive only the factory tag correction');
        }
        // Custom tags or a changed core stop the same old-tag repair.
        $pearl=$rows['fo4_pearl'];$maxwell=$rows['fo4_maxwell'];
        self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET worldknowledge_tags=$1 WHERE npc_name=$2',
            ['prewar,'.$pearl['fields']['worldknowledge_tags'].',operator_tag','fo4_pearl']));
        self::assertNotFalse($db->execQuery('UPDATE synth.bio_templates SET worldknowledge_tags=$1,core=$2 WHERE npc_name=$3',
            ['prewar,'.$maxwell['fields']['worldknowledge_tags'],'Operator authored caretaker core.','fo4_maxwell']));
        self::assertNotFalse($db->execQuery('INSERT INTO synth.bio_templates_custom(npc_name,core,worldknowledge_tags) VALUES($1,$2,$3) ON CONFLICT(npc_name) DO UPDATE SET core=EXCLUDED.core,worldknowledge_tags=EXCLUDED.worldknowledge_tags',
            ['fo4_bert_riggs','Operator custom roboticist.','prewar,operator_robotics']));
        $before=$this->snapshot();
        synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertSame($before,$this->snapshot(),'Customized factory rows and overlays must retain exact bytes');
    }

    public function testLoreVectorsSupportSearchAndRepairNullStaleVectorsWithoutEnablingArticles(): void
    {
        $this->clearFactoryCatalogue();$db=$this->db();
        synthInstallFalloutCatalogueSql($db,$this->root());
        $catalog=synthLoadFalloutCatalogueSql($this->root())['manifest']['catalog'];
        $scope="source_kind='factory' AND catalog_id=".$db->escapeLiteral($catalog['catalog_id'])
            .' AND catalog_version='.$db->escapeLiteral($catalog['catalog_version']);
        self::assertSame('125',$db->fetchOne('SELECT count(*) AS n FROM synth.worldknowledge WHERE '.$scope.' AND native_vector IS NOT NULL')['n']);
        self::assertSame('1',$db->fetchOne("SELECT count(*) AS n FROM synth.worldknowledge WHERE ".$scope." AND canonical_topic='tato' AND native_vector @@ plainto_tsquery('simple','tato')")['n']);
        self::assertNotFalse($db->execQuery("UPDATE synth.worldknowledge SET native_vector=NULL,is_active=false WHERE ".$scope." AND canonical_topic='tato'"));
        self::assertNotFalse($db->execQuery("UPDATE synth.worldknowledge SET native_vector=to_tsvector('simple','obsolete_vector_fixture') WHERE ".$scope." AND canonical_topic='jet'"));
        synthInstallFalloutCatalogueSql($db,$this->root());
        $expected="setweight(to_tsvector('simple',coalesce(topic,'')),'A')"
            ."||setweight(to_tsvector('simple',coalesce(aliases,'')),'A')"
            ."||setweight(to_tsvector('simple',coalesce(topic_desc,'')),'B')"
            ."||setweight(to_tsvector('simple',coalesce(topic_desc_basic,'')),'C')";
        self::assertSame('0',$db->fetchOne('SELECT count(*) AS n FROM synth.worldknowledge WHERE '.$scope.' AND native_vector IS DISTINCT FROM ('.$expected.')')['n']);
        self::assertSame('f',$db->fetchOne("SELECT is_active FROM synth.worldknowledge WHERE ".$scope." AND canonical_topic='tato'")['is_active']);
        self::assertSame('1',$db->fetchOne("SELECT count(*) AS n FROM synth.worldknowledge WHERE ".$scope." AND canonical_topic='jet' AND native_vector @@ plainto_tsquery('simple','jet')")['n']);
        $after=$this->snapshot();
        synthInstallFalloutCatalogueSql($db,$this->root());
        self::assertSame($after,$this->snapshot(),'Correct vectors must not churn on repeated release');
    }

    public function testLostBackendBeforePayloadCannotReconnectOrAutocommit(): void
    {
        $this->clearFactoryCatalogue();$before=$this->snapshot();
        $sql=synthLoadFalloutCatalogueSql($this->root())['sql'];$injected=false;
        $control=pg_connect('host=localhost dbname=postgres user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse($control);
        $adapter=new FalloutCatalogueSqlTestAdapter($this->db(),null,false,
            static function(string $query,\PgSql\Connection $connection) use ($sql,$control,&$injected): void {
                if ($query!==$sql || $injected) return;
                $injected=true;
                $result=pg_query_params($control,'SELECT pg_terminate_backend($1)',[pg_get_pid($connection)]);
                if ($result===false || pg_fetch_result($result,0,0)!=='t')
                    throw new RuntimeException('Disposable backend termination injection failed');
            });
        try {
            try { synthInstallFalloutCatalogueSql($adapter,$this->root());self::fail('A lost backend must abort'); }
            catch (RuntimeException $error) {
                self::assertMatchesRegularExpression('/connection.*unavailable|execute cleanly|owned transaction is no longer active/',$error->getMessage());
            }
            self::assertTrue($injected);
            self::assertSame(['BEGIN','ROLLBACK'],$this->transactions($adapter));
            self::assertTrue($this->db()->isTransactionIdle());
            self::assertSame($before,$this->snapshot(),'A reconnect must not seed or record any catalogue data');
        } finally { pg_close($control); }
    }

    public function testResetOfSameHandleBeforePayloadIsRejectedByBackendIdentity(): void
    {
        $this->clearFactoryCatalogue();$before=$this->snapshot();
        $sql=synthLoadFalloutCatalogueSql($this->root())['sql'];$injected=false;
        $adapter=new FalloutCatalogueSqlTestAdapter($this->db(),null,false,
            static function(string $query,\PgSql\Connection $connection) use ($sql,&$injected): void {
                if ($query!==$sql || $injected) return;
                $injected=true;
                if (!pg_connection_reset($connection)) throw new RuntimeException('Disposable session reset injection failed');
            });
        try { synthInstallFalloutCatalogueSql($adapter,$this->root());self::fail('A reset backend must abort'); }
        catch (RuntimeException $error) { self::assertStringContainsString('session changed',$error->getMessage()); }
        self::assertTrue($injected);
        self::assertSame(['BEGIN','ROLLBACK'],$this->transactions($adapter));
        self::assertTrue($this->db()->isTransactionIdle());
        self::assertSame($before,$this->snapshot(),'The new session must never execute release writes');
    }

    public function testCallerOwnedTransactionIsRejectedWithoutCommittingIt(): void
    {
        $adapter=new FalloutCatalogueSqlTestAdapter($this->db());
        self::assertNotFalse($this->db()->execQuery('BEGIN'));
        try {
            try { synthInstallFalloutCatalogueSql($adapter,$this->root());self::fail('Caller transaction must be rejected'); }
            catch (LogicException) { self::assertSame([],$adapter->queries); }
            self::assertFalse($this->db()->isTransactionIdle());
        } finally { $this->db()->execQuery('ROLLBACK'); }
    }

    public function testLostCommitAcknowledgementReportsUncertaintyWithoutFalseRollback(): void
    {
        $this->clearFactoryCatalogue();$adapter=new FalloutCatalogueSqlTestAdapter($this->db(),null,true);
        try { synthInstallFalloutCatalogueSql($adapter,$this->root());self::fail('Lost acknowledgement must be uncertain'); }
        catch (RuntimeException $error) { self::assertStringContainsString('outcome is uncertain',$error->getMessage()); }
        self::assertSame(['BEGIN','COMMIT'],$this->transactions($adapter));
        self::assertTrue($this->db()->isTransactionIdle());
        self::assertSame(strval(SYNTH_FALLOUT_CATALOGUE_SQL_VERSION),$this->db()->fetchOne("SELECT min(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'")['version']);
    }
}
