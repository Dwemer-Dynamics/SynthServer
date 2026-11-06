<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';

final class FalloutDlcContentPackTest extends DatabaseTestCase
{
    private function fixtureRoot(array $pack): string
    {
        $root=sys_get_temp_dir().'/synth-dlc-content-'.bin2hex(random_bytes(6));
        mkdir($root.'/data',0700,true);
        file_put_contents($root.'/data/fallout4_content_pack.json',json_encode($pack,JSON_THROW_ON_ERROR));
        return $root;
    }

    private function removeFixture(string $root): void
    {
        unlink($root.'/data/fallout4_content_pack.json');rmdir($root.'/data');rmdir($root);
    }

    private function row(string $plugin,string $name): array
    {
        $row=synthLoadFalloutContentPack(dirname(__DIR__,2))['npcs'][0];
        $row['plugin']=$plugin;$row['baseid']='00F00103';
        $row['npc_name']=$name;$row['display_name']='Fixture resident';
        $row['fields']['core']='A test-only resident of '.$plugin.'.';
        $row['fields']['npc_static_bio']='Test-only background for '.$name.'.';
        return $row;
    }

    public function testCanonicalIdentityUsesApprovedPluginAndLocalId(): void
    {
        $keys=[];
        foreach (SYNTH_FALLOUT_CONTENT_PLUGINS as $plugin) {
            $key=synthFalloutContentIdentity(['plugin'=>$plugin,'baseid'=>'000179FF']);
            self::assertNotNull($key);$keys[]=$key;
        }
        self::assertCount(7,array_unique($keys));
        foreach ([['plugin'=>'OtherMod.esp','baseid'=>'000179FF'],
            ['plugin'=>'DLCCoast.esm','baseid'=>'010179FF'],
            ['plugin'=>'DLCCoast.esm','baseid'=>'0x000179FF'],
            ['plugin'=>'DLCCoast.esm','baseid'=>'00000000'],
            ['plugin'=>'DLCCoast.esm','baseid'=>179],
            ['plugin'=>['DLCCoast.esm'],'baseid'=>'000179FF']] as $invalid)
            self::assertNull(synthFalloutContentIdentity($invalid));
    }

