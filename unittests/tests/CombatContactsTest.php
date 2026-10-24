<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/response.php';
require_once dirname(__DIR__,2).'/lib/phpunit.class.php';

final class CombatContactsFixtureDatabase extends sql
{
    public function withNativeRequestLock(callable $operation,int $timeout=0): mixed
    {
        $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];$this->execQuery('BEGIN');
        try {
            $this->execQuery("SET LOCAL statement_timeout='2s'");
            $this->execQuery('SELECT pg_advisory_xact_lock(hashtext($1))',[$event['session_id']]);
            synth_protocol_assert_request_active();$canonical=synth_protocol_canonical_json($event);
            $result=$this->execQuery('SELECT 1 FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e USING(session_id,request_id)
                WHERE p.session_id=$1 AND p.request_id=$2 AND p.canonical_event=$3::jsonb AND p.event_sha256=$4 AND e.request_sha256=p.event_sha256',
                [$event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical)]);
            if(!pg_fetch_assoc($result))throw new LogicException('combat_fixture_canonical_owner_changed');
            // The callback runs prepared route queries on the same connection and ownership transaction.
            $connection=(new ReflectionProperty(sql::class,'link'))->getValue();
            $value=$operation($connection,$event);$this->execQuery('COMMIT');return $value;
        } finally {if(!$this->isTransactionIdle())$this->execQuery('ROLLBACK');}
    }
}

final class CombatContactsTest extends DatabaseTestCase
{
    private array $event;
    private array $payload;
    private $database;
    private array $saved=[];

