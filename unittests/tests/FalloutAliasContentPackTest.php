<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';

final class FalloutAliasContentPackTestAdapter
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

final class FalloutAliasContentPackTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    private function row(string $name='fo4_fixture_continuing_character'): array
    {
        $row=synthLoadFalloutContentPack(dirname(__DIR__,2))['npcs'][0];
        unset($row['mapping_kind'],$row['reference_local_formid'],$row['base_aliases']);
        $row['plugin']='Fallout4.esm';$row['baseid']='00F10701';
        $row['base_aliases']=['00F10702','00F10703'];
        $row['npc_name']=$name;$row['display_name']='Continuing fixture character';
        $row['source_url']='https://example.invalid/continuing-fixture';
        foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) $row['fields'][$field]='Continuing fixture '.$field.'.';
        $row['fields']['worldknowledge_tags']='commonwealth';
        return $row;
    }

    private function fixtureRoot(array $rows): string
    {
        $root=sys_get_temp_dir().'/synth-alias-content-'.bin2hex(random_bytes(6));
        mkdir($root.'/data',0700,true);
        file_put_contents($root.'/data/fallout4_content_pack.json',json_encode([
            'schema'=>'synth.fallout4-content.v1','npcs'=>$rows,'items'=>[]],JSON_THROW_ON_ERROR));
        return $root;
    }

    private function removeFixture(string $root): void
    {
        unlink($root.'/data/fallout4_content_pack.json');rmdir($root.'/data');rmdir($root);
    }

    private function snapshot(): array
    {
        return [
            $this->db()->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.bio_templates t ORDER BY body'),
            $this->db()->fetchAll('SELECT row_to_json(t)::text AS body FROM synth.bio_template_actor_map t ORDER BY body'),
        ];
    }

    public function testAllFormsRouteToOneTemplateWithoutNameOrOtherPluginFallback(): void
    {
        $row=$this->row();$root=$this->fixtureRoot([$row]);
        try {
            self::assertSame(1,synthInstallFalloutContentPack($this->db(),$root)['npc_templates']);
            self::assertSame(1,synthInstallFalloutContentPack($this->db(),$root)['npc_templates']);
            self::assertSame('1',$this->db()->fetchOne("SELECT count(*) AS n FROM synth.bio_templates WHERE npc_name='fo4_fixture_continuing_character'")['n']);
            self::assertSame('3',$this->db()->fetchOne("SELECT count(*) AS n FROM synth.bio_template_actor_map WHERE template_name='fo4_fixture_continuing_character'")['n']);
            foreach (array_merge([$row['baseid']],$row['base_aliases']) as $id) {
                $template=synth_fetch_bio_template($this->db(),'Renamed fixture','','Fallout4.esm|'.$id);
                self::assertSame($row['npc_name'],$template['npc_name'] ?? null);
                self::assertSame($row['fields']['npc_static_bio'],$template['npc_static_bio']);
            }
            self::assertNull(synth_fetch_bio_template($this->db(),$row['npc_name']));
            self::assertNull(synth_fetch_bio_template($this->db(),$row['npc_name'],'','DLCCoast.esm|00F10702'));
            self::assertNull(synth_fetch_bio_template($this->db(),$row['npc_name'],'','Fallout4.esm|00F10704'));
        } finally { $this->removeFixture($root); }
    }

    public function testPhysicalFormsKeepDistinctActorProfilesAndAuthoredVoiceAndRelationships(): void
    {
        $row=$this->row();$root=$this->fixtureRoot([$row]);$ids=[];
        try {
            synthInstallFalloutContentPack($this->db(),$root);
            foreach ([$row['baseid'],$row['base_aliases'][0]] as $index=>$base) {
                $reference='0x00F1080'.($index+1);
                $result=$this->db()->execQuery('INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name) VALUES($1,$2,$3,$4,$5) RETURNING id',
                    ['fo4','continuing-alias-fixture','Fallout4.esm',$reference,$row['display_name']]);
                self::assertNotFalse($result);$actor=pg_fetch_assoc($result);$id=intval($actor['id']);$ids[]=$id;
                synth_upsert_npc_profile($this->db(),$row['display_name'],$reference,
                    ['baseid'=>'Fallout4.esm|'.$base,'reference_origin_plugin'=>'Fallout4.esm'],$id);
                self::assertNotFalse($this->db()->execQuery('UPDATE synth.core_npc_master SET npc_static_bio=NULL,voiceid=$1,relationships=$2 WHERE actor_identity_id=$3',
                    ['fixture_form_voice_'.$index,'Authored form relationships '.$index.'.',$id]));
            }
            self::assertNotSame($ids[0],$ids[1]);
            self::assertSame(2,synthInstallFalloutContentPack($this->db(),$root,true)['profiles_filled']);
            $profileIds=[];
            foreach ($ids as $index=>$id) {
                $profile=$this->db()->fetchOne('SELECT id,npc_static_bio,voiceid,relationships FROM synth.core_npc_master WHERE actor_identity_id='.$id);
                $profileIds[]=$profile['id'];
                self::assertSame($row['fields']['npc_static_bio'],$profile['npc_static_bio']);
                self::assertSame('fixture_form_voice_'.$index,$profile['voiceid']);
                self::assertSame('Authored form relationships '.$index.'.',$profile['relationships']);
            }
            self::assertCount(2,array_unique($profileIds));
        } finally { $this->removeFixture($root); }
    }

    public function testAliasIdsAreBoundedCanonicalAndCannotCollideWithAnyNpcBase(): void
    {
        $valid=$this->row();$before=$this->snapshot();
        foreach (['null_list','associative_list','too_many','integer_id','runtime_prefix','zero_id',
            'duplicate_alias','alias_equals_primary','alias_equals_other_primary','alias_equals_other_alias','reference_aliases'] as $kind) {
            $row=$valid;$rows=[];
            if ($kind==='null_list') $row['base_aliases']=null;
            elseif ($kind==='associative_list') $row['base_aliases']=['alternate'=>'00F10702'];
            elseif ($kind==='too_many') $row['base_aliases']=array_map(static fn($index)=>sprintf('00F109%02X',$index),range(1,9));
            elseif ($kind==='integer_id') $row['base_aliases']=[10702];
            elseif ($kind==='runtime_prefix') $row['base_aliases']=['01F10702'];
            elseif ($kind==='zero_id') $row['base_aliases']=['00000000'];
            elseif ($kind==='duplicate_alias') $row['base_aliases']=['00F10702','00F10702'];
            elseif ($kind==='alias_equals_primary') $row['base_aliases']=[$row['baseid']];
            elseif ($kind==='alias_equals_other_primary' || $kind==='alias_equals_other_alias') {
                $other=$this->row('fo4_fixture_alias_collision');
                $other['baseid']=$kind==='alias_equals_other_primary' ? '00F10702' : '00F10901';
                $other['base_aliases']=$kind==='alias_equals_other_primary' ? [] : ['00F10703'];
                $rows[]=$other;
            } else {
                unset($row['baseid']);$row['mapping_kind']='reference';$row['reference_local_formid']='00F10901';
            }
            $rows[]=$row;$root=$this->fixtureRoot($rows);
            try {
                $db=new FalloutAliasContentPackTestAdapter($this->db());
                try { synthInstallFalloutContentPack($db,$root);self::fail('Invalid '.$kind.' must fail'); }
                catch (RuntimeException) { self::assertSame([],$db->queries); }
                self::assertSame($before,$this->snapshot());
            } finally { $this->removeFixture($root); }
        }
        $other=$this->row('fo4_fixture_other_plugin_forms');$other['plugin']='DLCCoast.esm';
        $root=$this->fixtureRoot([$valid,$other]);
        try { self::assertCount(2,synthLoadFalloutContentPack($root)['npcs']); }
        finally { $this->removeFixture($root); }
    }

    public function testAliasMappingFailureRollsBackItsTemplateAndEarlierMaps(): void
    {
        $root=$this->fixtureRoot([$this->row()]);$before=$this->snapshot();
        try {
            $db=new FalloutAliasContentPackTestAdapter($this->db(),2);
            try { synthInstallFalloutContentPack($db,$root);self::fail('Alias insertion must fail'); }
            catch (RuntimeException $error) { self::assertStringContainsString('stable mapping',$error->getMessage()); }
            $transactions=array_values(array_filter($db->queries,static fn($query)=>in_array($query,['BEGIN','COMMIT','ROLLBACK'],true)));
            self::assertSame(['BEGIN','ROLLBACK'],$transactions);
            self::assertTrue($this->db()->isTransactionIdle());
            self::assertSame($before,$this->snapshot());
        } finally { $this->removeFixture($root); }
    }
}
