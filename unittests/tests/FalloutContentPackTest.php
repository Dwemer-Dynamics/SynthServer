<?php
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';

final class FalloutContentPackTest extends DatabaseTestCase
{
    private function db(): sql { return $GLOBALS['db'] ??= new sql(); }

    public function testNamedCharactersNeedStableIdentityAndPreservePrewarSeparation(): void
    {
        $db=$this->db();
        $post=synth_fetch_bio_template($db,'Renamed robot','','Fallout4.esm|000179FF');
        $pre=synth_fetch_bio_template($db,'Codsworth','','Fallout4.esm|000AA763');
        self::assertNotNull($post);self::assertNotNull($pre);
        self::assertNotSame($post['npc_name'],$pre['npc_name']);
        self::assertContains($post['is_nonverbal_creature'],[false,'f',0,'0']);
        self::assertStringNotContainsString('commonwealth',$pre['worldknowledge_tags']);
        self::assertNull(synth_fetch_bio_template($db,'Codsworth'));
        self::assertNull(synth_fetch_bio_template($db,'Codsworth','','OtherMod.esp|000179FF'));
        self::assertNull(synth_fetch_bio_template($db,'Preston Garvey','','Fallout4.esm|001AC7C4'));
        $lucy=synth_fetch_bio_template($db,'Lucy Abernathy','','Fallout4.esm|0006B4D2');
        self::assertNotNull($lucy);
        self::assertStringNotContainsString('Shaun',$lucy['npc_static_bio']);
    }

    public function testImportIsIdempotentAndPreservesCustomDescriptionsAndAuthoredTemplates(): void
    {
        $db=$this->db();$root=dirname(__DIR__,2);
        $db->execQuery("UPDATE synth.bio_templates SET personality='Operator-authored character.' WHERE npc_name=(SELECT template_name FROM synth.bio_template_actor_map WHERE base_plugin='Fallout4.esm' AND base_local_formid='000179FF')");
        $db->execQuery("INSERT INTO synth.descriptions_custom(plugin,baseid,name,description) VALUES('Fallout4.esm','00004822','My pistol','My custom description.') ON CONFLICT(plugin,baseid) DO UPDATE SET description=EXCLUDED.description");
        $before=$db->fetchOne('SELECT (SELECT count(*) FROM synth.bio_templates) AS bios,(SELECT count(*) FROM synth.descriptions) AS items');
        synthInstallFalloutContentPack($db,$root,true);
        $report=synthInstallFalloutContentPack($db,$root,true);
        self::assertSame(0,$report['profiles_filled']);
        self::assertSame($before,$db->fetchOne('SELECT (SELECT count(*) FROM synth.bio_templates) AS bios,(SELECT count(*) FROM synth.descriptions) AS items'));
        $robot=synth_fetch_bio_template($db,'Codsworth','','Fallout4.esm|000179FF');
        self::assertSame('Operator-authored character.',$robot['personality']);
        self::assertSame('My custom description.',$db->fetchOne("SELECT description FROM synth.combined_descriptions WHERE plugin='Fallout4.esm' AND baseid='00004822'")['description']);
    }

    public function testExistingProfileFillsBlanksButPreservesVoiceRelationshipsAndEdits(): void
    {
        $db=$this->db();$root=dirname(__DIR__,2);
        $identity=$db->fetchOne("INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name) VALUES('fo4','content-fixture','Fallout4.esm','0x0001CA7D','Codsworth') RETURNING id");
        synth_upsert_npc_profile($db,'Codsworth','0x0001CA7D',['baseid'=>'Fallout4.esm|000179FF','reference_origin_plugin'=>'Fallout4.esm'],intval($identity['id']));
        $id=intval($identity['id']);
        $db->execQuery("UPDATE synth.core_npc_master SET npc_static_bio=NULL,personality='Edited personality.',voiceid='unchanged_voice',relationships='unchanged relationship text' WHERE actor_identity_id=$id");
        synthInstallFalloutContentPack($db,$root,true);
        $profile=$db->fetchOne("SELECT * FROM synth.core_npc_master WHERE actor_identity_id=$id");
        self::assertNotEmpty($profile['npc_static_bio']);
        self::assertSame('Edited personality.',$profile['personality']);
        self::assertSame('unchanged_voice',$profile['voiceid']);
        self::assertSame('unchanged relationship text',$profile['relationships']);
        $db->execQuery("UPDATE synth.core_npc_master SET npc_static_bio=NULL,lock_profile=1 WHERE actor_identity_id=$id");
        synthInstallFalloutContentPack($db,$root,true);
        self::assertNull($db->fetchOne("SELECT npc_static_bio FROM synth.core_npc_master WHERE actor_identity_id=$id")['npc_static_bio']);
        $db->execQuery("UPDATE synth.core_npc_master SET core=NULL,occupation='',appearance=NULL,skills='',goals=NULL,worldknowledge_tags=NULL,personality='A Fallout 4 wasteland resident. Replace this generated SYNTH seed profile with richer character data when available.',speechstyle='Speaks plainly and reacts to the Sole Survivor and the current situation.' WHERE actor_identity_id=$id");
        $before=$db->fetchOne("SELECT core,npc_static_bio,occupation,appearance,skills,goals,worldknowledge_tags,personality,speechstyle,voiceid,relationships FROM synth.core_npc_master WHERE actor_identity_id=$id");
        synth_upsert_npc_profile($db,'Codsworth','0x0001CA7D',['baseid'=>'Fallout4.esm|000179FF','reference_origin_plugin'=>'Fallout4.esm'], $id);
        self::assertSame($before,$db->fetchOne("SELECT core,npc_static_bio,occupation,appearance,skills,goals,worldknowledge_tags,personality,speechstyle,voiceid,relationships FROM synth.core_npc_master WHERE actor_identity_id=$id"));
    }

