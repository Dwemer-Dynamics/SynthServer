<?php declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'DatabaseTestCase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';

final class NpcPersistenceEncodingTest extends DatabaseTestCase
{
    private NpcMaster $npcMaster;

    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['db'] = new sql();
        $this->npcMaster = new NpcMaster();
    }

    public function testDatabaseFixtureUsesUtf8(): void
    {
        $this->assertSame('UTF8', synthRuntimeDatabaseEncoding());
        $this->assertTrue(synthRuntimeDatabaseEncodingIsSupported());
    }

    public function testLegacyMiddletermGeneratorRejectsUnownedNativeAndStaleSelections(): void
    {
        $db=$GLOBALS['db'];
        $identity=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'test.middleterm.boundary','display_name'=>'Middleterm Same Name'];
        $actor=synth_protocol_actor_identity_id($db,$identity);
        synth_upsert_npc_profile($db,$identity['display_name'],'',[],$actor);
        $native=$db->fetchOne('SELECT id FROM core_npc_master WHERE actor_identity_id='.$actor);
        $legacy=$db->fetchOne("INSERT INTO core_npc_master(npc_name,md5,extended_data)
            VALUES ('Middleterm Same Name','".md5('middleterm-legacy-fixture')."','{}') RETURNING id");
        $keys=['SELECTED_NPC','SELECTED_NPC_ID','ONCE_PER_RUN','SYNTH_NAME','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_REQUEST_EVENT'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        try {
            unset($GLOBALS['ONCE_PER_RUN'],$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'],$GLOBALS['SYNTH_REQUEST_EVENT']);
            $GLOBALS['SYNTH_NAME']='Unchanged worker context';
            foreach ([$native['id'],PHP_INT_MAX,$legacy['id']] as $id) {
                $GLOBALS['SELECTED_NPC_ID']=$id;
                $GLOBALS['SELECTED_NPC']=$id===$legacy['id'] ? 'Old label before rename' : $identity['display_name'];
                require dirname(__DIR__,2).'/service/processors/middleterm/cmd/generate.php';
                $this->assertSame('Unchanged worker context',$GLOBALS['SYNTH_NAME']);
            }
            $GLOBALS['SELECTED_NPC']=$identity['display_name'];$GLOBALS['SELECTED_NPC_ID']=$legacy['id'];
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']=['protocol_version'=>2];
            require dirname(__DIR__,2).'/service/processors/middleterm/cmd/generate.php';
            $this->assertSame('Unchanged worker context',$GLOBALS['SYNTH_NAME']);
            $this->assertArrayNotHasKey('ONCE_PER_RUN',$GLOBALS);
        } finally {
            foreach ($keys as $key) unset($GLOBALS[$key]);
            foreach ($saved as $key=>$value) $GLOBALS[$key]=$value;
        }
    }

    public function testMiddletermSchedulerKeepsLegacyEnablementAndExcludesBothNativeModes(): void
    {
        $db=$GLOBALS['db'];
        $profile=$db->fetchOne("INSERT INTO core_profiles(label,metadata) VALUES ('Middleterm fixture','{\"MIDDLE_TERM_MEMORY_ENABLED\":true}') RETURNING id");
        $actor=synth_protocol_actor_identity_id($db,['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'test.middleterm.scheduler','display_name'=>'Scheduler fixture']);
        $expected=[];
        foreach (['explicit'=>[null,'{"middle_term_enabled":1}'], 'inherited'=>[null,'{}'],
            'disabled'=>[null,'{"middle_term_enabled":0}'], 'native-explicit'=>[$actor,'{"middle_term_enabled":1}'],
            'native-inherited'=>[$actor,'{}']] as $label=>[$actorId,$extended]) {
            // Native identities are unique; reuse the explicit fixture row for the second policy through another actor.
            if ($label==='native-inherited') $actorId=synth_protocol_actor_identity_id($db,[
                'form_id'=>'0x0002F025','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'test.middleterm.scheduler','display_name'=>'Scheduler fixture']);
            $row=$db->fetchOne("INSERT INTO core_npc_master(npc_name,md5,profile_id,actor_identity_id,extended_data) VALUES ("
                .$db->escapeLiteral('Middleterm '.$label).','.$db->escapeLiteral(md5('middleterm-'.$label)).','.$profile['id'].','
                .($actorId ?? 'NULL').','.$db->escapeLiteral($extended).') RETURNING id');
            if (in_array($label,['explicit','inherited'],true)) $expected[]=intval($row['id']);
        }
        $observed=new class($db) {
            public array $selected=[];
            public function __construct(private object $inner) {}
            // This fixture covers the legacy scan; native workers are exercised with the production adapter separately.
            public function isTransactionIdle(): bool {return true;}
            public function fetchOne($query): array {
                if (str_contains($query,"job_type='middleterm'")) return [];
                return $this->inner->fetchOne($query);
            }
            public function execQuery($query): mixed {
                if (str_contains($query,'WITH candidate AS (')) return $this->inner->execQuery('SELECT 1 WHERE FALSE');
                if (str_contains($query,'UPDATE synth_meta.worker_jobs')) return true;
                return $this->inner->execQuery($query);
            }
            public function __call(string $name,array $args): mixed {return $this->inner->$name(...$args);}
            public function fetchAll($query): array {
                $rows=$this->inner->fetchAll($query);
                if (str_contains($query,'SELECT n.*') && str_contains($query,'middle_term_enabled')) {
                    $this->selected=$rows;return []; // Exercise real selection SQL without generating any provider work.
                }
                return $rows;
            }
        };
        $keys=['db','ENGINE_ROOT','ENGINE_PATH','FEATURES','TASKS'];$saved=array_intersect_key($GLOBALS,array_flip($keys));
        try {
            $GLOBALS['db']=$observed;$GLOBALS['ENGINE_ROOT']=dirname(__DIR__,2).'/';
            $GLOBALS['FEATURES']['MEMORY_EMBEDDING']['AUTO_CREATE_SUMMARYS']=false;
            require dirname(__DIR__,2).'/service/processors/middleterm/entrypoint.php';
            $GLOBALS['TASKS']['middleterm']['fn']();
            $selected=[];
            foreach ($observed->selected as $row) {
                $this->assertNull($row['actor_identity_id']);
                if (str_starts_with($row['npc_name'],'Middleterm ')) $selected[]=intval($row['id']);
            }
            sort($expected);sort($selected);$this->assertSame($expected,$selected);
        } finally {
            foreach ($keys as $key) unset($GLOBALS[$key]);
            foreach ($saved as $key=>$value) $GLOBALS[$key]=$value;
        }
    }

    public function testNativeProfileRowsKeepIdentityAcrossNamesAndLegacyImports(): void
    {
        $db=$GLOBALS['db'];
        $identity=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'test.profile.ownership','display_name'=>'Same Name Test'];
        $first=synth_protocol_actor_identity_id($db,$identity);
        $second=synth_protocol_actor_identity_id($db,array_replace($identity,['form_id'=>'0x0002F025']));
        $otherSave=synth_protocol_actor_identity_id($db,array_replace($identity,['playthrough_id'=>'test.profile.other']));
        foreach ([$first=>'first_voice',$second=>'second_voice',$otherSave=>'other_voice'] as $actor=>$voice) {
            synth_upsert_npc_profile($db,$identity['display_name'],'',[
                'voice'=>$voice,'reference_origin_plugin'=>'Fallout4.esm'
            ],$actor);
        }
        $rows=$db->fetchAll("SELECT * FROM synth.core_npc_master WHERE npc_name='Same Name Test' ORDER BY id");
        $this->assertCount(3,$rows);
        $this->assertCount(3,array_unique(array_column($rows,'md5')));
        $this->assertSame(['first_voice','second_voice','other_voice'],array_column($rows,'voiceid'));
        $firstRow=$rows[0];
        $this->assertTrue($this->npcMaster->update($firstRow['id'],[
            'npc_name'=>'Renamed Identity Test','md5'=>md5('Renamed Identity Test'),
            'actor_identity_id'=>$second,'voiceid'=>'manual_voice','lock_profile'=>1
        ]));
        $renamed=$this->npcMaster->getById($firstRow['id']);
        $this->assertSame($firstRow['md5'],$renamed['md5']);
        $this->assertSame($first,intval($renamed['actor_identity_id']));
        synth_upsert_npc_profile($db,'Captured Rename Test','',[
            'voice'=>'detected_voice','reference_origin_plugin'=>'Fallout4.esm'
        ],$first);
        $again=$this->npcMaster->getById($firstRow['id']);
        $this->assertSame('Captured Rename Test',$again['npc_name']);
        $this->assertSame('manual_voice',$again['voiceid']);
        $this->assertSame($firstRow['md5'],$again['md5']);
        $customSlot=$db->fetchOne("INSERT INTO core_profiles(label,default_npc) VALUES('Owned custom slot','0') RETURNING id");
        $this->assertTrue($this->npcMaster->update($firstRow['id'],['profile_id'=>$customSlot['id']]));
        $this->assertTrue($this->npcMaster->update($firstRow['id'],['individual_memory_enabled'=>1]));
        // A stale/foreign cached row cannot supply this actor's token or protected control settings.
        $this->assertTrue($this->npcMaster->update($firstRow['id'],['extended_data'=>[]],$rows[1]));
        $fresh=$this->npcMaster->getById($firstRow['id']);
        $this->assertSame($firstRow['md5'],$fresh['md5']);
        $this->assertSame(1,$this->npcMaster->getExtendedData($fresh)['individual_memory_enabled']);
        $this->assertSame($customSlot['id'],$fresh['profile_id']);
        $this->assertFalse($this->npcMaster->update(-1,['voiceid'=>'missing'],$rows[1]));
        $this->assertTrue($this->npcMaster->backupNpcById($firstRow['id'],'manual_identity_test'));
        $history=$db->fetchOne('SELECT npc_id,md5 FROM core_npc_master_history WHERE npc_id='.intval($firstRow['id']).' ORDER BY history_id DESC LIMIT 1');
        $this->assertSame($firstRow['id'],$history['npc_id']);
        $this->assertSame($firstRow['md5'],$history['md5']);
        $this->npcMaster->backupAllNpcs(99);
        $this->assertSame($fresh['gamets_last_updated'],$this->npcMaster->getById($firstRow['id'])['gamets_last_updated']);
        $this->assertSame('1',$db->fetchOne('SELECT count(*) AS n FROM core_npc_master_history WHERE npc_id='.intval($firstRow['id']))['n']);
        synth_ensure_npc($db,'Same Name Test','',['voice'=>'legacy_voice']);
        $legacy=$this->npcMaster->getByName('Same Name Test');
        $this->assertNull($legacy['actor_identity_id']);
        $this->assertSame('legacy_voice',$legacy['voiceid']);
        $this->assertSame('second_voice',$this->npcMaster->getById($rows[1]['id'])['voiceid']);
    }

    public function testNpcWithoutKnowledgeTagsClearsPreviousActorsKnowall(): void
    {
        $GLOBALS['WORLDKNOWLEDGE'] = 'knowall';

        $this->npcMaster->setOldGlobalsFromCurrentNpcData([
            'npc_name' => 'No Knowledge Tags Test',
        ], false);

        $this->assertSame('', $GLOBALS['WORLDKNOWLEDGE']);

        $this->npcMaster->setOldGlobalsFromCurrentNpcData([
            'npc_name' => 'Tagged Knowledge Test',
            'worldknowledge_tags' => 'community:sanctuary,domain:medicine',
        ], false);

        $this->assertSame('community:sanctuary,domain:medicine', $GLOBALS['WORLDKNOWLEDGE']);
    }

    public function testNpcMetadataRoundTripsUnicode(): void
    {
        $created = $this->npcMaster->create([
            'npc_name' => 'Sunny Smiles Encoding Test',
            'core' => 'Sanctuary scout',
            'metadata' => [
                'note' => "Sunny's gecko lesson - cafe",
            ],
            'extended_data' => [
                'note' => "Sunny\u{2019}s gecko lesson \u{2600}",
            ],
        ]);

        $this->assertTrue($created);
        $row = $this->npcMaster->getByName('Sunny Smiles Encoding Test');
        $this->assertNotEmpty($row);

        $extended = json_decode((string)$row['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("Sunny\u{2019}s gecko lesson \u{2600}", $extended['note']);
        $this->assertStringContainsString("Sunny\u{2019}s", (string)$row['extended_data']);
        $this->assertStringNotContainsString('\\u2019', (string)$row['extended_data']);
    }

    public function testDetectedVoiceDoesNotReplaceSavedProfileVoice(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Manual Voice Test',
            'voiceid' => 'custom_sunny_voice',
            'refid' => '0x00123456',
        ]));

        synth_ensure_npc($GLOBALS['db'], 'Sunny Manual Voice Test', '0x00123456', [
            'voice' => 'femaleadult04',
            'voice_formid' => '0x0000ABCD',
            'voice_name' => 'FemaleAdult04',
        ]);

        $row = $this->npcMaster->getByName('Sunny Manual Voice Test');
        $extended = json_decode((string)$row['extended_data'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('custom_sunny_voice', $row['voiceid']);
        $this->assertSame('femaleadult04', $extended['voice_metadata']['voiceid']);
        $this->assertSame('0x0000ABCD', $extended['voice_metadata']['voice_formid']);
    }

    public function testDetectedVoicePopulatesNewNpcProfile(): void
    {
        synth_ensure_npc($GLOBALS['db'], 'New Detected Voice Test', '0x00654321', [
            'voice' => 'maleadult03',
        ]);

        $row = $this->npcMaster->getByName('New Detected Voice Test');

        $this->assertNotEmpty($row);
        $this->assertSame('maleadult03', $row['voiceid']);
    }

    public function testResolvedVoiceMetadataUpdatesAtomicallyWithoutReplacingManualVoice(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Piper Voice Persistence Test',
            'voiceid' => '',
            'extended_data' => [],
        ]));
        $row = $this->npcMaster->getByName('Piper Voice Persistence Test');
        $GLOBALS['db']->execQuery("UPDATE synth.core_npc_master SET extended_data = '\"legacy malformed value\"'::jsonb WHERE id = " . (int)$row['id']);

        $metadata = [
            'voiceid' => 'femaleyoungeager',
            'voice_formid' => '0x0002F1F',
            'voice_name' => 'FemaleYoungEager',
            'source' => 'fo4_snapshot',
            'updated_at' => time(),
        ];
        $this->assertTrue($this->npcMaster->updateResolvedVoiceMetadata((int)$row['id'], 'femaleyoungeager', $metadata));

        $resolved = $this->npcMaster->getByName('Piper Voice Persistence Test');
        $extended = json_decode((string)$resolved['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('femaleyoungeager', $resolved['voiceid']);
        $this->assertEquals($metadata, $extended['voice_metadata']);
        $this->assertSame('metadata_resolved', $extended['voice_refresh_last_result']);

        $this->assertNotFalse($this->npcMaster->update((int)$resolved['id'], ['voiceid' => 'manual_piper_voice']));
        $this->assertTrue($this->npcMaster->updateResolvedVoiceMetadata((int)$resolved['id'], 'femaleadult02', [
            'voiceid' => 'femaleadult02',
        ]));
        $preserved = $this->npcMaster->getByName('Piper Voice Persistence Test');
        $this->assertSame('manual_piper_voice', $preserved['voiceid']);
        $this->assertEquals($metadata, json_decode((string)$preserved['extended_data'], true, 512, JSON_THROW_ON_ERROR)['voice_metadata']);
    }

    public function testStaleRuntimeSnapshotDoesNotClearResolvedVoice(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Nick Stale Voice Test',
            'voiceid' => '',
            'extended_data' => ['inventory' => ['10mm pistol']],
        ]));
        $staleRow = $this->npcMaster->getByName('Nick Stale Voice Test');
        $this->assertTrue($this->npcMaster->updateVoiceRefreshRequest((int)$staleRow['id'], time(), 1));

        $metadata = [
            'voiceid' => 'malenicksvalentine',
            'voice_formid' => '0x00002F25',
            'voice_name' => 'MaleNickValentine',
            'source' => 'fo4_snapshot',
            'updated_at' => time(),
        ];
        $this->assertTrue($this->npcMaster->updateResolvedVoiceMetadata((int)$staleRow['id'], 'malenicksvalentine', $metadata));
        $this->assertTrue($this->npcMaster->updateVoiceRefreshRequest((int)$staleRow['id'], time(), 2));

        $staleExtended = json_decode((string)$staleRow['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $staleExtended['voice_refresh_requested_at'] = time();
        $staleExtended['voice_refresh_attempts'] = 1;
        $staleExtended['voice_refresh_last_result'] = 'awaiting_plugin_profile';
        $staleRow['extended_data'] = $staleExtended;
        $this->assertNotFalse($this->npcMaster->updateByArray($staleRow));

        $preserved = $this->npcMaster->getByName('Nick Stale Voice Test');
        $extended = json_decode((string)$preserved['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('malenicksvalentine', $preserved['voiceid']);
        $this->assertEquals($metadata, $extended['voice_metadata']);
        $this->assertSame('metadata_resolved', $extended['voice_refresh_last_result']);
        $this->assertArrayNotHasKey('voice_refresh_requested_at', $extended);
        $this->assertSame(['10mm pistol'], $extended['inventory']);

        $this->assertNotFalse($this->npcMaster->update((int)$preserved['id'], ['voiceid' => '']));
        $cleared = $this->npcMaster->getByName('Nick Stale Voice Test');
        $this->assertSame('', trim((string)($cleared['voiceid'] ?? '')));
    }

    public function testUnrelatedStaleUpdatePreservesIndividualMemorySetting(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Individual Memory Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $staleRow = $this->npcMaster->getByName('Sunny Individual Memory Test');

        $this->assertNotFalse($this->npcMaster->update((int)$staleRow['id'], [
            'individual_memory_enabled' => 1,
        ]));

        $staleRow['metadata'] = ['actor_profile_updated' => time()];
        $this->assertNotFalse($this->npcMaster->updateByArray($staleRow));

        $row = $this->npcMaster->getByName('Sunny Individual Memory Test');
        $extended = json_decode((string)$row['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $extended['individual_memory_enabled']);
        $this->assertSame(['Varmint rifle'], $extended['inventory']);
    }

    public function testExplicitIndividualMemoryDisableRemovesSetting(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Disable Individual Memory Test',
            'individual_memory_enabled' => 1,
            'extended_data' => ['inventory' => ['Gecko meat']],
        ]));
        $row = $this->npcMaster->getByName('Sunny Disable Individual Memory Test');

        $this->assertNotFalse($this->npcMaster->update((int)$row['id'], [
            'individual_memory_enabled' => 0,
            'extended_data' => $row['extended_data'],
        ]));

        $updated = $this->npcMaster->getByName('Sunny Disable Individual Memory Test');
        $extended = json_decode((string)$updated['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('individual_memory_enabled', $extended);
    }

    public function testHistoryRestorePreservesCurrentIndividualMemorySetting(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Enable Memory After Snapshot Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Disable Memory After Snapshot Test',
            'individual_memory_enabled' => 1,
            'extended_data' => ['inventory' => ['Gecko meat']],
        ]));

        $this->assertTrue($this->npcMaster->backupAllNpcs(100));

        $enabledRow = $this->npcMaster->getByName('Enable Memory After Snapshot Test');
        $disabledRow = $this->npcMaster->getByName('Disable Memory After Snapshot Test');
        $this->assertNotFalse($this->npcMaster->update((int)$enabledRow['id'], [
            'individual_memory_enabled' => 1,
            'extended_data' => [
                'inventory' => ['Current inventory should roll back'],
                'middle_term_enabled' => 1,
                'auto_diary_enabled' => 1,
                'auto_diary_wait_enabled' => 1,
                'relationships_locked' => true,
                'relationships' => ['Player' => ['aff' => 42, 'type' => 'friend']],
            ],
        ]));
        $this->assertNotFalse($this->npcMaster->update((int)$disabledRow['id'], [
            'individual_memory_enabled' => 0,
        ]));

        $this->assertTrue($this->npcMaster->restoreNPC(100));

        $restoredEnabled = $this->npcMaster->getByName('Enable Memory After Snapshot Test');
        $enabledExtended = json_decode((string)$restoredEnabled['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $enabledExtended['individual_memory_enabled']);
        $this->assertSame(1, $enabledExtended['middle_term_enabled']);
        $this->assertSame(1, $enabledExtended['auto_diary_enabled']);
        $this->assertSame(1, $enabledExtended['auto_diary_wait_enabled']);
        $this->assertTrue($enabledExtended['relationships_locked']);
        $this->assertSame(42, $enabledExtended['relationships']['Player']['aff']);
        $this->assertSame(['Varmint rifle'], $enabledExtended['inventory']);

        $restoredDisabled = $this->npcMaster->getByName('Disable Memory After Snapshot Test');
        $disabledExtended = json_decode((string)$restoredDisabled['extended_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('individual_memory_enabled', $disabledExtended);
        $this->assertSame(['Gecko meat'], $disabledExtended['inventory']);
    }

    public function testRelationshipTimelineSnapshotsChangedStateAndRestoresByGameTime(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Piper Relationship Timeline Test',
            'extended_data' => ['relationships' => ['Player' => ['aff' => 10, 'type' => 'platonic']]],
        ]));
        $this->assertTrue($this->npcMaster->backupAllNpcs(100));

        $row = $this->npcMaster->getByName('Piper Relationship Timeline Test');
        $this->assertNotFalse($this->npcMaster->update((int)$row['id'], [
            'extended_data' => [
                'relationships' => ['Player' => ['aff' => 35, 'type' => 'platonic']],
                'relationships_last_eval' => 'volatile timestamp',
            ],
        ]));

        $savedGameRequest = $GLOBALS['gameRequest'] ?? null;
        $hadGameRequest = array_key_exists('gameRequest', $GLOBALS);
        try {
            $GLOBALS['gameRequest'] = ['rechat', '', 110];
            $this->assertTrue(synthRelationshipTimelineStamp((int)$row['id'], 'relationship_evaluation'));
            $GLOBALS['gameRequest'] = ['rechat', '', 111];
            $this->assertTrue(synthRelationshipTimelineStamp((int)$row['id'], 'relationship_evaluation'));
        } finally {
            if ($hadGameRequest) {
                $GLOBALS['gameRequest'] = $savedGameRequest;
            } else {
                unset($GLOBALS['gameRequest']);
            }
        }

        $snapshotCount = $GLOBALS['db']->fetchOne(
            "SELECT COUNT(*)::int AS count FROM core_npc_master_history
             WHERE npc_id = " . (int)$row['id'] . "
               AND extended_data->>'_synth_history_source' = 'relationship_evaluation'"
        );
        $this->assertSame(1, (int)$snapshotCount['count']);

        $timelineRows = synthFetchRelationshipTimelineChanges($GLOBALS['db'], [
            'npc_id' => (int)$row['id'], 'limit' => 10,
        ]);
        $this->assertCount(1, $timelineRows);
        $this->assertSame('affinity', $timelineRows[0]['changes'][0]['kind']);
        $this->assertSame(10, $timelineRows[0]['changes'][0]['before_aff']);
        $this->assertSame(35, $timelineRows[0]['changes'][0]['after_aff']);

        $this->assertTrue($this->npcMaster->restoreNPC(100));
        $atSave = $this->npcMaster->getExtendedData($this->npcMaster->getByName('Piper Relationship Timeline Test'));
        $this->assertSame(10, $atSave['relationships']['Player']['aff']);

        $this->assertTrue($this->npcMaster->restoreNPC(110));
        $afterChange = $this->npcMaster->getExtendedData($this->npcMaster->getByName('Piper Relationship Timeline Test'));
        $this->assertSame(35, $afterChange['relationships']['Player']['aff']);
        $this->assertArrayNotHasKey('_synth_history_source', $afterChange);
    }

    public function testNeverClearRelationshipsPreservesCurrentStateUntilDisabled(): void
    {
        $name = 'Never Clear Relationships Test';
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => $name,
            'personality' => 'Before save',
            'extended_data' => [
                'inventory' => ['10mm pistol'],
                'relationships' => ['Player' => ['aff' => 10]],
                'relationships_updated' => 'Historical marker',
            ],
        ]));
        $this->assertTrue($this->npcMaster->backupAllNpcs(100));
        $row = $this->npcMaster->getByName($name);
        $current = [
            'inventory' => ['Current inventory'],
            'relationships' => ['Player' => ['aff' => 80, 'notes' => null]],
            'relationships_analyzed' => false,
            'relationships_inferred' => [],
            'relationships_last_eval' => 200,
            'relationships_model' => null,
        ];
        $this->assertTrue($GLOBALS['db']->updateRow('core_npc_master', [
            'personality' => 'After save',
            'gamets_last_updated' => 200,
            'extended_data' => json_encode($current, JSON_THROW_ON_ERROR),
        ], 'id = ' . (int)$row['id']));

        $hadGlobal = array_key_exists('NEVER_CLEAR_RELATIONSHIP_DATA', $GLOBALS);
        $savedGlobal = $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] ?? null;
        try {
            $GLOBALS['db']->upsertRowOnConflict('general_settings', [
                'id' => 'NEVER_CLEAR_RELATIONSHIP_DATA', 'value' => 'true',
            ], 'id');
            $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
            $this->assertTrue($this->npcMaster->restoreNPC(100));
            $restored = $this->npcMaster->getByName($name);
            $expected = $current;
            $expected['inventory'] = ['10mm pistol'];
            $this->assertSame('Before save', $restored['personality']);
            $this->assertEquals($expected, $this->npcMaster->getExtendedData($restored));

            $GLOBALS['db']->updateRow('general_settings', ['value' => 'false'], "id = 'NEVER_CLEAR_RELATIONSHIP_DATA'");
            $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
            $this->assertTrue($this->npcMaster->restoreNPC(100));
            $rolledBack = $this->npcMaster->getExtendedData($this->npcMaster->getByName($name));
            $this->assertSame(10, $rolledBack['relationships']['Player']['aff']);
            $this->assertSame('Historical marker', $rolledBack['relationships_updated']);
        } finally {
            if ($hadGlobal) {
                $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = $savedGlobal;
            } else {
                unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
            }
        }
    }

    public function testRelationshipRestoreIndexExists(): void
    {
        $index = $GLOBALS['db']->fetchOne(
            "SELECT indexname FROM pg_indexes
             WHERE schemaname = 'synth' AND indexname = 'idx_core_npc_master_history_restore'"
        );
        $this->assertSame('idx_core_npc_master_history_restore', $index['indexname'] ?? null);
    }

    public function testInvalidJsonDoesNotOverwriteExistingExtendedData(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Invalid JSON Test',
            'extended_data' => ['inventory' => ['Gecko meat']],
        ]));
        $before = $this->npcMaster->getByName('Sunny Invalid JSON Test');

        try {
            $this->npcMaster->update((int)$before['id'], ['extended_data' => '{"inventory":']);
            $this->fail('Malformed extended_data should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('extended_data is invalid JSON', $e->getMessage());
        }

        $after = $this->npcMaster->getByName('Sunny Invalid JSON Test');
        $this->assertSame($before['extended_data'], $after['extended_data']);
    }

    public function testLegacyEmptyJsonListNormalizesToObject(): void
    {
        $this->assertSame(
            '{}',
            $this->npcMaster->encodeJsonObjectForPersistence('[]', 'extended_data')
        );
    }

    public function testMalformedStoredJsonScalarIsRepairedToObject(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Stored Scalar Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $row = $this->npcMaster->getByName('Sunny Stored Scalar Test');
        $this->assertNotEmpty($row);

        $this->assertNotFalse($GLOBALS['db']->execQuery(
            "UPDATE core_npc_master SET extended_data='\"legacy scalar\"'::jsonb WHERE id=" . intval($row['id'])
        ));
        $corruptRow = $this->npcMaster->getById((int)$row['id']);

        $this->assertSame([], $this->npcMaster->getExtendedData($corruptRow));
        $repairedRow = $this->npcMaster->getById((int)$row['id']);
        $this->assertSame([], json_decode((string)$repairedRow['extended_data'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('object', $GLOBALS['db']->fetchOne(
            "SELECT jsonb_typeof(extended_data) AS value_type FROM core_npc_master WHERE id=" . intval($row['id'])
        )['value_type']);
    }

    public function testInvalidUtf8DoesNotOverwriteExistingExtendedData(): void
    {
        $this->assertTrue($this->npcMaster->create([
            'npc_name' => 'Sunny Invalid UTF8 Test',
            'extended_data' => ['inventory' => ['Varmint rifle']],
        ]));
        $before = $this->npcMaster->getByName('Sunny Invalid UTF8 Test');

        try {
            $this->npcMaster->update((int)$before['id'], [
                'extended_data' => ['note' => "bad\x92byte"],
            ]);
            $this->fail('Malformed UTF-8 should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('contains invalid UTF-8', $e->getMessage());
        }

        $after = $this->npcMaster->getByName('Sunny Invalid UTF8 Test');
        $this->assertSame($before['extended_data'], $after['extended_data']);
    }

    public function testDatabaseUpdateFailureIsReturnedToCaller(): void
    {
        $result = $GLOBALS['db']->updateRow(
            'core_npc_master',
            ['column_that_does_not_exist' => 'value'],
            'id = 1'
        );

        $this->assertFalse($result);
    }
}