    public function setUp(): void
    {
        parent::setUp();
        foreach (['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_NAME','SYNTH_SPEECH_ROLE',
            'SYNTH_CLIENT_CAPABILITIES','SYNTH_JSON_RESPONSE_LINES','SYNTH_RESPONSE_STREAMING','SYNTH_PROTOCOL_RESPONSE_BODY',
            'SYNTH_PROTOCOL_RESPONSE_STARTED','SYNTH_PROTOCOL_RESPONSE_SEQUENCE'] as $key)
            $this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
        $fixture=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-combat-contacts.json'),true,flags:JSON_THROW_ON_ERROR);
        $this->payload=$fixture['payload'];
        $owner=&$this->payload['actor_states'][0];
        $owner['combat_target']=['observation'=>'none','actor'=>null,'race'=>''];$owner['in_combat']=false;
        $owner['combat_contacts'][0]['actor']['display_name']='Mole Rat';
        $owner['combat_contacts'][0]['race']='Molerat';
        unset($owner);
        $this->event=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'input_text','game'=>'fo4','runtime_variant'=>'flat',
            'session_id'=>'combat-contact-session','request_id'=>'combat-contact-input','turn_id'=>'combat-contact-turn','generation'=>1,'context_sequence'=>7,
            'capabilities'=>['context.combat_contacts','context.combat_targets','dialogue.turn_ownership'],
            'payload'=>['text'=>'what are these creatures?!?!?!']];
        $this->database=$GLOBALS['db']=new CombatContactsFixtureDatabase();
        $playerId=synth_protocol_actor_identity_id($this->database,$this->payload['player']);
        $actorId=synth_protocol_actor_identity_id($this->database,$this->payload['target']);
        $this->database->execQuery("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,
            active_generation,capabilities,state,active_turn_id,active_turn_sequence) VALUES('combat-contact-session','save-001','fo4','flat','fixture','fixture',1,$1::jsonb,'active','combat-contact-turn',7)",
            [json_encode($this->event['capabilities'])]);
        $this->database->execQuery("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload)
            VALUES('combat-contact-session',1,7,'save-001',$1,$2,$3::jsonb,$4::jsonb)",[$playerId,$actorId,json_encode([$actorId]),json_encode($this->payload)]);
        $this->persistEvent();
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;$GLOBALS['SYNTH_NAME']=$this->payload['target']['display_name'];
        $GLOBALS['SYNTH_SPEECH_ROLE']='npc';$GLOBALS['SYNTH_CLIENT_CAPABILITIES']=$this->event['capabilities'];
    }

    public function tearDown(): void
    {
        parent::tearDown();
        foreach($this->saved as $key=>[$exists,$value]) {unset($GLOBALS[$key]);if($exists)$GLOBALS[$key]=$value;}
    }

    private function persistEvent(): void
    {
        $canonical=synth_protocol_canonical_json($this->event);$hash=hash('sha256',$canonical);
        $this->database->execQuery("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state)
            VALUES('combat-contact-session','combat-contact-input','combat-contact-turn',1,$1,$2,'claimed')
            ON CONFLICT(session_id,request_id) DO UPDATE SET request_sha256=$1,event_type=$2",[$hash,$this->event['type']]);
        $this->database->execQuery("INSERT INTO synth_meta.protocol_events(session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256)
            VALUES('combat-contact-session','combat-contact-input','combat-contact-turn',1,$1,$2::jsonb,$3)
            ON CONFLICT(session_id,request_id) DO UPDATE SET canonical_event=$2::jsonb,event_sha256=$3,event_type=$1",[$this->event['type'],$canonical,$hash]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=synth_normalize_json_event($this->event);
    }

    private function updatePayload(): void
    {
        $this->database->execQuery("UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE session_id='combat-contact-session'",[json_encode($this->payload)]);
    }

    private function useAwareness(): array
    {
        $this->event['capabilities']=array_values(array_diff($this->event['capabilities'],['context.combat_contacts']));
        $this->event['capabilities'][]='context.enemy_awareness';
        $owner=&$this->payload['actor_states'][0];$contact=$owner['combat_contacts'][0];unset($owner['combat_contacts']);
        $owner['enemy_awareness']=['current'=>[['actor'=>$contact['actor'],'race'=>$contact['race'],'distance'=>125,
            'hostile'=>true,'in_combat'=>false,'line_of_sight'=>'visible']],'recent'=>[]];
        unset($owner);return $contact;
    }

    public function testAwarenessDirectInputUsesOnlyFreshOwnedCurrentIdentitiesAndKeepsRecentSeparate(): void
    {
        $contact=$this->useAwareness();$this->persistEvent();$this->updatePayload();
        $this->assertSame('I can identify Mole Rat.',synth_combat_identification_reply($this->database,$this->event)['text']);
        $owner=&$this->payload['actor_states'][0];$owner['enemy_awareness']['current']=[];
        $owner['enemy_awareness']['recent']=[['actor'=>$contact['actor'],'race'=>$contact['race'],'observation'=>'seen_hostile','age_ms'=>500]];
        unset($owner);$this->updatePayload();
        $this->assertSame('I have not identified the attackers.',synth_combat_identification_reply($this->database,$this->event)['text']);
        $this->event['payload']['text']='what were those creatures?!';$this->persistEvent();
        $this->assertSame('I recently saw Mole Rat as hostile.',synth_combat_identification_reply($this->database,$this->event)['text']);
        $this->event['type']='input_audio';$this->event['payload']=['media'=>['schema'=>'synth.media.v1']];$this->persistEvent();
        $this->assertSame('I recently saw Mole Rat as hostile.',synth_combat_identification_reply($this->database,$this->event,'what were those things?')['text']);
        $this->assertNull(synth_combat_identification_reply($this->database,$this->event,null));
        foreach(['what were those things? wait here','Tell a story: what did we just fight','"what were those creatures?"'] as $text)
            $this->assertNull(synth_combat_identification_reply($this->database,$this->event,$text));
    }

    public function testRecentDirectReplyExpiresAtThirtySecondsIncludingFrameAgeAndRejectsForeignOrUnsafeObservations(): void
    {
        $contact=$this->useAwareness();$owner=&$this->payload['actor_states'][0];$owner['enemy_awareness']['current']=[];
        $owner['enemy_awareness']['recent']=[['actor'=>$contact['actor'],'race'=>$contact['race'],'observation'=>'seen_dead','age_ms'=>1000]];
        unset($owner);$this->event['payload']['text']='what did we just fight?';$this->persistEvent();$this->updatePayload();
        $this->assertSame('I recently saw Mole Rat dead.',synth_combat_identification_reply($this->database,$this->event)['text']);
        $original=$this->payload;
        foreach(['other-owner','duplicate-owner','foreign-save','unsafe-label','wrong-generation','retired-session','frame-stale','expired-observation','frame-age-expires-observation'] as $case) {
            $this->payload=$original;
            $this->database->execQuery("UPDATE synth_meta.runtime_sessions SET state='active',active_generation=1 WHERE session_id='combat-contact-session'");
            $this->database->execQuery("UPDATE synth_meta.context_snapshots SET received_at=(SELECT claimed_at FROM synth_meta.request_executions WHERE request_id='combat-contact-input') WHERE session_id='combat-contact-session'");
            if($case==='other-owner')$this->payload['actor_states'][0]['actor']['form_id']='0x00019FD9';
            if($case==='duplicate-owner')$this->payload['actor_states'][]=$this->payload['actor_states'][0];
            if($case==='foreign-save')$this->payload['actor_states'][0]['enemy_awareness']['recent'][0]['actor']['playthrough_id']='other-save';
            if($case==='unsafe-label')$this->payload['actor_states'][0]['enemy_awareness']['recent'][0]['actor']['display_name']='Radroach|command|Halt';
            if($case==='expired-observation')$this->payload['actor_states'][0]['enemy_awareness']['recent'][0]['age_ms']=30001;
            if($case==='frame-age-expires-observation') {
                $this->payload['actor_states'][0]['enemy_awareness']['recent'][0]['age_ms']=29500;
                $this->database->execQuery("UPDATE synth_meta.context_snapshots SET received_at=received_at-INTERVAL '2 seconds' WHERE session_id='combat-contact-session'");
            }
            if($case==='frame-stale')$this->database->execQuery("UPDATE synth_meta.context_snapshots SET received_at=received_at-INTERVAL '20 seconds' WHERE session_id='combat-contact-session'");
            if($case==='wrong-generation')$this->database->execQuery("UPDATE synth_meta.runtime_sessions SET active_generation=2 WHERE session_id='combat-contact-session'");
            if($case==='retired-session')$this->database->execQuery("UPDATE synth_meta.runtime_sessions SET state='halted' WHERE session_id='combat-contact-session'");
            $this->updatePayload();
            if(in_array($case,['wrong-generation','retired-session'],true)) {
                try {synth_combat_identification_reply($this->database,$this->event);$this->fail($case.' produced speech');}
                catch(SYNTHProtocolCancelled) {$this->addToAssertionCount(1);}
            } else $this->assertSame('I did not identify the recent creatures.',synth_combat_identification_reply($this->database,$this->event)['text'],$case);
        }
    }

    public function testValidatedVisibleIncomingContactDoesNotEnrollAnEnemyOrLeakToHistory(): void
    {
        $fixture=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-combat-contacts.json'),true,flags:JSON_THROW_ON_ERROR);
        $before=$fixture['payload']['audience'];
        $this->assertSame($fixture,synth_protocol_validate_event($fixture));
        $this->assertSame($before,$fixture['payload']['audience']);$this->assertCount(1,$before);
        $states=$this->payload['actor_states'];$original=json_encode($states);
        foreach(synth_protocol_without_combat_targets($states) as $state) {
            $this->assertArrayNotHasKey('combat_contacts',$state);$this->assertArrayNotHasKey('combat_target',$state);
        }
        foreach(synth_protocol_prompt_actor_states($states,$this->payload['player'],$this->payload['target']) as $state)
            $this->assertArrayNotHasKey('combat_contacts',$state);
        $this->assertSame($original,json_encode($states));
        $reply=synth_combat_identification_reply($this->database,$this->event);
        $this->assertSame('I can identify Mole Rat.',$reply['text']);
        $this->assertEquals($this->payload['target'],$reply['speaker']);
        $this->assertEquals($this->payload['player'],$reply['listener']);
        $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth.actor_identities WHERE full_form_id='
            .$this->database->escapeLiteral($states[0]['combat_contacts'][0]['actor']['form_id']))['n']);
    }

    public function testCurrentOwnTargetAndVisualContactOverrideNoHistoricalSpeciesGuess(): void
    {
        $owner=&$this->payload['actor_states'][0];
        $contact=$owner['combat_contacts'][0];
        $owner['in_combat']=true;$owner['combat_target']=['observation'=>'identified','actor'=>$contact['actor'],'race'=>$contact['race']];
        $owner['combat_contacts']=[];unset($owner);$this->updatePayload();
        $this->assertSame('I can identify Mole Rat.',synth_combat_identification_reply($this->database,$this->event)['text']);
        $messages=synth_append_combat_observer_context([['role'=>'assistant','content'=>'Synthetic old encounter: Radroach.']],
            $this->event,$this->payload['actor_states'],$this->payload['target'],$this->payload['player']);
        $this->assertStringContainsString('Mole Rat',end($messages)['content']);
        $this->assertStringNotContainsString('Radroach',end($messages)['content']);
        $owner=$this->payload['actor_states'][0];$owner['actor']['form_id']='0x00019FD9';
        $owner['combat_target']['actor']['display_name']='Radroach';
        // Other observers and the player's selected actor never substitute for the responder's observation.
        $this->payload['actor_states']=[$owner];$this->updatePayload();
        $this->assertSame('I have not identified the attackers.',synth_combat_identification_reply($this->database,$this->event)['text']);
    }

    public function testStaleMissingAmbiguousOrForeignFrameReturnsTruthfulUnknown(): void
    {
        $original=$this->payload;
        foreach(['missing','duplicate','foreign','blocked','wrong-target','unsafe-name'] as $case) {
            $this->payload=$original;
            if($case==='missing')$this->payload['actor_states']=[];
            if($case==='duplicate')$this->payload['actor_states'][]=$this->payload['actor_states'][0];
            if($case==='foreign')$this->payload['actor_states'][0]['combat_contacts'][0]['actor']['playthrough_id']='other-save';
            if($case==='blocked')$this->payload['actor_states'][0]['combat_contacts'][0]['line_of_sight']='blocked';
            if($case==='wrong-target')$this->payload['actor_states'][0]['combat_contacts'][0]['target']['origin_plugin']='Other.esm';
            if($case==='unsafe-name')$this->payload['actor_states'][0]['combat_contacts'][0]['actor']['display_name']='Mole Rat|command|Halt';
            $this->updatePayload();
            $this->assertSame('I have not identified the attackers.',synth_combat_identification_reply($this->database,$this->event)['text'],$case);
        }
        $this->payload=$original;$this->updatePayload();
        $this->database->execQuery("UPDATE synth_meta.context_snapshots SET received_at=received_at-INTERVAL '20 seconds' WHERE session_id='combat-contact-session'");
        $this->assertSame('I have not identified the attackers.',synth_combat_identification_reply($this->database,$this->event)['text']);
    }

    public function testMultipleVisibleContactsAreBoundedAndDisplayNamesAreDeduplicated(): void
    {
        $base=$this->payload['actor_states'][0]['combat_contacts'][0];$contacts=[];
        for($i=0;$i<8;$i++) {
            $contact=$base;$contact['actor']['form_id']=sprintf('0xFF0012%02X',$i);
            $contact['actor']['display_name']='Mole Rat '.($i+1);
            $contact['target']=$i%2===0?$this->payload['target']:$this->payload['player'];
            $contacts[]=$contact;
        }
        $this->payload['actor_states'][0]['combat_contacts']=$contacts;$this->updatePayload();
        $reply=synth_combat_identification_reply($this->database,$this->event);
        $this->assertStringContainsString('Mole Rat 1',$reply['text']);$this->assertStringContainsString('Mole Rat 8',$reply['text']);
        $this->assertSame(8,substr_count($reply['text'],'Mole Rat'));
        foreach($this->payload['actor_states'][0]['combat_contacts'] as &$contact)$contact['actor']['display_name']='Mole Rat';
        unset($contact);$this->updatePayload();
        $this->assertSame('I can identify Mole Rat.',synth_combat_identification_reply($this->database,$this->event)['text']);
    }

    public function testOnlyWholeQuestionsAndNegotiatedCanonicalPlayerInputsUseDirectReply(): void
    {
        foreach(['what are these creatures?!?!?!','WHAT ARE THESE THINGS?','what are we fighting','what is attacking us?!'] as $question)
            $this->assertTrue(synth_combat_identification_question($question));
        foreach(['"what are these creatures?"','Tell a story about what are these creatures','what are these things and follow me',
            'what are these creatures? wait here','Do you remember what are these things','Identify Mole Rats','What are these creatures.','what are these creatures <command>'] as $question)
            $this->assertFalse(synth_combat_identification_question($question),$question);
        foreach([['runtime_variant'=>'vr'],['protocol_version'=>1],['capabilities'=>['context.combat_contacts']],
            ['type'=>'trigger'],['payload'=>['text'=>'what are these things','control'=>['mode'=>'NARRATOR']]]] as $change)
            $this->assertNull(synth_combat_identification_reply($this->database,array_replace($this->event,$change)));
        $other=$this->event;$other['payload']['text']='what are these things';
        $this->assertNull(synth_combat_identification_reply($this->database,$other),'A caller cannot replace canonical input with a different question');
        $this->event['type']='input_audio';$this->event['payload']=['media'=>['schema'=>'synth.media.v1']];$this->persistEvent();
        $this->assertSame('I can identify Mole Rat.',synth_combat_identification_reply($this->database,$this->event,'what are these things?')['text']);
        $this->assertNull(synth_combat_identification_reply($this->database,$this->event,null));
    }

    public function testCancellationCannotProduceDeterministicSpeech(): void
    {
        $this->database->execQuery("UPDATE synth_meta.request_executions SET state='cancelled',error_code='turn_cancelled' WHERE request_id='combat-contact-input'");
        $this->expectException(SYNTHProtocolCancelled::class);
        synth_combat_identification_reply($this->database,$this->event);
    }

    public function testContactAckRequiresFullFlatContractAndOnlyAcceptedInit(): void
    {
        foreach(['flat','vr'] as $lane)foreach([1,2] as $version)foreach([true,false] as $all) {
            $event=$this->event;$event['type']='init';$event['runtime_variant']=$lane;$event['protocol_version']=$version;
            if(!$all)$event['capabilities']=array_values(array_diff($event['capabilities'],['dialogue.turn_ownership']));
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $lines=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim(synth_protocol_encode_ack('init_accepted'))));
            $this->assertSame($lane==='flat'&&$version===2&&$all,$lines[1]['payload']['combat_contacts']??false);
            $this->assertStringNotContainsString('combat_contacts',synth_protocol_encode_ack('context_accepted'));
        }
    }

    public function testInvalidContactFramesAndIncompleteInitOffersFailRuntimeValidation(): void
    {
        $root=dirname(__DIR__,2);
        $fixture=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/context-combat-contacts.json'),true,flags:JSON_THROW_ON_ERROR);
        $invalid=[];
        foreach(glob($root.'/protocol/v2/fixtures/invalid/*combat-contact*.json') as $file)
            $invalid[basename($file)]=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        foreach(['empty-dead-owner','empty-disabled-owner','empty-player-owner','foreign-player-target','self-contact','player-contact','duplicate-contact','too-many','extra-key','false-hostile','unknown-los','missing-turn-cap'] as $case) {
            $event=$fixture;$state=&$event['payload']['actor_states'][0];
            if(str_starts_with($case,'empty-'))$state['combat_contacts']=[];
            if($case==='empty-dead-owner')$state['alive']=false;
            if($case==='empty-disabled-owner')$state['disabled']=true;
            if($case==='empty-player-owner'){$state['actor']=$event['payload']['player'];$event['payload']['audience']=[];unset($event['payload']['target']);}
            if($case==='foreign-player-target')$state['combat_contacts'][0]['target']=array_replace($event['payload']['player'],['origin_plugin'=>'Other.esm']);
            if($case==='self-contact')$state['combat_contacts'][0]['actor']=$state['actor'];
            if($case==='player-contact')$state['combat_contacts'][0]['actor']=$event['payload']['player'];
            if($case==='duplicate-contact')$state['combat_contacts'][]=$state['combat_contacts'][0];
            if($case==='too-many')$state['combat_contacts']=array_fill(0,9,$state['combat_contacts'][0]);
            if($case==='extra-key')$state['combat_contacts'][0]['command']='Halt';
            if($case==='false-hostile')$state['combat_contacts'][0]['hostile']=false;
            if($case==='unknown-los')$state['combat_contacts'][0]['line_of_sight']='unknown';
            if($case==='missing-turn-cap')$event['capabilities']=array_values(array_diff($event['capabilities'],['dialogue.turn_ownership']));
            unset($state);$invalid[$case]=$event;
        }
        foreach($invalid as $name=>$event) {
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_event(json_decode('.var_export(json_encode($event),true).',true));echo "ACCEPTED";';
            $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
            $this->assertStringNotContainsString('ACCEPTED',$output,$name);
            $this->assertFalse(json_decode($output,true)['ok']??true,$name);
        }
    }

    public function testExactProductionHookEmitsCanonicalNpcSpeechPendingTtsHistoryAndCompletionWithoutModelOrActions(): void
    {
        $this->assertProductionIdentification('I can identify Mole Rat.');
    }

    public function testExactProductionHookUsesCurrentAwarenessWithoutAnIncomingTarget(): void
    {
        $this->event['capabilities']=array_values(array_diff($this->event['capabilities'],['context.combat_contacts']));
        $this->event['capabilities'][]='context.enemy_awareness';
        $owner=&$this->payload['actor_states'][0];$contact=$owner['combat_contacts'][0];unset($owner['combat_contacts']);
        $owner['enemy_awareness']=['current'=>[['actor'=>$contact['actor'],'race'=>$contact['race'],'distance'=>125,
            'hostile'=>true,'in_combat'=>false,'line_of_sight'=>'visible']],'recent'=>[]];
        unset($owner);$this->persistEvent();$this->updatePayload();
        $this->assertProductionIdentification('I can identify Mole Rat.');
    }

    public function testExactProductionHookKeepsRecentDeathAnObservationWithoutVictoryOrActions(): void
    {
        $this->event['capabilities']=array_values(array_diff($this->event['capabilities'],['context.combat_contacts']));
        $this->event['capabilities'][]='context.enemy_awareness';
        $this->event['payload']['text']='what did we just fight?';
        $owner=&$this->payload['actor_states'][0];$contact=$owner['combat_contacts'][0];unset($owner['combat_contacts']);
        $owner['enemy_awareness']=['current'=>[],'recent'=>[['actor'=>$contact['actor'],'race'=>$contact['race'],
            'observation'=>'seen_dead','age_ms'=>1000]]];
        unset($owner);$this->persistEvent();$this->updatePayload();
        $this->assertProductionIdentification('I recently saw Mole Rat dead.');
    }

    private function assertProductionIdentification(string $expected): void
    {
        $root=dirname(__DIR__,2);
        require_once $root.'/lib/data_functions.php';require_once $root.'/lib/chat_helper_functions.php';
        require_once $root.'/lib/auditing.php';
        $keys=['gameRequest','startTime','FORCED_STOP','PLAYER_NAME','FEATURES','TTSFUNCTION','TTS','HOOKS','DEBUG_DATA','TRACK',
            'SCRIPTLINE_LISTENER','SCRIPTLINE_EXPRESSION','SCRIPTLINE_ANIMATION','SYNTH_RESPONSE_FORMAT','SYNTH_DEFERRED_TTS',
            'PRESERVE_ASTERISKS_IN_CONTEXT','INLINE_NARRATION_MODE','TTS_NPC_ORIGINAL_VOICE','PATCH_OVERRIDE_VOICE','SYNTH_NATIVE_ACTOR_STATES',
            'SYNTH_CANONICAL_SPEECH_EMITTED','SYNTH_CANONICAL_SPEECH_BUFFERED','SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'];
        $saved=[];foreach($keys as $key)$saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];
        $cache='';$file=tempnam(sys_get_temp_dir(),'synth-combat-hook-');
        try {
            $question=$this->event['payload']['text'];
            $GLOBALS['gameRequest']=['inputtext',1000,1000,$question];
            $GLOBALS['startTime']=microtime(true);$GLOBALS['FORCED_STOP']=false;
            $GLOBALS['PLAYER_NAME']=$this->payload['player']['display_name'];$GLOBALS['FEATURES']=[];
            $GLOBALS['TTSFUNCTION']='xtts-fastapi';$GLOBALS['TTS']=['XTTSFASTAPI'=>['endpoint'=>'http://fixture.invalid:8020','voiceid'=>'fixture']];
            $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture';$GLOBALS['TTS_NPC_ORIGINAL_VOICE']='fixture';
            // A process-local modifier makes the real TTS plan nonportable, retaining a pending in-process job without a worker/provider call.
            $GLOBALS['HOOKS']['XTTS_TEXTMODIFIER']=[static fn($text)=>$text];
            $GLOBALS['DEBUG_DATA']=[];$GLOBALS['TRACK']=[];$GLOBALS['SYNTH_DEFERRED_TTS']=[];$GLOBALS['AUDIT_RUNID_REQUEST']='fixture';
            $GLOBALS['SCRIPTLINE_EXPRESSION']='';$GLOBALS['SCRIPTLINE_ANIMATION']='';
            $GLOBALS['SYNTH_RESPONSE_FORMAT']='json';$GLOBALS['SYNTH_RESPONSE_STREAMING']=true;
            $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED']=false;$GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED']=false;
            $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']='';$GLOBALS['SYNTH_PROTOCOL_RESPONSE_SEQUENCE']=0;
            $GLOBALS['INLINE_NARRATION_MODE']='disabled';$GLOBALS['PRESERVE_ASTERISKS_IN_CONTEXT']=false;
            $source=file_get_contents($root.'/main_synth_pipeline.php');
            $start=strpos($source,'$combatIdentification=synth_combat_identification_reply(');
            $end=strpos($source,'// One 60-second RPG window',$start);
            $this->assertIsInt($start);$this->assertIsInt($end);
            file_put_contents($file,"<?php\n".substr($source,$start,$end-$start));
            $db=$this->database;$transcript=null;$terminated=false;$path=$root.'/';
            $gameRequest=$GLOBALS['gameRequest'];
            // Execute the actual normal input-history insertion immediately preceding this production hook.
            $logStart=strpos($source,'/// LOG INTO DB. Will use this later.');
            $logCode=substr($source,$logStart,strpos($source,'// Check if this event has been disabled before either direct or model responses.',$logStart)-$logStart);
            $eventPeople='|'.$this->payload['target']['display_name'].'|';$authoritativePeople=$eventPeople;
            $turnPeopleSnapshotEventTypes=['inputtext'];$GLOBALS['CACHE_LOCATION']='Synthetic Sanctuary';$GLOBALS['CACHE_PARTY']='';
            eval($logCode);
            ob_start(static fn($chunk)=>'');
            try {include $file;$this->fail('The direct combat branch fell through to later provider/action processing.');}
            catch(SYNTHTestRequestTerminated) {$terminated=true;}
            finally {ob_end_clean();}
            $this->assertTrue($terminated);
            $wire=$GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'];
            $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),explode("\n",trim($wire)));
            $this->assertSame(['response_start','dialogue','dialogue','status','response_end'],array_column($lines,'type'));
            $this->assertEquals($this->payload['player'],$lines[1]['payload']['speaker']);
            $this->assertStringContainsString($question,$lines[1]['payload']['text']);
            $dialogue=$lines[2]['payload'];
            $this->assertEquals($this->payload['target'],$dialogue['speaker']);
            $this->assertSame($expected,$dialogue['text']);
            $this->assertSame('pending',$dialogue['speech']['status']);$cache=$dialogue['speech']['cache_key'];
            $this->assertSame('combat_identification_complete',$lines[3]['payload']['code']);
            $this->assertSame('complete',$lines[4]['payload']['status']);
            foreach($lines as $line) {$this->assertSame($this->event['request_id'],$line['request_id']);$this->assertSame(7,$line['context_sequence']);}
            $this->assertCount(1,$GLOBALS['SYNTH_DEFERRED_TTS']);
            $this->assertSame($dialogue['text'],$GLOBALS['SYNTH_DEFERRED_TTS'][0]['text']);
            $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
            $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.rechat_continuations')['n']);
            $this->assertSame('complete',$this->database->fetchOne("SELECT state FROM synth_meta.request_executions WHERE request_id='combat-contact-input'")['state']);
            $this->assertSame('2',$this->database->fetchOne("SELECT count(*) AS n FROM synth.eventlog WHERE type IN ('prechat','chat') AND data LIKE "
                .$this->database->escapeLiteral('%'.$expected.'%'))['n']);
            $this->assertSame('1',$this->database->fetchOne("SELECT count(*) AS n FROM synth.eventlog WHERE type='inputtext' AND data LIKE "
                .$this->database->escapeLiteral('%'.$question.'%'))['n']);
        } finally {
            unlink($file);
            if($cache!=='') { $path=synth_npc_tts_status_dir($root).'/'.$cache.'.json';if(is_file($path))unlink($path); }
            foreach($saved as $key=>[$exists,$value]){unset($GLOBALS[$key]);if($exists)$GLOBALS[$key]=$value;}
        }
    }

    public function testDisabledInputCannotBypassItsExistingGateForDirectIdentification(): void
    {
        require_once dirname(__DIR__,2).'/lib/auditing.php';
        $source=file_get_contents(dirname(__DIR__,2).'/main_synth_pipeline.php');
        $start=strpos($source,'// Check if this event has been disabled before either direct or model responses.');
        $end=strpos($source,'// One 60-second RPG window',$start);
        $this->assertIsInt($start);$this->assertIsInt($end);
        $saved=$GLOBALS['PROMPTS'] ?? null;
        $file=tempnam(sys_get_temp_dir(),'synth-combat-disabled-');
        file_put_contents($file,"<?php\n".substr($source,$start,$end-$start));
        $gameRequest=['inputtext',1000,1000,'what are these creatures?!?!?!'];$db=$this->database;
        $GLOBALS['PROMPTS']['inputtext']['extra']['dontuse']=true;$GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']='';
        try {
            try {include $file;$this->fail('Disabled input reached direct identification.');}
            catch(SYNTHTestRequestTerminated) {$this->assertSame('',$GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']);}
        } finally {unlink($file);if($saved===null)unset($GLOBALS['PROMPTS']);else$GLOBALS['PROMPTS']=$saved;}
        $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
    }
}
