<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . "/../../lib/logger.php");

@define("MAXIMUM_SENTENCE_SIZE", 125);
@define("MINIMUM_SENTENCE_SIZE", 75);

require_once(__DIR__ . "/../../lib/chat_helper_functions.php");
require_once(__DIR__ . "/../../lib/power_awareness.php");
require_once(__DIR__ . "/../../lib/core/activity_status.php");

final class ResponseRuntimeRegressionTest extends TestCase
{
    public function testSpeechStatusPollingDoesNotPromoteOrPublishCachedAudio(): void
    {
        $root=sys_get_temp_dir().'/synth-status-read-'.bin2hex(random_bytes(8));
        mkdir($root.'/soundcache',0700,true);
        $key=md5('read-only-status');
        $wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0";
        $cache=$root.'/soundcache/'.$key.'.wav';
        $status=synth_npc_tts_status_dir($root).'/'.$key.'.json';
        $object=$root.'/soundcache/media/'.hash('sha256',$wav);
        $claim=$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
        unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
        try {
            file_put_contents($cache,$wav);
            $this->assertSame('unknown',synth_read_npc_tts_status($key,$root)['status']);
            foreach (['pending','failed','ready'] as $state) {
                $this->assertTrue(synth_write_npc_tts_status($key,$state,[],$root));
                $before=file_get_contents($status);
                $read=synth_read_npc_tts_status($key,$root);
                $this->assertSame($state==='ready'?'failed':$state,$read['status']);
                $this->assertArrayNotHasKey('media',$read);
                $this->assertSame($before,file_get_contents($status));
                $this->assertDirectoryDoesNotExist($root.'/soundcache/media');
            }
            synth_media_publish_bytes($root,$wav,'audio/wav');
            $metadata=file_get_contents($object.'.json');
            $read=synth_read_npc_tts_status($key,$root);
            $this->assertSame('ready',$read['status']);
            $this->assertSame('media/'.hash('sha256',$wav),$read['media']['media_id']);
            $this->assertSame($metadata,file_get_contents($object.'.json'));
            $this->assertSame($wav,file_get_contents($object));
            $this->assertTrue(synth_write_npc_tts_status($key,'pending',['job_id'=>2],$root));
            $before=file_get_contents($status);
            $read=synth_read_npc_tts_status($key,$root);
            $this->assertSame('pending',$read['status']);
            require_once dirname(__DIR__,2).'/lib/synth_tts_worker.php';
            $db=new class {
                public ?array $media=null;
                public function escapeLiteral($value): string { return "'".$value."'"; }
                public function fetchOne($sql): ?array {
                    return str_contains($sql,'synth_meta.media_objects') ? $this->media : ['state'=>'running','within_deadline'=>'t'];
                }
            };
            $this->assertSame('pending',synth_tts_resolve_job_status($db,$read,$root)['status']);
            $db->media=['state'=>'ready','content_type'=>'audio/wav','byte_length'=>strlen($wav),'sha256'=>hash('sha256',$wav)];
            $resolved=synth_tts_resolve_job_status($db,$read,$root);
            $this->assertSame('ready',$resolved['status']);
            $this->assertArrayNotHasKey('_job_id',$resolved);
            $this->assertSame('media/'.hash('sha256',$wav),$resolved['media']['media_id']);
            $this->assertSame($before,file_get_contents($status));
            $this->assertSame($metadata,file_get_contents($object.'.json'));
        } finally {
            if ($claim!==null) $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$claim;
            foreach ([$cache,$status,$object,$object.'.json'] as $file) if(is_file($file)) unlink($file);
            foreach (['data/tmp/npc_tts_status','data/tmp','data','soundcache/media','soundcache',''] as $dir) {
                $path=$root.($dir===''?'':'/'.$dir);
                if(is_dir($path)) rmdir($path);
            }
        }
    }

