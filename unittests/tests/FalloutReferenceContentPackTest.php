<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';

/** Forward to disposable PostgreSQL and fail only the selected map insertion. */
final class FalloutReferenceContentPackTestAdapter
{
    public array $queries=[];
    private int $maps=0;
    public function __construct(private sql $db,private ?int $failureMap=null) {}
    public function isTransactionIdle(): bool { return $this->db->isTransactionIdle(); }
    public function execQuery(string $query,array $parameters=[])
    {
        $this->queries[]=$query;
        if (str_starts_with($query,'INSERT INTO synth.bio_template_actor_map(')
            && ++$this->maps===$this->failureMap) return false;
        return $this->db->execQuery($query,$parameters);
    }
}

final class FalloutReferenceContentPackTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    private function row(string $name,string $id='00F00501',string $kind='reference'): array
    {
        $row=synthLoadFalloutContentPack(dirname(__DIR__,2))['npcs'][0];
        unset($row['baseid'],$row['mapping_kind'],$row['reference_local_formid'],$row['base_aliases']);
        $row['plugin']='DLCworkshop03.esm';$row['mapping_kind']=$kind;
        $row[$kind==='reference' ? 'reference_local_formid' : 'baseid']=$id;
        $row['npc_name']=$name;$row['display_name']='Security Chief Andersen';
        $row['source_url']='https://example.invalid/reference-fixture';
        foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) $row['fields'][$field]='Fixture '.$field.'.';
        $row['fields']['worldknowledge_tags']='commonwealth';
        return $row;
    }

    private function fixtureRoot(array $rows,array $items=[]): string
    {
        $root=sys_get_temp_dir().'/synth-reference-content-'.bin2hex(random_bytes(6));
        mkdir($root.'/data',0700,true);
        file_put_contents($root.'/data/fallout4_content_pack.json',json_encode([
            'schema'=>'synth.fallout4-content.v1','npcs'=>$rows,'items'=>$items],JSON_THROW_ON_ERROR));
        return $root;
    }

    private function removeFixture(string $root): void
    {
        unlink($root.'/data/fallout4_content_pack.json');rmdir($root.'/data');rmdir($root);
    }

    private function snapshot(): array
    {
        return [
            'templates'=>$this->db()->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.bio_templates t ORDER BY body'),
            'maps'=>$this->db()->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.bio_template_actor_map t ORDER BY body'),
            'columns'=>$this->db()->fetchAll("SELECT column_name,is_nullable FROM information_schema.columns WHERE table_schema='synth' AND table_name='bio_template_actor_map' ORDER BY column_name"),
            'constraints'=>$this->db()->fetchAll("SELECT conname,pg_get_constraintdef(oid) AS body FROM pg_constraint WHERE conrelid='synth.bio_template_actor_map'::regclass ORDER BY conname"),
            'indexes'=>$this->db()->fetchAll("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='synth' AND tablename='bio_template_actor_map' ORDER BY indexname"),
        ];
    }

    private function restoreLegacySchema(): void
    {
        // A pre-upgrade installation could contain only maps with a base.
        // Final packaged reference seeds exist only in the disposable test DB.
        self::assertNotFalse($this->db()->execQuery('DELETE FROM synth.bio_template_actor_map WHERE base_plugin IS NULL OR base_local_formid IS NULL'));
        self::assertNotFalse($this->db()->execQuery('ALTER TABLE synth.bio_template_actor_map
            DROP CONSTRAINT IF EXISTS bio_template_actor_map_base_pair_check,
            DROP CONSTRAINT IF EXISTS bio_template_actor_map_identity_present_check,
            ALTER COLUMN base_plugin SET NOT NULL,
            ALTER COLUMN base_local_formid SET NOT NULL'));
    }

    public function testReferenceOnlyActorDoesNotBindOtherFeralActorsOrTheirNames(): void
    {
        // This character uses a shared feral base. Only its placed reference may
        // select the named history, even when another actor has the same name.
        $row=$this->row('fo4_fixture_andersen');$root=$this->fixtureRoot([$row]);
        try {
            $this->restoreLegacySchema();$before=$this->snapshot();
            self::assertSame(1,synthInstallFalloutContentPack($this->db(),$root)['npc_templates']);
            self::assertSame(1,synthInstallFalloutContentPack($this->db(),$root)['npc_templates']);
            $maps=$this->db()->fetchAll("SELECT base_plugin,base_local_formid,reference_plugin,reference_local_formid FROM synth.bio_template_actor_map WHERE template_name='fo4_fixture_andersen'");
            self::assertSame([['base_plugin'=>null,'base_local_formid'=>null,
                'reference_plugin'=>'DLCworkshop03.esm','reference_local_formid'=>'00F00501']],$maps);
            $after=$this->snapshot();
            foreach ($before['maps'] as $map) self::assertContains($map,$after['maps']);
            self::assertSame($before['indexes'],$after['indexes']);
            $feralBase='Fallout4.esm|00F00601';
            $actual=synth_fetch_bio_template($this->db(),'Renamed ghoul','0x06F00501',$feralBase,'DLCworkshop03.esm');
            self::assertSame($row['npc_name'],$actual['npc_name'] ?? null);
            self::assertNull(synth_fetch_bio_template($this->db(),'Security Chief Andersen','0x06F00502',$feralBase,'DLCworkshop03.esm'));
            self::assertNull(synth_fetch_bio_template($this->db(),'fo4_fixture_andersen','0x06F00502',$feralBase,'DLCworkshop03.esm'));
            self::assertNull(synth_fetch_bio_template($this->db(),'fo4_fixture_andersen'));
            self::assertNull(synth_fetch_bio_template($this->db(),'fo4_fixture_andersen','0x03F00501',$feralBase,'DLCCoast.esm'));
        } finally { $this->removeFixture($root); }
    }

    public function testIdentitySeparatesMappingKindAndPluginAndKeepsLegacyBaseRows(): void
    {
        $base=$this->row('fo4_fixture_reference_base','00F00501','base');
        unset($base['mapping_kind']);
        $reference=$this->row('fo4_fixture_reference_only');
        $other=$this->row('fo4_fixture_reference_other');$other['plugin']='DLCCoast.esm';
        self::assertNotSame(synthFalloutContentIdentity($base),synthFalloutContentIdentity($reference));
        self::assertNotSame(synthFalloutContentIdentity($other),synthFalloutContentIdentity($reference));
        $root=$this->fixtureRoot([$base,$reference,$other]);
        try {
            self::assertCount(3,synthLoadFalloutContentPack($root)['npcs']);
            synthInstallFalloutContentPack($this->db(),$root);
            $resolved=synth_fetch_bio_template($this->db(),'Unknown','DLCworkshop03.esm|00F00501','DLCworkshop03.esm|00F00501');
            self::assertSame($reference['npc_name'],$resolved['npc_name'] ?? null);
            $resolved=synth_fetch_bio_template($this->db(),'Unknown','','DLCworkshop03.esm|00F00501');
            self::assertSame($base['npc_name'],$resolved['npc_name'] ?? null);
            $resolved=synth_fetch_bio_template($this->db(),'Unknown','DLCCoast.esm|00F00501');
            self::assertSame($other['npc_name'],$resolved['npc_name'] ?? null);
        } finally { $this->removeFixture($root); }
    }

    public function testPartialUnknownOrDuplicateReferenceMapsAreRejectedBeforeWrites(): void
    {
        $valid=$this->row('fo4_fixture_reference_valid');$before=$this->snapshot();
        foreach (['unknown_kind','null_kind','missing_id','both_ids','null_base','orphan_id',
            'runtime_prefix','zero_id','integer_id','unknown_plugin','redundant_plugin','duplicate'] as $kind) {
            $row=$valid;$rows=[];
            if ($kind==='unknown_kind') $row['mapping_kind']='display_name';
            elseif ($kind==='null_kind') $row['mapping_kind']=null;
            elseif ($kind==='missing_id') unset($row['reference_local_formid']);
            elseif ($kind==='both_ids') $row['baseid']='00F00601';
            elseif ($kind==='null_base') $row['baseid']=null;
            elseif ($kind==='orphan_id') unset($row['mapping_kind']);
            elseif ($kind==='runtime_prefix') $row['reference_local_formid']='06F00501';
            elseif ($kind==='zero_id') $row['reference_local_formid']='00000000';
            elseif ($kind==='integer_id') $row['reference_local_formid']=501;
            elseif ($kind==='unknown_plugin') $row['plugin']='OtherMod.esp';
            elseif ($kind==='redundant_plugin') $row['reference_plugin']='DLCCoast.esm';
            else { $rows[]=$valid;$row['npc_name']='fo4_fixture_reference_duplicate'; }
            $rows[]=$row;$root=$this->fixtureRoot($rows);
            try {
                $db=new FalloutReferenceContentPackTestAdapter($this->db());
                try { synthInstallFalloutContentPack($db,$root);self::fail('Invalid '.$kind.' must fail'); }
                catch (RuntimeException) { self::assertSame([],$db->queries); }
                self::assertSame($before,$this->snapshot());
            } finally { $this->removeFixture($root); }
        }
        $root=$this->fixtureRoot([],[[
            'plugin'=>'DLCworkshop03.esm','mapping_kind'=>'reference','reference_local_formid'=>'00F00501',
            'name'=>'Fixture item','description'=>'Fixture description.','source_url'=>'https://example.invalid/item']]);
        try {
            try { synthInstallFalloutContentPack($this->db(),$root);self::fail('Item reference mapping must fail'); }
            catch (RuntimeException) { self::assertSame($before,$this->snapshot()); }
        } finally { $this->removeFixture($root); }
    }

    public function testReferenceMapFailureRollsBackContentAndLegacySchemaUpgrade(): void
    {
        $root=$this->fixtureRoot([$this->row('fo4_fixture_reference_first'),
            $this->row('fo4_fixture_reference_second','00F00502')]);
        try {
            $this->restoreLegacySchema();$before=$this->snapshot();
            $db=new FalloutReferenceContentPackTestAdapter($this->db(),2);
            try { synthInstallFalloutContentPack($db,$root);self::fail('Second reference map must fail'); }
            catch (RuntimeException $error) { self::assertStringContainsString('stable mapping',$error->getMessage()); }
            $transactions=array_values(array_filter($db->queries,static fn($query)=>in_array($query,['BEGIN','COMMIT','ROLLBACK'],true)));
            self::assertSame(['BEGIN','ROLLBACK'],$transactions);
            self::assertTrue($this->db()->isTransactionIdle());
            self::assertSame($before,$this->snapshot());
        } finally { $this->removeFixture($root); }
    }

    public function testExpandedPackRetainsAnExplicitNpcCountLimit(): void
    {
        $rows=[];
        for ($index=1;$index<=300;++$index)
            $rows[]=$this->row('fo4_fixture_bound_'.$index,sprintf('00F1%04X',$index));
        $root=$this->fixtureRoot($rows);
        try { self::assertCount(300,synthLoadFalloutContentPack($root)['npcs']); }
        finally { $this->removeFixture($root); }
        $rows[]=$this->row('fo4_fixture_bound_overflow','00F1012D');
        $root=$this->fixtureRoot($rows);
        try {
            $db=new FalloutReferenceContentPackTestAdapter($this->db());
            try { synthInstallFalloutContentPack($db,$root);self::fail('301 NPCs must exceed the pack limit'); }
            catch (RuntimeException) { self::assertSame([],$db->queries); }
        } finally { $this->removeFixture($root); }
    }

    public function testUpgradedSchemaRequiresOneCompleteIdentityPair(): void
    {
        $root=$this->fixtureRoot([$this->row('fo4_fixture_reference_constraints')]);
        try {
            synthInstallFalloutContentPack($this->db(),$root);
            // Ask PostgreSQL whether CHECK constraints reject each malformed
            // row inside a subtransaction, keeping this disposable DB usable.
            foreach (['NULL,NULL,NULL,NULL',"'Fallout4.esm',NULL,NULL,NULL",
                "NULL,'00F00599',NULL,NULL","NULL,NULL,'DLCworkshop03.esm',NULL",
                "NULL,NULL,NULL,'00F00599'","NULL,NULL,'DLCworkshop03.esm','bad-id'"] as $values) {
                self::assertNotFalse($this->db()->execQuery("DO \$\$ BEGIN
                    BEGIN
                        INSERT INTO synth.bio_template_actor_map(template_name,base_plugin,base_local_formid,reference_plugin,reference_local_formid)
                        VALUES('fo4_fixture_orphan',".$values.");
                        RAISE EXCEPTION 'Malformed map accepted';
                    EXCEPTION WHEN check_violation THEN NULL;
                    END;
                END; \$\$"));
            }
            self::assertSame('0',$this->db()->fetchOne("SELECT count(*) AS n FROM synth.bio_template_actor_map WHERE template_name='fo4_fixture_orphan'")['n']);
        } finally { $this->removeFixture($root); }
    }
}