    public function testOlderContentVersionIsReportedAsPendingUpgrade(): void
    {
        // DatabaseTestCase replaces databases between classes, while runtime
        // helpers cache completion for one production process. Apply real
        // idempotent migrations in a fresh process against this disposable DB;
        // do not run DatabaseTestCase teardown or delete its shared template.
        $root=dirname(__DIR__,2);
        $bootstrap='$root='.var_export($root,true).';'
            .'require_once $root."/lib/runtime_bootstrap.php";'
            .'require_once $root."/lib/phpunit.class.php";'
            .'require $root."/conf/conf.sample.php";'
            .'synthRuntimeImportConfigVariables(get_defined_vars());'
            .'$GLOBALS["db"]=$db=new sql();'
            .'require $root."/debug/db_updates.php";'
            .'exit(synthRuntimeNeedsDbUpdates()?1:0);';
        $process=proc_open([PHP_BINARY,'-r',$bootstrap],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),'Disposable readiness bootstrap failed: '.$output.$errors);
        require_once dirname(__DIR__,2).'/lib/runtime_bootstrap.php';
        $db=$GLOBALS['db'] ??= new sql();
        self::assertFalse(synthRuntimeNeedsDbUpdates());
        self::assertNotFalse($db->execQuery('UPDATE synth.database_versioning SET version=$1 WHERE tablename=$2',
            [20261007001,'fallout4_content_pack']));
        self::assertTrue(synthRuntimeNeedsDbUpdates());
        self::assertNotFalse($db->execQuery('UPDATE synth.database_versioning SET version=$1 WHERE tablename=$2',
            [SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION,'fallout4_content_pack']));
        self::assertFalse(synthRuntimeNeedsDbUpdates());
    }

    public function testDifferentOfficialPluginsCanShareLocalNpcAndItemIds(): void
    {
        $pack=synthLoadFalloutContentPack(dirname(__DIR__,2));
        $pack['npcs']=[];$pack['items']=[];
        foreach (SYNTH_FALLOUT_CONTENT_PLUGINS as $index=>$plugin) {
            $pack['npcs'][]=$this->row($plugin,'fo4_fixture_dlc_'.$index);
            $pack['items'][]=['plugin'=>$plugin,'baseid'=>'00F00103','name'=>'Fixture object',
                'description'=>'A test-only object.','source_url'=>'https://example.invalid/fixture'];
        }
        $root=$this->fixtureRoot($pack);
        try {
            $loaded=synthLoadFalloutContentPack($root);
            self::assertCount(7,$loaded['npcs']);self::assertCount(7,$loaded['items']);
            $db=$GLOBALS['db'] ??= new sql();
            synthInstallFalloutContentPack($db,$root);
            foreach ($loaded['npcs'] as $row) {
                $template=synth_fetch_bio_template($db,'Renamed fixture','',$row['plugin'].'|'.$row['baseid']);
                self::assertSame($row['npc_name'],$template['npc_name'] ?? null);
            }
            self::assertNull(synth_fetch_bio_template($db,'Fixture resident','','OtherMod.esp|00F00103'));
        } finally { $this->removeFixture($root); }
    }

    public function testInvalidDlcPackIsRejectedBeforeImport(): void
    {
        $pack=synthLoadFalloutContentPack(dirname(__DIR__,2));
        $pack['npcs']=[$this->row('DLCCoast.esm','fo4_fixture_island')];$pack['items']=[];
        $db=$GLOBALS['db'] ??= new sql();
        $before=$db->fetchOne('SELECT count(*) AS n FROM synth.bio_templates');
        foreach (['unknown_plugin','runtime_prefix','duplicate_actor','unsupported_field','duplicate_item'] as $kind) {
            $invalid=$pack;
            if ($kind==='unknown_plugin') $invalid['npcs'][0]['plugin']='OtherMod.esp';
            elseif ($kind==='runtime_prefix') $invalid['npcs'][0]['baseid']='03000103';
            elseif ($kind==='duplicate_actor') {
                $invalid['npcs'][]=$invalid['npcs'][0];$invalid['npcs'][1]['npc_name']='fo4_fixture_duplicate';
            } elseif ($kind==='unsupported_field') $invalid['npcs'][0]['fields']['voiceid']='forbidden';
            else {
                $item=['plugin'=>'DLCCoast.esm','baseid'=>'00F00103','name'=>'Fixture object',
                    'description'=>'A test-only object.','source_url'=>'https://example.invalid/fixture'];
                $invalid['items']=[$item,$item];
            }
            $root=$this->fixtureRoot($invalid);
            try {
                try { synthInstallFalloutContentPack($db,$root,true);self::fail('Invalid '.$kind.' must fail'); }
                catch (RuntimeException) { self::assertSame($before,$db->fetchOne('SELECT count(*) AS n FROM synth.bio_templates')); }
            } finally { $this->removeFixture($root); }
        }
    }

    public function testDlcUpgradeFillsOnlyBlanksAndPreservesAuthoredAndLockedProfiles(): void
    {
        $db=$GLOBALS['db'] ??= new sql();
        $pack=synthLoadFalloutContentPack(dirname(__DIR__,2));
        $pack['npcs']=[$this->row('DLCCoast.esm','fo4_fixture_island'),
            $this->row('DLCNukaWorld.esm','fo4_fixture_park')];$pack['items']=[];
        $ids=[];
        foreach ($pack['npcs'] as $index=>$row) {
            $reference='0x0'.($index+3).'F00104';
            $result=$db->execQuery('INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name)
                VALUES($1,$2,$3,$4,$5) RETURNING id',['fo4','dlc-upgrade-fixture',$row['plugin'],$reference,$row['display_name']]);
            self::assertNotFalse($result);$identity=pg_fetch_assoc($result);
            $id=intval($identity['id']);$ids[]=$id;
            synth_upsert_npc_profile($db,$row['display_name'],$reference,
                ['baseid'=>$row['plugin'].'|'.$row['baseid'],'reference_origin_plugin'=>$row['plugin']],$id);
            $db->execQuery('UPDATE synth.core_npc_master SET npc_static_bio=NULL,personality=$1,
                voiceid=$2,relationships=$3,lock_profile=$4 WHERE actor_identity_id=$5',
                ['Authored fixture personality.','fixture_voice','Authored fixture relationships.',$index,$id]);
        }
        $root=$this->fixtureRoot($pack);
        try {
            $report=synthInstallFalloutContentPack($db,$root,true);
            self::assertSame(1,$report['profiles_filled']);
            foreach ($ids as $index=>$id) {
                $profile=$db->fetchOne('SELECT * FROM synth.core_npc_master WHERE actor_identity_id='.$id);
                self::assertSame('Authored fixture personality.',$profile['personality']);
                self::assertSame('fixture_voice',$profile['voiceid']);
                self::assertSame('Authored fixture relationships.',$profile['relationships']);
                if ($index===0) self::assertSame($pack['npcs'][0]['fields']['npc_static_bio'],$profile['npc_static_bio']);
                else self::assertNull($profile['npc_static_bio']);
            }
            $before=$db->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.core_npc_master t WHERE actor_identity_id IN ('
                .implode(',',$ids).') ORDER BY id');
            self::assertSame(0,synthInstallFalloutContentPack($db,$root,true)['profiles_filled']);
            self::assertSame($before,$db->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.core_npc_master t WHERE actor_identity_id IN ('
                .implode(',',$ids).') ORDER BY id'));
        } finally { $this->removeFixture($root); }
    }
}