    public function testCancelledSpeechRegistrationAndStatusCannotReplaceCurrentReadiness(): void
    {
        $root=sys_get_temp_dir().'/synth-status-owner-'.bin2hex(random_bytes(8));
        mkdir($root.'/soundcache',0700,true);
        $key=md5('owned-status');
        $wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0";
        $cache=$root.'/soundcache/'.$key.'.wav';
        $status=synth_npc_tts_status_dir($root).'/'.$key.'.json';
        $object=$root.'/soundcache/media/'.hash('sha256',$wav);
        $keys=['SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_REQUEST_EVENT','db'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        foreach($keys as $name) unset($GLOBALS[$name]);
        try {
            file_put_contents($cache,$wav);
            $this->assertTrue(synth_write_npc_tts_status($key,'pending',[],$root));
            $before=file_get_contents($status);
            $db=$GLOBALS['db']=new class {
                public bool $cancelled=false;
                public bool $cancelAtLock=true;
                public int $writes=0;
                public function escapeLiteral($value): string { return "'".$value."'"; }
                public function fetchOne($sql): array {
                    return ['state'=>$this->cancelled?'cancelled':'complete','runtime_state'=>'active','active_generation'=>1];
                }
                public function execQuery($sql): bool { $this->writes++; return true; }
                public function withNativeRequestLock($publish,$timeout): mixed {
                    if($this->cancelAtLock) $this->cancelled=true;
                    return $publish();
                }
            };
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']=['protocol_version'=>2,'session_id'=>'status-fixture',
                'request_id'=>'status-request','generation'=>1,'context_sequence'=>1,'capabilities'=>[]];
            try {
                synth_register_npc_tts_media($key,$db,[],$root);
                $this->fail('Late cancellation registered speech');
            } catch (SYNTHProtocolCancelled) {
                $this->assertSame(0,$db->writes);
                $this->assertSame($before,file_get_contents($status));
            }
            $db->cancelled=false;
            $this->assertFalse(synth_write_npc_tts_status($key,'failed',[],$root));
            $this->assertSame($before,file_get_contents($status));
            $this->assertSame([],glob($status.'.*.tmp'));
            $db->cancelled=false;
            $db->cancelAtLock=false;
            $this->assertNotNull(synth_register_npc_tts_media($key,$db,[],$root));
            $this->assertSame(1,$db->writes);
            $this->assertSame('ready',synth_read_npc_tts_status($key,$root)['status']);
            $before=file_get_contents($status);
            $db->cancelled=true;
            $this->assertFalse(synth_write_npc_tts_status($key,'failed',[],$root));
            $this->assertSame($before,file_get_contents($status));
        } finally {
            foreach($keys as $name) unset($GLOBALS[$name]);
            foreach($saved as $name=>$value) $GLOBALS[$name]=$value;
            foreach ([$cache,$status,$object,$object.'.json'] as $file) if(is_file($file)) unlink($file);
            foreach (['data/tmp/npc_tts_status','data/tmp','data','soundcache/media','soundcache',''] as $dir) {
                $path=$root.($dir===''?'':'/'.$dir);
                if(is_dir($path)) rmdir($path);
            }
        }
    }

    public function testInworldChunksKeepOneFormatAndRejectCorruptAudio(): void
    {
        require_once dirname(__DIR__,2).'/tts/tts-inworld.php';
        $wav=static fn(int $rate): string=>'RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,$rate,$rate*2,2,16).'data'.pack('V',2)."\0\0";
        $chunk=static fn(string $audio): string=>'data: '.json_encode(['result'=>['audioContent'=>base64_encode($audio)]])."\n";
        $decoded=synth_inworld_decode_audio_chunks($chunk($wav(24000)).$chunk($wav(24000)));
        $this->assertSame("\0\0\0\0",$decoded['audio']);
        $this->assertSame(['format'=>'s16le','sample_rate'=>24000,'channels'=>1],$decoded['format']);
        foreach ([$chunk($wav(22050)).$chunk($wav(24000)), $chunk(substr($wav(22050),0,-1)),
            $chunk("\0"), 'data: {"result":{"audioContent":"!invalid!"}}',
            'data: {"result":{"audioContent":[]}}', 'data: {"error":"fixture"}'] as $invalid) {
            $this->assertFalse(synth_inworld_decode_audio_chunks($invalid));
        }
    }

    public function testRawSpeechConversionRejectsUntrustedFormatsBeforeStartingProcess(): void
    {
        require_once dirname(__DIR__,2).'/lib/speech_cache.php';
        $valid=['format'=>'s16le','sample_rate'=>22050,'channels'=>1];
        foreach ([['format'=>'concat'],['sample_rate'=>'22050'],['sample_rate'=>0],
            ['sample_rate'=>192001],['channels'=>0],['channels'=>3],['extra'=>'-i file']] as $override) {
            try {
                synth_transcode_speech_cache("\0\0",'/invalid-target','anull',pcmInput:array_replace($valid,$override));
                $this->fail('Invalid PCM format reached conversion');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('Invalid speech PCM format',$error->getMessage());
            }
        }
        try {
            synth_transcode_speech_cache("\0",'/invalid-target','anull',pcmInput:$valid);
            $this->fail('Truncated PCM frame reached conversion');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Invalid speech PCM format',$error->getMessage());
        }
    }

