<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . "/DaoTestDoubles.php");
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
        $wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\1\0";
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
                    return str_contains($sql,'public.core_media_object') ? $this->media : ['state'=>'running','within_deadline'=>'t'];
                }
            };
            DaoTestDoubles::workerJobFrom($db);
            \Synth\Core\DbManager\Dao\MediaObjectDao::inject(new class($db) extends \Synth\Core\DbManager\Dao\MediaObjectDao {
                public function __construct(private object $fake) {}
                public function delivery(string $mediaId): ?array { return $this->fake->media; }
            });
            $this->assertSame('pending',synth_tts_resolve_job_status($db,$read,$root)['status']);
            $db->media=['state'=>'ready','content_type'=>'audio/wav','byte_length'=>strlen($wav),'sha256'=>hash('sha256',$wav)];
            $resolved=synth_tts_resolve_job_status($db,$read,$root);
            $this->assertSame('ready',$resolved['status']);
            $this->assertArrayNotHasKey('_job_id',$resolved);
            $this->assertSame('media/'.hash('sha256',$wav),$resolved['media']['media_id']);
            $this->assertSame($before,file_get_contents($status));
            $this->assertSame($metadata,file_get_contents($object.'.json'));
        } finally {
            \Synth\Core\DbManager\Dao\MediaObjectDao::inject(null);
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
        $wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\1\0";
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
            DaoTestDoubles::ownerFrom($db);
            \Synth\Core\DbManager\Dao\MediaObjectDao::inject(new class($db) extends \Synth\Core\DbManager\Dao\MediaObjectDao {
                public function __construct(private object $fake) {}
                public function publishReady(string $mediaId, string $contentType, int $size, string $sha256, array $correlation): bool { $this->fake->writes++; return true; }
            });
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
            \Synth\Core\DbManager\Dao\MediaObjectDao::inject(null);
            DaoTestDoubles::reset();
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
        $wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\1\0";
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
            DaoTestDoubles::ownerFrom($GLOBALS['db']);
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
            DaoTestDoubles::reset();
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
                if (str_contains($sql,'FROM public.core_action_intent')) {
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

    // Auto Chat's rewrite stage fails closed before any provider, derived-text change or state; its rechat root is only
    // the one proven generated line; raw-intent shortcuts never see Auto Chat intent; the pipeline order is fixed.
    public function testAutoChatStageFailsClosedAndRootUsesOnlyItsProvenGeneratedLine(): void
    {
        $root=dirname(__DIR__,2);
        require_once $root.'/lib/response.php';
        require_once $root.'/lib/synth_autochat.php';
        require_once $root.'/lib/synth_rechat_routes.php';
        require_once $root.'/lib/synth_combat_target.php';
        require_once $root.'/lib/synth_social_tasks.php';
        require_once $root.'/lib/synth_jev_actions.php';
        require_once $root.'/lib/synth_runtime.php';
        require_once $root.'/lib/request.php';
        require_once $root.'/lib/synth_player_rewrite.php';
        $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_INPUT_TARGET_IDENTITY','CORE_CONNECTOR_PLAYER',
            'HTTP_TIMEOUT','SYNTH_AUTOCHAT','JEV_ACTIONS_ENABLED','FUNCTIONS_ARE_ENABLED','SYNTH_JEV_ACTION_POLICY','SYNTH_JEV_ACTION_DIAGNOSTIC',
            'SYNTH_STRUCTURED_INPUT_FIELDS','SYNTH_PLAYER_INPUT_TEXT','SYNTH_INPUT_TARGET','SYNTH_SKIP_PLAYER_TTS'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        $target=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'pt-auto','display_name'=>'Piper'];
        $player=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'pt-auto','display_name'=>'Nora'];
        $context=['player'=>$player,'target'=>$target,'payload'=>['player'=>$player,'target'=>$target,'audience'=>[$target]]];
        $event=['protocol_version'=>2,'type'=>'input_text','session_id'=>'session:auto','request_id'=>'auto:1','turn_id'=>'turn:auto',
            'generation'=>7,'context_sequence'=>9,'runtime_variant'=>'flat',
            'capabilities'=>['dialogue.turn_ownership','social.tasks.v1','context.saved_anchor','context.combat_contacts','context.combat_targets'],
            'payload'=>['text'=>'Wait here and tell me who that is','control'=>['mode'=>'AUTOCHAT','model_slot'=>1]]];
        try {
            $GLOBALS['db']=$db=new class($context) {
                public array $queries=[];
                public ?array $connector=['id'=>2,'driver'=>'openai','url'=>'http://127.0.0.1:9/v1','model'=>'m','metadata'=>''];
                public function __construct(public array $context) {}
                public function escape(string $value): string { return $value; }
                public function escapeLiteral(string $value): string { return "'".$value."'"; }
                public array $history=[];
                public function insertNativeHistoryOnce(array $data): bool { $this->history[]=$data; return true; }
                public function fetchOne(string $query): ?array {
                    $this->queries[]=$query;
                    if (str_contains($query,'public.core_request_execution')) return ['state'=>'streaming','error_code'=>null,
                        'runtime_state'=>'active','active_generation'=>7,'active_turn_id'=>'turn:auto','active_turn_sequence'=>9];
                    if (str_contains($query,'core_runtime_context')) return ['context_payload'=>json_encode($this->context)];
                    return str_contains($query,'core_llm_connector') ? $this->connector : null;
                }
                public function fetchAll(string $query, $log=false): array { $this->queries[]=$query; return []; }
            };
            DaoTestDoubles::ownerFrom($db);
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['CORE_CONNECTOR_PLAYER']=2;$GLOBALS['HTTP_TIMEOUT']=15;unset($GLOBALS['SYNTH_AUTOCHAT']);
            $bound=['inputtext',0,0,json_encode(['schema'=>'synth.input.v1','npc'=>'Piper','text'=>$event['payload']['text'],'game'=>'fo4'])];
            $attempt=static function (array $request, ?array $responder) use ($db,$event): array {
                $GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']=$responder;$db->queries=[];
                try { synth_autochat_prepare($db,$event,$request,null); return [$request,null]; }
                catch (Throwable $error) { return [$request,$error]; }
            };
            // A responder other than the addressed target, and an unsupported, missing or unconfigured player connector,
            // fail closed with a closed detail: no provider call, no derived-text change, no accepted state.
            foreach ([['rewrite_target_mismatch',['form_id'=>'0x0001A4D8']+$target,null],['provider_unsupported',$target,null],
                ['provider_unsupported',$target,'missing'],['provider_unsupported',$target,'unconfigured']] as [$code,$responder,$variant]) {
                if ($variant==='missing') $db->connector=null;
                if ($variant==='unconfigured') $GLOBALS['CORE_CONNECTOR_PLAYER']=0;
                [$request,$error]=$attempt($bound,$responder);
                $this->assertSame($code,$error?->getMessage(),strval($variant));
                $this->assertSame($code,synth_autochat_failure($error));
                $this->assertSame($bound,$request);
                $this->assertNull(synth_autochat_line());
                if ($code==='rewrite_target_mismatch') $this->assertSame([],array_filter($db->queries,static fn($q)=>str_contains($q,'core_llm_connector')));
            }
            // Only the bound structured inputtext is rewritten; the legacy `**` wrapper or another type never reaches a read.
            foreach ([['inputtext',0,0,'**(Wait here)'],['cheatmode',0,0,$bound[3]]] as $unbound) {
                [,$error]=$attempt($unbound,$target);
                $this->assertSame('provider_failed',synth_autochat_failure($error));
                $this->assertSame([],$db->queries);
            }
            $this->assertSame('provider_failed',synth_autochat_failure(new RuntimeException('Bearer secret at http://provider')));
            foreach ([array_replace_recursive($event,['payload'=>['control'=>['mode'=>'STANDARD']]]),array_replace($event,['request_id'=>'auto:other'])] as $foreign) {
                try { synth_autochat_rewrite($db,$foreign,null); $this->fail('Foreign or ordinary owner entered the stage'); }
                catch (LogicException $error) { $this->assertSame('autochat_owner_required',$error->getMessage()); }
            }
            $id=synth_autochat_utterance_id($event);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$id);
            $this->assertNotSame($id,synth_autochat_utterance_id(array_replace($event,['request_id'=>'auto:2'])));
            // The ordinary adapter's exact derived text for the bound player and canonical target is frozen once.
            $accepted=['text'=>'Hold this spot for me.','target'=>$target,'player'=>'Nora','utterance_id'=>$id,'published'=>false,'data'=>null];
            $structured=['schema'=>'synth.input.v1','npc'=>'Piper','npc_id'=>$target['form_id'],'npc_plugin'=>$target['origin_plugin'],
                'player'=>'Nora','player_id'=>$player['form_id'],'player_plugin'=>$player['origin_plugin'],'playthrough_id'=>'pt-auto',
                'text'=>'Hold this spot for me.','game'=>'fo4'];
            $freeze=static function (array $fields) use ($accepted): ?string {
                $GLOBALS['SYNTH_AUTOCHAT']=['text'=>$fields['text']]+$accepted;
                $request=['inputtext',0,0,json_encode($fields)];
                $adapted=synth_adapt_json_input_payload_for_pipeline($request);
                try { synth_autochat_freeze_input($adapted,$request); return $GLOBALS['SYNTH_AUTOCHAT']['data']; }
                catch (LogicException $error) { return $error->getMessage(); }
            };
            $this->assertSame('Nora: Hold this spot for me. (Talking to Piper)',$freeze($structured));
            foreach ([['npc'=>'Nick Valentine'],['npc_id'=>'0x0001A4D8'],['playthrough_id'=>'pt-other'],['player'=>'Nate']] as $change) {
                $this->assertSame('autochat_derived_text_mismatch',$freeze(array_replace($structured,$change)),json_encode($change));
            }
            // A grunt-like line still carries its speaker and addressee, so the whole-text combat-grunt filter cannot drop it.
            $grunt=$freeze(['text'=>'Hmm!']+$structured);
            $this->assertSame('Nora: Hmm! (Talking to Piper)',$grunt);
            // The history row is exactly the frozen text, stored as accepted input (`received`) and never claims delivery.
            $row=['type'=>'inputtext','data'=>'Nora: Hold this spot for me. (Talking to Piper)','people'=>'|Nora|Piper|'];
            foreach (['unfrozen'=>[$accepted,[]],'raw intent'=>[null,['data'=>'Nora: Wait here (Talking to Piper)']],
                'embedded line'=>[null,['data'=>'Nora: Wait. Nora: Hold this spot for me. (Talking to Piper)']],
                'other target'=>[null,['data'=>'Nora: Hold this spot for me. (Talking to Nick Valentine)']],
                'claimed delivery'=>[null,['delivery_state'=>'spoken']],'foreign utterance'=>[null,['utterance_id'=>'utt_0123']]] as $case=>[$state,$change]) {
                $GLOBALS['SYNTH_AUTOCHAT']=$state ?? ['data'=>$row['data']]+$accepted;
                try { synth_autochat_record_history($db,array_replace($row,$change)); $this->fail('History stored: '.$case); }
                catch (LogicException $error) { $this->assertSame('autochat_history_mismatch',$error->getMessage(),$case); }
            }
            $this->assertSame([],$db->history);
            synth_autochat_record_history($db,$row);
            $this->assertSame([['utterance_id'=>$id,'delivery_state'=>'received']+$row],$db->history);
            // Rewrite pair context: the player's input to this exact target and the target's own heard replies only.
            // A witnessed NPC line or observed vanilla line is omitted, never presented as the player's speech.
            $pair=synth_autochat_pair_history(array_reverse([
                ['type'=>'backgroundchat','own'=>0,'data'=>'Danny Sullivan: Overheard. (Talking to Piper)'],
                ['type'=>'chat','own'=>0,'data'=>'Nick Valentine: Witnessed. (talking to Nora)'],
                ['type'=>'inputtext','own'=>0,'data'=>'Nora: Hello, Piper. (Talking to Piper)'],
                ['type'=>'inputtext','own'=>0,'data'=>'Nora: Hi, Nick. (Talking to Nick Valentine)'],
                ['type'=>'inputtext','own'=>0,'data'=>'Nora: Unaddressed.'],
                ['type'=>'chat','own'=>1,'data'=>'Piper: Hey, Blue. (talking to Nora)']]),'Piper');
            $this->assertSame([['role'=>'user','content'=>'Hello, Piper.'],['role'=>'assistant','content'=>'Hey, Blue.']],$pair);
            $prompt=json_encode(SynthPlayerRewrite::messages('Nora','Piper','ask',[],$pair));
            $this->assertStringContainsString('Nora: Hello, Piper.\nPiper: Hey, Blue.\n',$prompt);
            foreach (['Overheard','Witnessed','Nick','Unaddressed'] as $foreign) $this->assertStringNotContainsString($foreign,$prompt);
            // Raw-intent shortcuts stay closed to Auto Chat intent even when every capability is negotiated.
            $GLOBALS['JEV_ACTIONS_ENABLED']=true;$GLOBALS['FUNCTIONS_ARE_ENABLED']=true;$db->queries=[];
            synth_jev_prepare_actions($db,$event,null,static fn()=>throw new LogicException('jev_provider_called'));
            $this->assertSame('ineligible',$GLOBALS['SYNTH_JEV_ACTION_DIAGNOSTIC']['reason']);
            $this->assertNull(synth_combat_identification_reply($db,$event));
            $this->assertNull(synth_social_admit_from_input($db,$event));
            $this->assertSame([],$db->queries);
            // Rechat root: exactly one player line routed to the original target under the root's utterance id.
            $route=['listener_state'=>'addressed','listener'=>json_encode($target),'utterance_id'=>$id];
            $proven=['target'=>$target,'player_lines'=>[['line_id'=>'dialogue:2','text'=>'Hold this spot for me.','route'=>$route]]];
            $this->assertSame('Hold this spot for me.',synth_autochat_root_line($proven,$event));
            $line=$proven['player_lines'][0];
            foreach (['missing'=>['player_lines'=>[]]+$proven,'duplicated'=>['player_lines'=>[$line,$line]]+$proven,
                'foreign listener'=>['player_lines'=>[['route'=>['listener'=>json_encode(['form_id'=>'0x0001A4D8']+$target)]+$route]+$line]]+$proven,
                'unaddressed'=>['player_lines'=>[['route'=>['listener_state'=>'unknown','listener'=>null]+$route]+$line]]+$proven,
                'foreign utterance'=>['player_lines'=>[['route'=>['utterance_id'=>'utt_0123']+$route]+$line]]+$proven,
                'invalid text'=>['player_lines'=>[['line_id'=>'dialogue:2','text'=>null,'route'=>['listener_state'=>'invalid','listener'=>null]]]]+$proven,
                'no target'=>['target'=>null]+$proven,'ordinary root'=>$proven] as $case=>$response) {
                try {
                    synth_autochat_root_line($response,$case==='ordinary root'
                        ? array_replace_recursive($event,['payload'=>['control'=>['mode'=>'STANDARD']]]) : $event);
                    $this->fail('Unproven Auto Chat root accepted: '.$case);
                } catch (LogicException $error) { $this->assertSame('rechat_history_autochat_unproven',$error->getMessage(),$case); }
            }
            $this->assertSame($event,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
        } finally {
            DaoTestDoubles::reset();
            foreach ($keys as $key) {
                if (array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key]; else unset($GLOBALS[$key]);
            }
        }
        // Order of the owned pipeline: frozen origin policy, then rewrite, then echo; every raw-intent shortcut and the
        // legacy respeech/speech-table rewrite are closed to Auto Chat.
        $pipeline=file_get_contents($root.'/main_synth_pipeline.php');
        $policy=strpos($pipeline,"synth_dialogue_capture_origin_policy(\$db,\\Synth\\Bootstrap::requestEvent()['json']");
        $rewrite=strpos($pipeline,'synth_autochat_prepare(');
        $echo=strpos($pipeline,'synth_emit_native_player_transcript($gameRequest);');
        $this->assertTrue($policy!==false && $rewrite>$policy && $echo>$rewrite);
        foreach (['if (!$synthAutoChat && in_array(\'social.tasks.v1\'','if (!$synthAutoChat && synth_gift_menu_try_handoff(',
            "if (!\$synthAutoChat && in_array(\$gameRequest[0],[\"inputtext\",\"inputtext_s\",\"narrator_inputtext\"]) && \Synth\Bootstrap::has('PLAYER_RESPEECH') && \Synth\Bootstrap::config(\"PLAYER_RESPEECH\")",
            'if (!$synthAutoChat && \\Synth\\Bootstrap::has(\'SYNTH_EXECUTION_MODE\') && \\Synth\\Bootstrap::config("SYNTH_EXECUTION_MODE") === "AUTOCHAT"',
            'if ($synthAutoChat) synth_autochat_freeze_input($normalizedInputPayload ?? [], $gameRequest);',
            'if ($synthAutoChat && $gameRequest[0] === \'inputtext\') synth_autochat_record_history($db, $eventlogInsert);'] as $gate) {
            $this->assertStringContainsString($gate,$pipeline);
        }
        // The combat-grunt filter compares the whole derived text (proven above to carry speaker and addressee), before
        // the history stage; the pair context never uses the shared history formatter.
        $filter=strpos($pipeline,'$trimmedData = trim($data);');
        $this->assertTrue($filter!==false && $filter<strpos($pipeline,'synth_autochat_record_history(')
            && $filter>strpos($pipeline,'synth_autochat_freeze_input('));
        foreach (['$data = isset($gameRequest[3]) ? $gameRequest[3] : \'\';','if (in_array($trimmedData, $combatGrunts)) {'] as $shape) {
            $this->assertStringContainsString($shape,$pipeline);
        }
        $this->assertStringNotContainsString('->history(',file_get_contents($root.'/managers/Conversation/Rechat/synth_autochat.php'));
        $this->assertStringNotContainsString('PLAYER_RESPEECH',file_get_contents($root.'/managers/Conversation/Rechat/synth_autochat.php'));
        $this->assertStringNotContainsString('player_rewrite.php`',file_get_contents($root.'/managers/Conversation/Rechat/synth_autochat.php'));
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
            'RANDOM_NARATION'=>true,'RANDOM_NARATION_CHANCE'=>15,'RANDOM_NARRATION_COOLDOWN'=>2,
            'source_profile_id'=>123,'PROVIDER_KEY'=>'not-a-real-key'];
        foreach (['STANDARD'=>false,'WHISPER'=>true,'CLOSE'=>false,'SHOUT'=>false] as $mode=>$private) {
            $policy=synth_dialogue_origin_policy($settings,$mode);
            $this->assertSame($private,$policy['private']);
            // Close is a non-private small group, but narration never reaches past its captured hearers.
            $this->assertSame(!in_array($mode,['WHISPER','CLOSE'],true),$policy['random_narration']);
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
        $companion=$event+['type'=>'trigger','payload'=>['kind'=>'companion_combat_comment']];
        $companionKey=synthRpgCommentCooldownKey($db,$companion);
        $this->assertStringStartsWith('SYNTH_COMPANION_COMBAT_V2:',$companionKey);
        $this->assertNotSame($key,$companionKey);
        $this->assertSame($companionKey,synthRpgCommentCooldownKey($db,$companion+['turn_id'=>'another-turn']));
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
                if (str_contains($sql,'core_config_setting') || str_contains($sql,'conf_opts')) return null;
                if (!str_contains($sql,'core_runtime_context')) throw new LogicException('Global query forbidden');
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
            $db->payload['quest_events']=['transitions'=>[['display_name'=>'Private future quest']]];
            $snapshot=synth_protocol_prompt_snapshot($db);
            $this->assertArrayNotHasKey('quest_events',$snapshot);
            $this->assertArrayNotHasKey('active_quests',$snapshot);
            $this->assertStringContainsString('unavailable',DataGetCurrentTask());
            $this->assertStringNotContainsString('When Freedom Calls',DataGetCurrentTask());
            $this->assertStringNotContainsString('Join Preston Garvey in Sanctuary',DataQuestJournal('None'));
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
            $this->assertStringContainsString('unavailable',DataGetCurrentTask());
        } finally { $GLOBALS['SYNTH_REQUEST_EVENT'] = $previousEvent; }
    }

    private function withQuestPromptContext(callable $test): void
    {
        require_once dirname(__DIR__,2).'/lib/data_functions.php';
        $keys=['db','gameRequest','SYNTH_REQUEST_EVENT','SYNTH_NAME','SYNTH_SPEECH_ROLE',
            'SYNTH_TURN_QUEST_PROMPT','SYNTH_TURN_ACTIVE_QUESTS','SYNTH_INPUT_TARGET',
            'SYNTH_INPUT_TARGET_IDENTITY','SYNTH_PLAYER_IDENTITY','SYNTH_TURN_ACTOR_STATES',
            'SYNTH_TURN_NEARBY_ITEMS','SYNTH_TURN_POINTS_OF_INTEREST','SYNTH_TURN_WORLD_STATE',
            'SYNTH_RESPONSE_SPEAKER_FORMID','SYNTH_TURN_PEOPLE_SNAPSHOT','SYNTH_EXTERNAL_REQUEST',
            'SYNTH_CLIENT_CAPABILITIES'];
        $saved=array_intersect_key($GLOBALS,array_flip($keys));
        foreach ($keys as $key) unset($GLOBALS[$key]);
        $db=new class {
            public array $payload;
            public function escapeLiteral(string $value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchOne(string $sql): ?array {
                if (str_contains($sql,'core_config_setting') || str_contains($sql,'conf_opts')) return null;
                $actor=$this->payload['target'];
                if (str_contains($sql,'SELECT a.full_form_id')) return [
                    'full_form_id'=>$actor['form_id'],'origin_plugin'=>$actor['origin_plugin'],'playthrough_id'=>$actor['playthrough_id']];
                if (str_contains($sql,'SELECT i.action_name')) return [
                    'action_name'=>'ReadQuests','capability'=>'action.read_quests','arguments'=>'{}',
                    'actor_name'=>$actor['display_name'],'actor_form_id'=>$actor['form_id'],
                    'actor_plugin'=>$actor['origin_plugin'],'actor_playthrough'=>$actor['playthrough_id'],
                    'target_name'=>'Private journal entry','target_form_id'=>null,'target_plugin'=>null,'target_playthrough'=>null];
                if (!str_contains($sql,'core_runtime_context')) throw new LogicException('Unexpected quest prompt query');
                return str_contains($sql,"c.session_id='quest:owner'") && str_contains($sql,'c.generation=2')
                    && str_contains($sql,'c.snapshot_sequence=7') ? ['context_payload'=>json_encode($this->payload)] : null;
            }
            public function fetchAll(string $sql): never { throw new LogicException('Mutable global journal forbidden'); }
            public function execQuery(string $sql): never { throw new LogicException('Quest prompt must not write'); }
        };
        $db->payload=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/quest-tracking.json'),true)['payload'];
        $public=$db->payload['active_quests'][0];
        $private=array_replace($public,['form_id'=>'0x00001000','display_name'=>'Private journal entry',
            'objectives'=>['Find the secret cache'],'tracked'=>false]);
        $db->payload['active_quests'][]=$private;
        $GLOBALS['db']=$db;
        $event=['protocol_version'=>2,'session_id'=>'quest:owner','request_id'=>'quest:request',
            'generation'=>2,'context_sequence'=>7,'runtime_variant'=>'flat','type'=>'input_text',
            'payload'=>['text'=>'Hello','actor'=>$db->payload['target'],'control'=>['mode'=>'CHAT']]];
        try { $test($db,$event,$public); }
        finally {
            foreach ($keys as $key) unset($GLOBALS[$key]);
            foreach ($saved as $key=>$value) $GLOBALS[$key]=$value;
        }
    }

    public function testAutomaticNpcQuestPromptDoesNotGrantPrivateJournalEvenToTeammates(): void
    {
        $this->withQuestPromptContext(function($db,array $event): void {
            $original=$db->payload;
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            // A native NPC cannot borrow narrator authority from its display label.
            $GLOBALS['SYNTH_NAME']='The Narrator';
            $request=['inputtext',0,0,''];
            synth_protocol_bind_dialogue_context($db,$event,$request);
            $structured=json_decode($request[3],true);
            $this->assertSame([],$structured['active_quests']);
            $this->assertSame([],$GLOBALS['SYNTH_TURN_ACTIVE_QUESTS']);
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            foreach ($db->payload['actor_states'] as &$state) $state['teammate']=true;
            unset($state);
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            $this->assertStringContainsString('unavailable',DataQuestJournal('None'));
            $this->assertStringNotContainsString('Find the secret cache',DataQuestJournal('None'));
            $this->assertSame($original['active_quests'],$db->payload['active_quests']);
        });
    }

    public function testRawQuestReactionProjectionCannotTeachNpcWithoutLockedHistory(): void
    {
        $this->withQuestPromptContext(function($db,array $event,array $public): void {
            $event['type']='trigger';
            $event['payload']=['kind'=>'quest_updated','actor'=>$db->payload['target'],'previous_context_sequence'=>6];
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $original=$db->payload;
            require_once dirname(__DIR__,2).'/lib/quest_reaction.php';
            $previous=$db->payload;
            $previous['active_quests'][0]['objectives']=['An earlier displayed objective'];
            // Use the same raw journal comparator as synthBindQuestReaction, so
            // normalization cannot silently discard real admitted changes.
            $reaction=['previous_context_sequence'=>6,'quests'=>synthQuestReactionChanges($previous,$db->payload),
                'text'=>'A tracked objective changed.'];
            $bound=synth_protocol_bind_quest_prompt($event,$db->payload,false,$reaction);
            $this->assertSame([$public],$bound['active_quests']);
            $prompt=DataGetCurrentTask('speaker');
            $this->assertStringContainsString('unavailable',$prompt);
            $this->assertStringNotContainsString($public['objectives'][0],$prompt);
            $this->assertStringNotContainsString('Private journal entry',$prompt);
            $this->assertStringNotContainsString('Find the secret cache',$prompt);
            $this->assertSame($original,$db->payload);
            // A later ordinary input cannot reuse the reaction projection.
            $event['type']='input_text';$event['payload']=['text'=>'Hello'];
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
        });
    }

    public function testQuestReactionProjectionCannotCrossRequestSessionSaveOrSnapshot(): void
    {
        $this->withQuestPromptContext(function($db,array $event,array $public): void {
            $event['type']='trigger';
            $event['payload']=['kind'=>'quest_updated','actor'=>$db->payload['target'],'previous_context_sequence'=>6];
            $reaction=['previous_context_sequence'=>6,'quests'=>[$public]];
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            synth_protocol_bind_quest_prompt($event,$db->payload,false,$reaction);
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            $this->assertStringNotContainsString($public['display_name'],DataGetCurrentTask('speaker'));
            foreach (['session_id'=>'quest:other','request_id'=>'quest:other-request','generation'=>3,'context_sequence'=>8] as $key=>$value) {
                $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>array_replace($event,[$key=>$value])];
                $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            }
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $original=$db->payload;
            $db->payload['target']['playthrough_id']='save:other';
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            $db->payload=$original;
            $db->payload['active_quests'][0]['objectives']=['A different captured objective'];
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            $db->payload=$original;
            $db->payload['active_quests_observation']='cached';
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            $db->payload=$original;
            $request=['inputtext',0,0,''];
            $ordinary=array_replace($event,['type'=>'input_text','payload'=>['text'=>'Hi']]);
            synth_protocol_bind_dialogue_context($db,$ordinary,$request);
            $this->assertSame('none',$GLOBALS['SYNTH_TURN_QUEST_PROMPT']['scope']);
        });
    }

    public function testNarratorQuestContextRequiresLockedNativeOwner(): void
    {
        $this->withQuestPromptContext(function($db,array $event): void {
            $event['payload']['control']['mode']='NARRATOR';
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $request=['narrator_inputtext',0,0,''];
            synth_protocol_bind_dialogue_context($db,$event,$request);
            $structured=json_decode($request[3],true);
            // This fake reader lacks a native request lock. Real narrator journal
            // ownership is exercised by QuestKnowledgeTest's SQL fixtures.
            $this->assertSame([],$structured['active_quests']);
            $this->assertSame('narrator',$structured['quest_knowledge']['perspective']);
            $this->assertSame('unavailable',$structured['quest_knowledge']['observation']);
            $this->assertStringNotContainsString('Find the secret cache',DataGetCurrentTask('speaker'));
            $this->assertStringNotContainsString('Find the secret cache',DataQuestJournal('None'));
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['session_id']='quest:other';
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
        });
    }

    public function testNativeNpcCannotBypassPrivateJournalThroughToolEmissionOrIntent(): void
    {
        $this->withQuestPromptContext(function($db,array $event): void {
            require_once dirname(__DIR__,2).'/lib/response.php';
            require_once dirname(__DIR__,2).'/lib/core/action_catalog.php';
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.read_quests'];
            $this->assertFalse(synth_protocol_player_journal_action_allowed());
            $this->assertFalse(synthActionCatalogIsActionEnabled('ReadQuests'));
            $this->assertNull(synth_protocol_action_from_legacy_line([
                'speaker'=>$db->payload['target']['display_name'],'command_name'=>'ReadQuests',
                'target'=>'0x00001000','command_args'=>['0x00001000']]));
            foreach ([['server_code'=>'ReadQuests'],['name'=>'read_quests'],['capability'=>'action.read_quests']] as $action) {
                try {
                    synth_protocol_record_action_intent($db,$action);
                    $this->fail('NPC journal intent bypassed the authoritative gate');
                } catch (DomainException $error) {
                    $this->assertSame('private_quest_journal_not_shared',$error->getMessage());
                }
            }
            $event['payload']['control']['mode']='NARRATOR';
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $this->assertTrue(synth_protocol_player_journal_action_allowed());
            // Retain legacy explicitly invoked tool compatibility.
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>1]];
            $this->assertTrue(synth_protocol_player_journal_action_allowed());
        });
    }

    public function testOldNativeNpcQuestResultIsRedactedBeforeFuncretHistoryOrPrompt(): void
    {
        $this->withQuestPromptContext(function($db,array $event): void {
            $event['type']='action_result';$event['turn_id']='quest:turn';
            $event['payload']=['result'=>['action_id'=>'quest:action','idempotency_key'=>'quest:key',
                'status'=>'succeeded','detail'=>'Private journal entry: Find the secret cache']];
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
            $request=['action_result',0,0,''];
            synth_protocol_bind_action_result($db,$event,$request);
            $this->assertSame('funcret',$request[0]);
            $bound=json_decode($request[3],true);
            $this->assertSame('ReadQuests',$bound['action']);
            $this->assertSame('unavailable',$bound['status']);
            $this->assertSame('',$bound['target']);
            $this->assertStringContainsString('no quest facts were shared',$bound['result']);
            $this->assertStringNotContainsString('Private journal entry',$request[3]);
            $this->assertStringNotContainsString('secret cache',$request[3]);
            $this->assertStringContainsString('unavailable',DataGetCurrentTask('speaker'));
            $this->assertStringContainsString('secret cache',$db->payload['active_quests'][1]['objectives'][0]);
        });
    }

    protected function tearDown(): void
    {
        DaoTestDoubles::reset();
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
                if (str_contains($query, "config_key = 'profile_id'")) {
                    return ['value' => '7'];
                }
                if (str_contains($query, 'FROM core_profiles WHERE id = 7')) {
                    return ['id' => 7, 'tts_connector_id' => 0];
                }
                if (str_contains($query, "config_key = 'voiceid'")) {
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

        DaoTestDoubles::npcFrom($GLOBALS['db']);
        $this->assertSame(18, getNpcLevel('Ranger Ghost'));
        $this->assertStringContainsString('FROM core_npc', $GLOBALS['db']->lastQuery);
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
        $this->assertSame(['radroach', 'radroaches'], array_column(synthDefaultTtsPronunciationEntries(), 'source_text'));
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