    public function testContactRefreshFillsOldSeedWithoutReplacingEditsOrAnotherSave(): void
    {
        $db=$this->db();
        $owners=[];
        foreach (['contact-save-one','contact-save-two'] as $save) {
            $identity=$db->fetchOne("INSERT INTO synth.actor_identities(game,playthrough_id,origin_plugin,full_form_id,display_name) VALUES('fo4','$save','Fallout4.esm','0x0001CA7D','Codsworth') RETURNING id");
            $owners[]=intval($identity['id']);
            synth_upsert_npc_profile($db,'Codsworth','0x0001CA7D',['baseid'=>'Fallout4.esm|000179FF','reference_origin_plugin'=>'Fallout4.esm','voice'=>'robotmrhandy'],intval($identity['id']));
        }
        [$first,$other]=$owners;
        $db->execQuery("UPDATE synth.core_npc_master SET core=NULL,npc_static_bio='',personality='A Fallout 4 wasteland resident. Replace this generated SYNTH seed profile with richer character data when available.',speechstyle='Speaks plainly and reacts to the Sole Survivor and the current situation.',occupation='My edited occupation.',voiceid='my_manual_voice',relationships='My edited relationships.' WHERE actor_identity_id=$first");
        $before=$db->fetchOne("SELECT id,md5,profile_id FROM synth.core_npc_master WHERE actor_identity_id=$first");
        $otherBefore=$db->fetchOne("SELECT * FROM synth.core_npc_master WHERE actor_identity_id=$other");

        // The contact path must use the captured base identity even if the displayed name changed.
        synth_upsert_npc_profile($db,'My renamed Codsworth','0x0001CA7D',['baseid'=>'Fallout4.esm|000179FF','reference_origin_plugin'=>'Fallout4.esm','voice'=>'captured_new_voice','race'=>'HandyRace'],$first);
        $profile=$db->fetchOne("SELECT * FROM synth.core_npc_master WHERE actor_identity_id=$first");
        $template=synth_fetch_bio_template($db,'My renamed Codsworth','','Fallout4.esm|000179FF');
        foreach (['core','npc_static_bio','personality','speechstyle'] as $field) {
            self::assertNotEmpty($template[$field]);
            self::assertSame($template[$field],$profile[$field]);
        }
        self::assertSame('My renamed Codsworth',$profile['npc_name']);
        self::assertSame('My edited occupation.',$profile['occupation']);
        self::assertSame('my_manual_voice',$profile['voiceid']);
        self::assertSame('My edited relationships.',$profile['relationships']);
        self::assertSame('HandyRace',$profile['race']);
        foreach ($before as $field=>$value) self::assertSame($value,$profile[$field]);
        self::assertSame($otherBefore,$db->fetchOne("SELECT * FROM synth.core_npc_master WHERE actor_identity_id=$other"));
    }

    public function testMalformedContentIsRejectedBeforeDatabaseWrites(): void
    {
        $root=sys_get_temp_dir().'/synth-content-'.bin2hex(random_bytes(6));
        mkdir($root.'/data',0700,true);
        $pack=synthLoadFalloutContentPack(dirname(__DIR__,2));
        $pack['npcs'][0]['fields']['voiceid']='Unexpected voice replacement';
        file_put_contents($root.'/data/fallout4_content_pack.json',json_encode($pack,JSON_THROW_ON_ERROR));
        $before=$this->db()->fetchOne('SELECT count(*) AS n FROM synth.bio_templates');
        try {
            synthInstallFalloutContentPack($this->db(),$root,true);
            self::fail('Unsupported authored field must be rejected');
        } catch (RuntimeException $expected) {
            self::assertSame($before,$this->db()->fetchOne('SELECT count(*) AS n FROM synth.bio_templates'));
        } finally {
            unlink($root.'/data/fallout4_content_pack.json');rmdir($root.'/data');rmdir($root);
        }
    }

    public function testCliUpgradeHandlesLegacyVersionTableWithoutUniqueKey(): void
    {
        global $argv;
        $db=$this->db();
        $db->execQuery('ALTER TABLE synth.database_versioning DROP CONSTRAINT IF EXISTS database_versioning_pkey');
        $db->execQuery("DELETE FROM synth.database_versioning WHERE tablename='fallout4_content_pack'");
        $saved=$GLOBALS['argv'] ?? null;
        $GLOBALS['argv']=['install-fallout-content.php','--apply','--fill-empty-profiles'];
        try {
            for ($attempt=0;$attempt<2;++$attempt) {
                ob_start();
                try { require dirname(__DIR__,2).'/tools/install-fallout-content.php';$output=ob_get_contents(); }
                finally { ob_end_clean(); }
                $report=json_decode($output,true,512,JSON_THROW_ON_ERROR);
                $pack=synthLoadFalloutContentPack(dirname(__DIR__,2));
                self::assertSame(count($pack['npcs']),$report['npc_templates']);
                self::assertSame(count($pack['items']),$report['item_descriptions']);
            }
            $version=$db->fetchOne("SELECT count(*) AS n,max(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_content_pack'");
            self::assertSame('1',$version['n']);
            self::assertSame(strval(SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION),$version['version']);
        } finally {
            if ($saved===null) unset($GLOBALS['argv']);else $GLOBALS['argv']=$saved;
        }
    }
}