    public function testDirectSpeechPublicationPreservesBytesAndRejectsCancelledOwner(): void
    {
        require_once dirname(__DIR__,2).'/lib/speech_cache.php';
        $root=sys_get_temp_dir().'/synth-direct-wav-'.bin2hex(random_bytes(8));
        mkdir($root.'/soundcache',0700,true);
        $target=$root.'/soundcache/'.md5('direct-wav').'.wav';
        $keys=['SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_REQUEST_EVENT','db'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        foreach($keys as $key) unset($GLOBALS[$key]);
        $wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0";
        try {
            $this->assertTrue(synth_publish_speech_cache($wav,$target));
            $this->assertSame($wav,file_get_contents($target));
            foreach (['', 'not audio', substr($wav,0,-1)] as $invalid) {
                $this->assertFalse(synth_publish_speech_cache($invalid,$target));
                $this->assertSame($wav,file_get_contents($target));
            }
            $GLOBALS['db']=new class {
                public bool $cancelled=false;
                public function escapeLiteral($value): string { return "'".$value."'"; }
                public function fetchOne($sql): array {
                    return ['state'=>$this->cancelled?'cancelled':'complete','runtime_state'=>'active','active_generation'=>1];
                }
                public function withNativeRequestLock($publish,$timeout): mixed {
                    $this->cancelled=true;
                    return $publish();
                }
            };
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']=['protocol_version'=>2,'session_id'=>'direct-fixture',
                'request_id'=>'direct-request','generation'=>1,'capabilities'=>[]];
            try {
                synth_publish_speech_cache(substr($wav,0,-2)."\1\0",$target);
                $this->fail('Cancelled owner replaced published audio');
            } catch (SYNTHProtocolCancelled) {
                $this->assertTrue($GLOBALS['db']->cancelled);
                $this->assertSame($wav,file_get_contents($target));
            }
            $this->assertSame([],glob($root.'/data/tmp/npc_tts_jobs/.speech-output-*'));
        } finally {
            foreach($keys as $key) unset($GLOBALS[$key]);
            foreach($saved as $key=>$value) $GLOBALS[$key]=$value;
            if(is_file($target)) unlink($target);
            foreach(['data/tmp/npc_tts_jobs','data/tmp','data','soundcache',''] as $directory) {
                $path=$root.($directory===''?'':'/'.$directory);
                if(is_dir($path)) rmdir($path);
            }
        }
    }

    public function testFailedSpeechConversionPreservesPublishedCacheAndRemovesPrivateFiles(): void
    {
        require_once dirname(__DIR__,2).'/lib/speech_cache.php';
        $root=sys_get_temp_dir().'/synth-transcode-'.bin2hex(random_bytes(8));
        mkdir($root.'/soundcache',0700,true);
        $target=$root.'/soundcache/'.md5('failed-conversion').'.wav';
        file_put_contents($target,'existing-cache-must-survive');
        $hadClaim=array_key_exists('SYNTH_PROTOCOL_REQUEST_CLAIMED',$GLOBALS);
        $claim=$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
        $ffmpegPath=getenv('FFMPEG_PATH');
        unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
        try {
            // PHP is deliberately not an audio converter: the real child process must exit unsuccessfully.
            $this->assertFalse(synth_transcode_speech_cache('not audio',$target,'anull',PHP_BINARY,1000));
            putenv('FFMPEG_PATH='.PHP_BINARY);
            $this->assertFalse(synth_transcode_speech_cache('not audio',$target,'anull',timeoutMs:1000));
            $this->assertSame('existing-cache-must-survive',file_get_contents($target));
            $this->assertSame([],glob($root.'/data/tmp/npc_tts_jobs/.transcode-*'));
            foreach ([$root.'/soundcache/not-a-cache.wav',$root.'/soundcache/../escape.wav'] as $invalid) {
                try {
                    synth_transcode_speech_cache('bytes',$invalid,'anull',PHP_BINARY);
                    $this->fail('Invalid target was accepted');
                } catch (InvalidArgumentException $error) {
                    $this->assertSame('Invalid speech conversion request',$error->getMessage());
                }
            }
        } finally {
            putenv($ffmpegPath===false?'FFMPEG_PATH':'FFMPEG_PATH='.$ffmpegPath);
            if ($hadClaim) $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$claim;
            else unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
            unlink($target);
            foreach (['data/tmp/npc_tts_jobs','data/tmp','data','soundcache',''] as $directory) rmdir($root.($directory===''?'':'/'.$directory));
        }
    }

