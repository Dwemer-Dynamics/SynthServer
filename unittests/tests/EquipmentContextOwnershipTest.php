<?php
require_once __DIR__ . '/DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . '/lib/data_functions.php';
require_once dirname(__DIR__, 2) . '/lib/synth_equipment_prompt.php';

final class EquipmentContextOwnershipTest extends DatabaseTestCase
{
    private array $savedGlobals = [];

    public function setUp(): void
    {
        parent::setUp();
        foreach (['SYNTH_REQUEST_EVENT','SYNTH_TURN_ACTOR_STATES','SYNTH_INPUT_TARGET_IDENTITY','SYNTH_PLAYER_IDENTITY',
            'SYNTH_INPUT_TARGET','SYNTH_EXTERNAL_REQUEST','SYNTH_RESPONSE_SPEAKER_FORMID','SYNTH_TURN_ACTIVE_QUESTS',
            'SYNTH_TURN_NEARBY_ITEMS','SYNTH_TURN_POINTS_OF_INTEREST','SYNTH_TURN_WORLD_STATE','gameRequest'] as $key) {
            $this->savedGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
        }
    }

    public function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => [$present, $value]) {
            unset($GLOBALS[$key]);
            if ($present) $GLOBALS[$key] = $value;
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $db = $GLOBALS['db'] ??= new sql();
        $player = ['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'equipment-owned-save','display_name'=>'Stu'];
        $target = ['form_id'=>'0x0001A4D8','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'equipment-owned-save','display_name'=>'Sturges'];
        $playerId = intval($db->fetchOne("INSERT INTO synth.actor_identities(game,full_form_id,origin_plugin,playthrough_id,display_name)
            VALUES('fo4','0x00000014','Fallout4.esm','equipment-owned-save','Stu') RETURNING id")['id']);
        $targetId = intval($db->fetchOne("INSERT INTO synth.actor_identities(game,full_form_id,origin_plugin,playthrough_id,display_name)
            VALUES('fo4','0x0001A4D8','Fallout4.esm','equipment-owned-save','Sturges') RETURNING id")['id']);
        $db->execQuery("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation)
            VALUES('equipment-owned-session','equipment-owned-save','fo4','flat','test','1.11.240',1)");
        $state = ['actor'=>$player,'weapon_drawn'=>true,'inventory_observation'=>'complete','inventory'=>[
            ['form_id'=>'0x00004822','origin_plugin'=>'Fallout4.esm','display_name'=>'10mm Pistol','form_type'=>43,'equipped'=>true]]];
        foreach ([1=>[$state], 2=>[]] as $sequence => $states) {
            $payload = ['player'=>$player,'target'=>$target,'audience'=>[$target],'actor_states'=>$states,
                'world'=>['location'=>'Fixture Sanctuary']];
            $db->execQuery("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,context_payload)
                VALUES('equipment-owned-session',1,$sequence,'equipment-owned-save',$playerId,$targetId,$1)", [json_encode($payload, JSON_THROW_ON_ERROR)]);
        }
        $event = ['protocol_version'=>2,'session_id'=>'equipment-owned-session','generation'=>1,'context_sequence'=>1,
            'runtime_variant'=>'flat','type'=>'input_text','payload'=>['text'=>'What am I holding?']];
        return [$db,$event,$player,$target,$state];
    }

    public function testEmptyOwnedDialogueCaptureClearsEarlierEquipmentInSameProcess(): void
    {
        [$db,$event,$player,$target] = $this->fixture();
        $request = [];
        synth_protocol_bind_dialogue_context($db,$event,$request);
        self::assertStringContainsString('10mm Pistol', synth_protocol_equipment_prompt($GLOBALS['SYNTH_TURN_ACTOR_STATES'],$player,$target));
        $event['context_sequence'] = 2;
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>$event];
        $db->execQuery('BEGIN READ ONLY');
        try {
            synth_protocol_bind_dialogue_context($db,$event,$request);
            $snapshot = synth_protocol_prompt_snapshot($db);
        } finally { $db->execQuery('ROLLBACK'); }
        self::assertSame([], $GLOBALS['SYNTH_TURN_ACTOR_STATES']);
        self::assertSame([], $snapshot['actor_states']);
        self::assertSame('', synth_protocol_equipment_prompt($snapshot['actor_states'],$snapshot['player'],$target));
        self::assertStringNotContainsString('10mm Pistol',$request[3]);
    }

    public function testEmptyOwnedTriggerCaptureClearsEarlierEquipmentInSameProcess(): void
    {
        [$db,$event,,$target,$state] = $this->fixture();
        $GLOBALS['SYNTH_TURN_ACTOR_STATES'] = [$state];
        $event['context_sequence'] = 2;
        $event['type'] = 'trigger';
        $event['payload'] = ['kind'=>'bored','actor'=>$target];
        $request = [];
        self::assertTrue(synth_protocol_bind_trigger_context($db,$event,$request));
        self::assertSame([], $GLOBALS['SYNTH_TURN_ACTOR_STATES']);
        self::assertEquals($target, $GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']);
        self::assertStringNotContainsString('10mm Pistol',$request[3]);
    }

    public function testNarratorAndEventOnlyInputsClearPreviousNpcTarget(): void
    {
        [$db,$event,,$target,$state] = $this->fixture();
        $event['context_sequence'] = 2;
        foreach (['NARRATOR','INJECTION_LOG'] as $mode) {
            $GLOBALS['SYNTH_INPUT_TARGET_IDENTITY'] = $target;
            $GLOBALS['SYNTH_TURN_ACTOR_STATES'] = [$state];
            $event['payload']['control'] = ['mode'=>$mode];
            $request = [];
            synth_protocol_bind_dialogue_context($db,$event,$request);
            self::assertSame([], $GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']);
            self::assertSame([], $GLOBALS['SYNTH_TURN_ACTOR_STATES']);
            self::assertSame('The Narrator',json_decode($request[3],true)['npc']);
        }
    }

    public function testMissingOrWrongGenerationNativeSnapshotNeverUsesLatestOrStaleGlobals(): void
    {
        [$db,$event,,$target,$state] = $this->fixture();
        $GLOBALS['SYNTH_TURN_ACTOR_STATES'] = [$state];
        foreach ([['context_sequence'=>99], ['generation'=>2], ['session_id'=>'equipment-other-session']] as $overrides) {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>array_replace($event,$overrides)];
            self::assertSame([],synth_protocol_prompt_snapshot($db));
            self::assertSame('',synth_protocol_equipment_prompt([],[], $target));
        }
    }
}