    public function testNativeReservedNpcNamesKeepTheirIdentityAndBufferedSpeechRole(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        require_once dirname(__DIR__,2).'/lib/core/narrator.class.php';
        $keys=['SYNTH_REQUEST_EVENT','SYNTH_SPEECH_ROLE','db','SYNTH_NAME','TTSFUNCTION',
            'SYNTH_JSON_RESPONSE_LINES','SYNTH_RESPONSE_STREAMING','SYNTH_PROTOCOL_REQUEST_CLAIMED',
            'SYNTH_PROTOCOL_RESPONSE_STARTED','SYNTH_PROTOCOL_RESPONSE_BODY','SYNTH_PROTOCOL_RESPONSE_SEQUENCE'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        foreach ($keys as $key) unset($GLOBALS[$key]);
        $db=new class {
            public ?array $payload;
            public function escapeLiteral(string $value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchOne(string $sql): ?array {
                if ($this->payload===null) return null;
                if (str_contains($sql,'FROM synth_meta.action_intents')) {
                    $actor=$this->payload['audience'][0];
                    return ['full_form_id'=>$actor['form_id'],'origin_plugin'=>$actor['origin_plugin'],
                        'playthrough_id'=>$actor['playthrough_id']];
                }
                return ['context_payload'=>$this->payload];
            }
        };
        $fixture=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload'];
        $GLOBALS['db']=$db; $GLOBALS['TTSFUNCTION']='none';
        try {
            foreach (['input_text','input_audio','trigger','action_result'] as $type) {
                foreach (['Player','Sole Survivor','The Narrator'] as $name) {
                    $db->payload=$fixture;
                    $db->payload['target']['display_name']=$name;
                    $actor=$db->payload['target'];
                    if ($type==='action_result') {
                        $actor['form_id']='0x00012345';
                        $db->payload['audience']=[$actor];
                    }
                    $event=['protocol_version'=>2,'type'=>$type,'session_id'=>'role:fixture','turn_id'=>'role:turn',
                        'request_id'=>'role:request','runtime_variant'=>'flat','generation'=>7,'context_sequence'=>7,
                        'payload'=>['result'=>['action_id'=>'role:action','idempotency_key'=>'role:key']]];
                    $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
                    $GLOBALS['SYNTH_NAME']=$name;
                    $this->assertSame('npc',synth_protocol_current_speech_role());
                    $this->assertFalse(synth_protocol_response_is_narrator());
                    $this->assertSame($actor,synth_protocol_resolve_speaker_identity($db,$name));
                    $this->assertSame($actor,synth_protocol_response_speaker($name));
                    $this->assertSame($name,synthGetPromptCharacterName());
                    $GLOBALS['SYNTH_JSON_RESPONSE_LINES']=[];
                    synth_buffer_response_line($name,'say','NPC reply',['listener'=>'__player_text_only','speech_role'=>'player']);
                    synth_buffer_response_line('Player','say','Player echo',['listener'=>'__player_text_only'],'player');
                    $GLOBALS['SYNTH_SPEECH_ROLE']='narrator';
                    synth_buffer_response_line('The Narrator','say','Narration');
                    unset($GLOBALS['SYNTH_SPEECH_ROLE']);
                    $lines=$GLOBALS['SYNTH_JSON_RESPONSE_LINES'];
                    $this->assertSame(['npc','player','narrator'],array_column($lines,'speech_role'));
                    $this->assertArrayNotHasKey('display_name',$lines[0]);
                    $this->assertSame($db->payload['player'],synth_protocol_response_speaker('Player',$lines[1]['speech_role']));
                    $this->assertSame('narrator',synth_protocol_response_speaker('The Narrator',$lines[2]['speech_role'])['kind']);
                    // Exercise buffered encoding after the producer's temporary role was restored.
                    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED']=true;
                    $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']='';
                    ob_start(static fn(string $output): string=>'');
                    try { synth_emit_json_response_envelope($lines); } finally { ob_end_clean(); }
                    $wire=array_map(static fn(string $line): array=>json_decode($line,true),
                        explode("\n",trim($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'])));
                    $this->assertCount(3,$wire);
                    $this->assertSame($actor,$wire[0]['payload']['speaker']);
                    $this->assertSame($db->payload['player'],$wire[1]['payload']['speaker']);
                    $this->assertSame('narrator',$wire[2]['payload']['speaker']['kind']);
                }
            }
            $db->payload=null;
            foreach (['npc','player','narrator'] as $role) $this->assertNull(synth_protocol_response_speaker('The Narrator',$role));
            unset($GLOBALS['SYNTH_REQUEST_EVENT']);
            $this->assertSame('player',synth_protocol_current_speech_role('Player'));
            $this->assertSame('narrator',synth_protocol_current_speech_role('The Narrator'));
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key]; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testQueuedSpeechRoleControlsNpcFallbackAndRestoresCallerOnFailure(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_tts_plan.php';
        $keys=array_merge(SYNTH_TTS_PLAN_GLOBALS,['SYNTH_REQUEST_EVENT']);
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        foreach ($keys as $key) unset($GLOBALS[$key]);
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'type'=>'input_text']];
            $GLOBALS['SYNTH_NAME']='The Narrator'; $GLOBALS['TTSFUNCTION']='none';
            $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture-primary';
            $GLOBALS['TTS_NPC_ORIGINAL_VOICE']='fixture-primary';
            $GLOBALS['TTS_NPC_FALLBACK_VOICE']='fixture-fallback';
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['npc_name'=>'The Narrator','voiceid'=>'fixture-primary','race'=>'Human'];
            $this->assertTrue(canRetryNpcTtsWithFallback());
            $plan=synth_capture_tts_plan(dirname(__DIR__,2),'The Narrator','hello','default');
            $plan=json_decode(json_encode($plan,JSON_THROW_ON_ERROR),true,flags:JSON_THROW_ON_ERROR);
            foreach (['player','narrator'] as $role) {
                $GLOBALS['SYNTH_SPEECH_ROLE']=$role;
                $this->assertFalse(canRetryNpcTtsWithFallback());
                $this->assertSame('',synthTtsPronunciationCurrentSpeakerScope()['npc_name']);
                try {
                    synth_with_tts_plan($plan,$plan['cache_seed'],$plan['speaker'],dirname(__DIR__,2),function (): void {
                        $this->assertSame('npc',synth_protocol_current_speech_role());
                        $this->assertTrue(canRetryNpcTtsWithFallback());
                        $this->assertSame('The Narrator',synthTtsPronunciationCurrentSpeakerScope()['npc_name']);
                        throw new RuntimeException('role-fixture-failure');
                    });
                    $this->fail('Fixture must throw');
                } catch (RuntimeException $error) { $this->assertSame('role-fixture-failure',$error->getMessage()); }
                $this->assertSame($role,$GLOBALS['SYNTH_SPEECH_ROLE']);
            }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key]; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testOwnedRoleNeedsPreparationButCandidateLabelsRemainUnchanged(): void
    {
        require_once dirname(__DIR__,2).'/lib/core/narrator.class.php';
        require_once dirname(__DIR__,2).'/lib/compact_context_history.php';
        require_once dirname(__DIR__,2).'/lib/narrator_actions.php';
        require_once dirname(__DIR__,2).'/lib/core/action_catalog.php';
        $keys=['SYNTH_REQUEST_EVENT','db','SYNTH_NAME','NARRATOR_ROLEPLAY_NAME','COMPACT_NPC_CONTEXT_HISTORY'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'type'=>'trigger','payload'=>['kind'=>'rechat'],
            'capabilities'=>['dialogue.turn_ownership','dialogue.rechat.ownership']]];
        $GLOBALS['SYNTH_NAME']='The Narrator'; $GLOBALS['NARRATOR_ROLEPLAY_NAME']='Mercy';
        $GLOBALS['COMPACT_NPC_CONTEXT_HISTORY']=true;
        $GLOBALS['db']=new class {
            public function withNativeRequestLock(callable $operation, int $timeout): never
            {
                throw new LogicException('role_preparation_required');
            }
        };
        try {
            $this->assertSame('Mercy',synthNormalizeNarratorRoleplayActorName('Mercy'));
            $this->assertSame('The Narrator: quoted NPC data',synthRenderNarratorContextText('The Narrator: quoted NPC data'));
            foreach (['synthGetPromptCharacterName','synthNarratorActionRequestIsActive','synthActionCatalogIsNarratorMode','synthShouldCompactNpcContextHistory'] as $reader) {
                try { $reader(); $this->fail('A current response role was inferred before preparation'); }
                catch (LogicException $error) { $this->assertSame('role_preparation_required',$error->getMessage()); }
            }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key]; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testRechatHistoryRequiresTheNativeOwnershipLock(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_history.php';
        $db=new class {
            public function withNativeRequestLock(callable $operation, int $timeout): never
            {
                throw new LogicException('history_owner_required');
            }
        };
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('history_owner_required');
        synth_rechat_history($db,[]);
    }

    public function testOriginInputSeparatesCanonicalTextFromServerTranscription(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_dialogue_policy.php';
        $typed=['type'=>'input_text','payload'=>['text'=>"  Hello 世界\n"]];
        $audio=['type'=>'input_audio','payload'=>['text'=>'untrusted payload text']];
        $this->assertSame("  Hello 世界\n",synth_dialogue_origin_input($typed,'ignored transcript'));
        $this->assertSame('Server transcript',synth_dialogue_origin_input($audio,'Server transcript'));
        $this->assertNull(synth_dialogue_origin_input(['type'=>'trigger'],'not player input'));
        $this->assertSame(str_repeat('界',4096),synth_dialogue_origin_input(
            ['type'=>'input_text','payload'=>['text'=>str_repeat('界',4096)]]));
        foreach ([null,'','   ',"bad\xff", "bad\0text",str_repeat('a',4097),str_repeat('界',1366)] as $invalid) {
            try {
                synth_dialogue_origin_input($audio,$invalid);
                $this->fail('Invalid or client-supplied audio transcript was accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('dialogue_origin_input_invalid',$error->getMessage());
            }
        }
        foreach ([null,'',"bad\xff","bad\0text",str_repeat('界',4097)] as $invalid) {
            try {
                synth_dialogue_origin_input(['type'=>'input_text','payload'=>['text'=>$invalid]]);
                $this->fail('Invalid canonical player text was accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('dialogue_origin_input_invalid',$error->getMessage());
            }
        }
    }

    public function testRechatProfileLoadingRequiresTheNativeOwnershipLock(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_profile.php';
        $db=new class {
            public function withNativeRequestLock(callable $operation, int $timeout): never
            {
                throw new LogicException('native_owner_required');
            }
        };
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('native_owner_required');
        synth_rechat_load_profile($db,[]);
    }

    public function testOriginPolicyCapturesPrivateModeAndOnlyServerControls(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_dialogue_policy.php';
        $settings=['RECHAT_H'=>0,'RECHAT_P'=>100,'RECHAT_MODE'=>'group','RECHAT_ALLOW_ACTIONS'=>'false',
            'RANDOM_NARATION'=>false,'RANDOM_NARATION_CHANCE'=>15,'RANDOM_NARRATION_COOLDOWN'=>2,
            'source_profile_id'=>123,'PROVIDER_KEY'=>'not-a-real-key'];
        foreach (['STANDARD'=>false,'WHISPER'=>true,'CLOSE'=>true,'SHOUT'=>false] as $mode=>$private) {
            $policy=synth_dialogue_origin_policy($settings,$mode);
            $this->assertSame($private,$policy['private']);
            $this->assertSame(0,$policy['rounds']);
            $this->assertFalse($policy['allow_actions']);
            $this->assertCount(8,$policy);
            $this->assertArrayNotHasKey('source_profile_id',$policy);
            $this->assertArrayNotHasKey('PROVIDER_KEY',$policy);
        }
        $this->expectException(InvalidArgumentException::class);
        synth_dialogue_origin_policy($settings,'invented-mode');
    }

    public function testOwnedRechatCannotResolveReservedLabelsWithoutPreparation(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        $savedEvent=$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $savedDb=$GLOBALS['db'] ?? null;
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'type'=>'trigger',
            'payload'=>['kind'=>'rechat'],'capabilities'=>['dialogue.turn_ownership','dialogue.rechat.ownership']]];
        $GLOBALS['db']=new class {
            public function withNativeRequestLock(callable $operation, int $timeout): never
            {
                throw new LogicException('preparation_required');
            }
        };
        try {
            foreach (['The Narrator','Player','Sole Survivor','Settler'] as $speaker) {
                try {
                    synth_protocol_response_speaker($speaker);
                    $this->fail('Owned rechat resolved a label without checking its prepared owner');
                } catch (LogicException $error) {
                    $this->assertSame('preparation_required',$error->getMessage());
                }
            }
        } finally {
            if ($savedEvent===null) unset($GLOBALS['SYNTH_REQUEST_EVENT']); else $GLOBALS['SYNTH_REQUEST_EVENT']=$savedEvent;
            if ($savedDb===null) unset($GLOBALS['db']); else $GLOBALS['db']=$savedDb;
        }
    }

    public function testQuestReactionRequiresObservedTrackedObjectiveChange(): void
    {
        require_once dirname(__DIR__,2).'/lib/quest_reaction.php';
        $before=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/quest-tracking.json'),true)['payload'];
        $after=$before;
        $this->assertSame([],synthQuestReactionChanges($before,$after));
        $after['active_quests'][0]['current_stage']++;
        $this->assertSame([],synthQuestReactionChanges($before,$after));
        $after['active_quests'][0]['objectives']=['Return to the settler'];
        $this->assertCount(1,synthQuestReactionChanges($before,$after));
        $after['active_quests'][0]['objectives_observation']='partial';
        $this->assertSame([],synthQuestReactionChanges($before,$after));
        unset($before['active_quests'][0]['objectives_observation']);
        $after['active_quests'][0]['objectives_observation']='complete';
        $this->assertSame([],synthQuestReactionChanges($before,$after));
        $before=$after;$after['active_quests'][0]['tracked']=false;
        $this->assertSame([],synthQuestReactionChanges($before,$after));
        $this->assertCount(1,synthQuestReactionChanges($after,$before));
        unset($after['active_quests'][0]['tracked']);
        $this->assertSame([],synthQuestReactionChanges($after,$before));
        $after['active_quests']=[];
        $this->assertCount(1,synthQuestReactionChanges($after,$before));
        foreach (['partial','cached','unavailable'] as $quality) {
            $after['active_quests_observation']=$quality;
            $this->assertSame([],synthQuestReactionChanges($after,$before));
        }
        $after=$before;$after['active_quests'][0]['active_objectives']=0;
        $this->assertSame([],synthQuestReactionChanges($before,$after));
        $after=$before;$after['active_quests'][0]['origin_plugin']='FALLOUT4.ESM';
        $this->assertSame([],synthQuestReactionChanges($before,$after));
    }

    public function testRpgCooldownUsesExactNativePlayerOwnerNotNpcOrTurn(): void
    {
        require_once dirname(__DIR__,2).'/lib/rpg_comment.php';
        $db = new class {
            public ?array $payload;
            public string $query = '';
            public function escapeLiteral(string $value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchOne(string $sql): ?array {
                $this->query = $sql;
                return $this->payload === null ? null : ['context_payload'=>$this->payload];
            }
        };
        $db->payload = json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload'];
        $event = ['protocol_version'=>2,'session_id'=>'rpg:one','generation'=>2,'context_sequence'=>7];
        $key = synthRpgCommentCooldownKey($db,$event);
        $this->assertStringStartsWith('SYNTH_RPG_V2:',$key);
        $this->assertStringContainsString('c.snapshot_sequence=7',$db->query);
        $this->assertStringContainsString("r.state='active'",$db->query);
        $this->assertStringContainsString('r.active_generation=c.generation',$db->query);
        $this->assertSame($key,synthRpgCommentCooldownKey($db,$event+['turn_id'=>'other','payload'=>['kind'=>'quest_updated']]));
        $this->assertSame($key,synthRpgCommentCooldownKey($db,array_replace($event,['context_sequence'=>8])));
        unset($db->payload['target']);
        $db->payload['player']['display_name']='Different display name';
        $db->payload['player']['origin_plugin']=strtoupper($db->payload['player']['origin_plugin']);
        $db->payload['player']['form_id']=strtoupper($db->payload['player']['form_id']);
        $this->assertSame($key,synthRpgCommentCooldownKey($db,$event));
        $this->assertNotSame($key,synthRpgCommentCooldownKey($db,array_replace($event,['session_id'=>'rpg:two'])));
        $this->assertNotSame($key,synthRpgCommentCooldownKey($db,array_replace($event,['generation'=>3])));
        foreach (['form_id'=>'0x00000015','origin_plugin'=>'Other.esm','playthrough_id'=>'other-playthrough'] as $field=>$value) {
            $previous=$db->payload;
            $db->payload['player'][$field]=$value;
            if ($field==='playthrough_id') $db->payload['playthrough_id']=$value;
            $this->assertNotSame($key,synthRpgCommentCooldownKey($db,$event));
            $db->payload=$previous;
        }
        $this->assertSame('RPG_COMMENT_LAST_TIMESTAMP',synthRpgCommentCooldownKey($db,[]));
        $this->assertSame('RPG_COMMENT_LAST_TIMESTAMP',synthRpgCommentCooldownKey($db,['protocol_version'=>1]));
        $db->payload=null;
        $this->expectExceptionMessage('rpg_cooldown_context_required');
        synthRpgCommentCooldownKey($db,$event);
    }

    public function testRpgCooldownDatabaseFailureNeverAllowsAReaction(): void
    {
        require_once dirname(__DIR__,2).'/lib/rpg_comment.php';
        $db=new class {
            public function execQuery(string $sql,array $params): bool { return false; }
        };
        $this->expectExceptionMessage('rpg_cooldown_claim_failed');
        synthClaimRpgCommentCooldown($db,[]);
    }

    public function testNativeQuestAndWorldReadersNeverUseGlobalLatestRows(): void
    {
        require_once dirname(__DIR__,2).'/lib/data_functions.php';
        $previousEvent = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $db = new class {
            public array $payload;
            public function escapeLiteral(string $value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchOne(string $sql): ?array {
                if (str_contains($sql,'general_settings') || str_contains($sql,'conf_opts')) return null;
                if (!str_contains($sql,'context_snapshots')) throw new LogicException('Global query forbidden');
                return str_contains($sql,"c.session_id='session:one'") && str_contains($sql,'c.generation=2') && str_contains($sql,'c.snapshot_sequence=7')
                    ? ['context_payload'=>json_encode($this->payload)] : null;
            }
            public function fetchAll(string $sql): never { throw new LogicException('Global latest rows forbidden'); }
        };
        $db->payload = json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload'];
        $GLOBALS['db'] = $db;
        $event = ['protocol_version'=>2,'session_id'=>'session:one','generation'=>2,'context_sequence'=>7];
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>$event];
            $this->assertStringContainsString('When Freedom Calls',DataGetCurrentTask());
            $this->assertStringContainsString('Join Preston Garvey in Sanctuary',DataQuestJournal('None'));
            $this->assertSame('Sanctuary Hills',DataLastKnownLocation());
            $this->assertSame('CommonwealthClear',DataLastKnownWeatherHuman());
            $this->assertSame('Sanctuary Hills',DataLastKnownLocationContextParts()['location']);
            $this->assertSame('',DataLastKnowDate());
            foreach (['session_id'=>'session:other','generation'=>3,'context_sequence'=>8] as $key=>$value) {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>array_replace($event,[$key=>$value])];
                $this->assertStringContainsString('unavailable',DataGetCurrentTask());
                $this->assertSame('',DataLastKnownLocation());
                $this->assertSame('',DataLastKnownWeatherHuman());
            }
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>$event];
            unset($db->payload['active_quests'],$db->payload['world']);
            $this->assertStringContainsString('unavailable',DataQuestJournal('None'));
            $this->assertNull(synthLatestWorldContextPayload());
            $db->payload['active_quests'] = [];
            $this->assertStringContainsString('No active quests in the captured journal snapshot',DataGetCurrentTask());
        } finally { $GLOBALS['SYNTH_REQUEST_EVENT'] = $previousEvent; }
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['db'],
            $GLOBALS['SYNTH_NAME'],
            $GLOBALS['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'],
            $GLOBALS['PATCH_OVERRIDE_VOICE'],
            $GLOBALS['PATCH_OVERRIDE_VOICE_ID'],
            $GLOBALS['TTS'],
            $GLOBALS['TTSFUNCTION'],
            $GLOBALS['TTS_FUNCTION'],
            $GLOBALS['SYNTH_TTS_PRONUNCIATION_BYPASS']
        );
    }

    public function testNarratorVoiceSettingsLoadThroughCoreProfile(): void
    {
        $GLOBALS['db'] = new class {
            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function fetchOne(string $query): array
            {
                if (str_contains($query, "id = 'profile_id'")) {
                    return ['value' => '7'];
                }
                if (str_contains($query, 'FROM core_profiles WHERE id = 7')) {
                    return ['id' => 7, 'tts_connector_id' => 0];
                }
                if (str_contains($query, "id = 'voiceid'")) {
                    return ['value' => 'maleadult02'];
                }
                return [];
            }
        };
        $GLOBALS['SYNTH_NAME'] = 'Ranger Ghost';
        $GLOBALS['TTS'] = [];
        $GLOBALS['TTSFUNCTION'] = 'none';

        loadNarratorVoiceSettings();

        $this->assertSame('maleadult02', $GLOBALS['PATCH_OVERRIDE_VOICE']);
    }

    public function testPowerAwarenessReadsNpcMasterMetadata(): void
    {
        $GLOBALS['db'] = new class {
            public string $lastQuery = '';

            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function fetchOne(string $query): array
            {
                $this->lastQuery = $query;
                return ['metadata' => '{"stats":{"level":18}}'];
            }
        };

        $this->assertSame(18, getNpcLevel('Ranger Ghost'));
        $this->assertStringContainsString('FROM core_npc_master', $GLOBALS['db']->lastQuery);
    }

    public function testRestrainedActivityStatusSurvivesSanitization(): void
    {
        $status = synthSanitizeActivityStatusPayload([
            'is_restrained' => true,
            'timestamp' => 1234,
        ]);

        $this->assertTrue($status['is_restrained']);
        $this->assertSame('restrained', $status['current_action']);
        $this->assertSame('restrained', synthBuildActivityStatusSummary($status));
    }

    public function testRechatRejectsHardActorStateEvenWhenActorCanHearPlayer(): void
    {
        foreach (['busy', 'sleeping', 'unconscious', 'restrained', 'bleedout'] as $state) {
            $reason = synthRechatNearbyActorBlockReason('Piper', [
                '__snapshot_fresh' => true,
                'piper' => [
                    'eligible' => true,
                    'can_hear_player' => true,
                    'status' => $state,
                    'reason' => 'line_of_sight_clear',
                ],
            ]);

            $this->assertStringContainsString($state, $reason);
        }
    }

    public function testTtsDictionaryUsesWholeTermsAndScopedCustomPriority(): void
    {
        $this->assertSame([], synthDefaultTtsPronunciationEntries());
        $GLOBALS['SYNTH_NAME'] = 'Piper';
        $scope = synthTtsPronunciationCurrentSpeakerScope([
            'npc_name' => 'Piper',
            'race' => 'Human',
            'worldknowledge_tags' => 'diamond_city, reporter',
        ]);
        $this->assertSame(['diamond_city', 'reporter'], $scope['knowledge_tags']);

        $rows = [
            ['source_text' => 'Commonwealth', 'spoken_text' => 'Common-wealth', 'is_builtin' => true, 'enabled' => true],
            [
                'source_text' => 'Commonwealth', 'spoken_text' => 'the Commonwealth',
                'npc_names' => 'Piper', 'races' => 'Human', 'oghma_tags' => 'reporter', 'enabled' => true,
            ],
            ['source_text' => 'Diamond City', 'spoken_text' => 'Diamond City', 'enabled' => true],
        ];

        $GLOBALS['SYNTH_TTS_PRONUNCIATION_BYPASS'] = true;
        $this->assertSame('Commonwealth.', synthApplyTtsPronunciationDictionary('Commonwealth.', $rows));
        unset($GLOBALS['SYNTH_TTS_PRONUNCIATION_BYPASS']);

        $this->assertSame(
            'the Commonwealth and Diamond City; Commonwealths remains separate.',
            synthApplyTtsPronunciationDictionary(
                'Commonwealth and Diamond City; Commonwealths remains separate.',
                $rows,
                $scope['knowledge_tags'],
                $scope['npc_name'],
                $scope['race']
            )
        );
    }
}
