<?php

// Stage 1 native typed turn: one registered placed NPC, explicit owner, no legacy prompt/profile globals.
require_once __DIR__.'/synth_protocol_state.php';
require_once __DIR__.'/response.php';
require_once __DIR__.'/synth_dialogue_provider.php';

const SYNTH_DIALOGUE_NATIVE_TURN='dialogue.native_turn';
const SYNTH_DIALOGUE_MAX_LINES=12;
const SYNTH_DIALOGUE_MAX_OUTPUT=16384;
const SYNTH_DIALOGUE_MAX_LINE=1000;
const SYNTH_DIALOGUE_HISTORY_ROWS=12;
// Drivers whose existing speech plan is portable to the durable TTS worker.
const SYNTH_DIALOGUE_TTS_DRIVERS=['xtts-fastapi','chatterbox','pockettts','cartesia','inworld'];

final class SynthTurnOwner
{
    public function __construct(public readonly array $event, public readonly array $context,
        public readonly array $target, public readonly int $targetIdentityId, public readonly string $characterId,
        public readonly array $npc, public readonly array $connector, public readonly array $speech) {}
}

// Plugin-local placed reference ID. Regular plugins keep 6 hex digits; light (FE) plugins keep 3.
// Dynamic FF references have no stable incarnation and are rejected.
function synth_dialogue_local_reference($formId): ?string
{
    if (!is_string($formId) || preg_match('/^0x[0-9A-Fa-f]{8}$/D',$formId)!==1) return null;
    $hex=synthNormalizeRuntimeFormId($formId);
    if (str_starts_with($hex,'FF')) return null;
    return str_starts_with($hex,'FE') ? substr($hex,5) : substr($hex,2);
}

// Character key: playthrough + normalized origin plugin + local placed reference. Never base NPC ID or name.
function synth_dialogue_character_id(array $identity): ?string
{
    $local=synth_dialogue_local_reference($identity['form_id'] ?? null);
    $plugin=strtolower(trim(strval($identity['origin_plugin'] ?? '')));
    $playthrough=$identity['playthrough_id'] ?? null;
    if ($local===null || $plugin==='' || !synth_protocol_valid_id($playthrough)) return null;
    return 'chr:'.substr(hash('sha256','fo4|'.$playthrough.'|'.$plugin.'|'.$local),0,40);
}

// Explicit speech inputs from the NPC's own profile TTS connector and resolved voice. Null means the
// profile has no portable configured speech, so the turn is ineligible; there is no server-wide fallback.
function synth_dialogue_speech_settings(array $npcRow, array $profile): ?array
{
    require_once __DIR__.'/core/tts_connector.class.php';
    require_once __DIR__.'/core/npc_master.class.php';
    $tts=new TTSConnector();
    $connectorId=intval($profile['tts_connector_id'] ?? 0);
    $connector=$connectorId>0 ? $tts->getById($connectorId) : null;
    if (!is_array($connector)) return null;
    $runtime=$tts->runtimeSettings($connector);
    if (!in_array($runtime['TTSFUNCTION'],SYNTH_DIALOGUE_TTS_DRIVERS,true) || $runtime['provider_key']==='') return null;
    $voice=(new NpcMaster())->resolveNpcTtsVoiceForProfile($npcRow,$profile);
    $reference=trim(strval($voice['resolved_voice_reference'] ?? $voice['resolved_voice'] ?? ''));
    $resolved=trim(strval($voice['resolved_voice'] ?? ''));
    $inputs=['TTSFUNCTION'=>$runtime['TTSFUNCTION'],'TTS_FUNCTION'=>$runtime['TTS_FUNCTION'],'TTS'=>$runtime['TTS'],
        'SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'=>$runtime['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'],
        'SYNTH_CORE_CURRENT_NPC_DATA'=>$npcRow,'SYNTH_CORE_CURRENT_PROFILE_DATA'=>$profile,'SYNTH_SPEECH_ROLE'=>'npc'];
    if ($reference!=='') {
        $inputs['PATCH_OVERRIDE_VOICE']=$reference;
        // Only the profile connector's own provider block; other providers never enter the plan.
        $inputs['TTS']=array_replace_recursive($inputs['TTS'],array_intersect_key(NpcMaster::ttsVoiceSettings($reference,$resolved),
            [$runtime['provider_key']=>true]));
    }
    foreach (['original_voice'=>'TTS_NPC_ORIGINAL_VOICE','fallback_voice'=>'TTS_NPC_FALLBACK_VOICE',
        'resolved_voice'=>'TTS_NPC_RESOLVED_VOICE','resolved_voice_sample'=>'TTS_NPC_RESOLVED_VOICE_SAMPLE'] as $from=>$to)
        if (trim(strval($voice[$from] ?? ''))!=='') $inputs[$to]=strval($voice[$from]);
    $language=$inputs['TTS'][$runtime['provider_key']]['language'] ?? '';
    // Server-wide language/filter configuration only; never another provider or per-turn prompt state.
    foreach (['LANG_LLM_XTTS','TTS_FFMPEG_FILTERS'] as $key) if (array_key_exists($key,$GLOBALS)) $inputs[$key]=$GLOBALS[$key];
    if (!empty($inputs['LANG_LLM_XTTS']) && is_string($language) && trim($language)!=='')
        $inputs['LLM_LANG']=preg_replace('/[^a-z\-]/i','',strtolower(trim($language)));
    return $inputs;
}

// Admission for an explicit conversation-intent (/talk) turn. Returns the owner, or a short reason the
// caller reports as a terminal failure; an explicit conversation request never silently goes legacy.
// The native route emits no actions, so action requests belong in unmarked text.
function synth_dialogue_turn_owner($db, array $event): SynthTurnOwner|string
{
    $capabilities=$event['capabilities'] ?? [];
    if (($event['type'] ?? '')!=='input_text' || ($event['protocol_version'] ?? 1)!==2
        || ($event['payload']['intent'] ?? null)!=='conversation'
        || !in_array(SYNTH_DIALOGUE_NATIVE_TURN,$capabilities,true)
        || !in_array('dialogue.turn_ownership',$capabilities,true)) return 'native_turn_not_negotiated';
    if (!in_array($event['payload']['control']['mode'] ?? 'STANDARD',['STANDARD','WHISPER','CLOSE','SHOUT'],true))
        return 'conversation_mode_unsupported';
    $context=synth_protocol_original_context($db,$event);
    $target=is_array($context['target'] ?? null) ? $context['target'] : null;
    if ($target===null || trim(strval($target['display_name'] ?? ''))==='') return 'target_missing';
    $characterId=synth_dialogue_character_id($target);
    if ($characterId===null) return 'target_not_placed_reference';
    // Registered = existing actor_identity-bound NPC row with a profile. Never resolve by display name.
    $row=$db->fetchOne("SELECT a.id AS identity_id, n.id AS npc_id, n.npc_name, n.prompt_head, n.npc_static_bio,
            n.personality, n.speechstyle, n.occupation, n.voiceid, p.id AS profile_id, c.*
        FROM synth.actor_identities a JOIN synth.core_npc_master n ON n.actor_identity_id=a.id
        JOIN synth.core_profiles p ON p.id=n.profile_id JOIN core_llm_connector c ON c.id=p.llm_primary_id
        WHERE a.game='fo4' AND upper(a.full_form_id)=upper(".$db->escapeLiteral('0x'.synthNormalizeRuntimeFormId($target['form_id'])).")
            AND lower(a.origin_plugin)=lower(".$db->escapeLiteral($target['origin_plugin']).")
            AND a.playthrough_id=".$db->escapeLiteral($target['playthrough_id'])." LIMIT 1");
    if (!$row) return 'npc_not_registered';
    if (!synth_dialogue_provider_supported($row)) return 'llm_connector_unsupported';
    // Existing storage is keyed by runtime full form ID. Another identity row for the same plugin-local
    // reference means the load order moved; this slice cannot prove continuity, so it does not admit.
    $local=synth_dialogue_local_reference($target['form_id']);
    $others=$db->fetchAll("SELECT full_form_id FROM synth.actor_identities WHERE game='fo4'
        AND lower(origin_plugin)=lower(".$db->escapeLiteral($target['origin_plugin']).")
        AND playthrough_id=".$db->escapeLiteral($target['playthrough_id'])." AND id<>".intval($row['identity_id']));
    foreach (is_array($others) ? $others : [] as $other) {
        if (synth_dialogue_local_reference(strval($other['full_form_id'] ?? ''))===$local) return 'load_order_identity_ambiguous';
    }
    $npcRow=$db->fetchOne('SELECT * FROM synth.core_npc_master WHERE id='.intval($row['npc_id']));
    $profile=$db->fetchOne('SELECT * FROM synth.core_profiles WHERE id='.intval($row['profile_id']));
    $speech=is_array($npcRow) && is_array($profile) ? synth_dialogue_speech_settings($npcRow,$profile) : null;
    if ($speech===null) return 'tts_profile_unsupported';
    $npc=array_intersect_key($row,array_flip(['npc_id','npc_name','prompt_head','npc_static_bio','personality','speechstyle','occupation','voiceid']));
    return new SynthTurnOwner($event,$context,$target,intval($row['identity_id']),$characterId,$npc,$row,$speech);
}

// Bounded prior conversation from the existing ancestry-scoped reader: real player input and only
// receipt-confirmed (spoken/presented) NPC lines between this player and this placed NPC.
function synth_dialogue_history_messages($db, SynthTurnOwner $owner): array
{
    $rows=$db->fetchAll(synth_protocol_history_cte($db,$owner->event,strval($owner->target['display_name']))
        ." SELECT type,data FROM native_history WHERE ((type='inputtext' AND speaker_identity_id=reader_player_id)
            OR (type='chat' AND speaker_identity_id=reader_identity_id AND delivery_state IN ('spoken','presented')))
        ORDER BY history_event_id DESC LIMIT ".SYNTH_DIALOGUE_HISTORY_ROWS);
    $messages=[];
    foreach (array_reverse(is_array($rows) ? $rows : []) as $row) {
        $text=preg_replace('/\s+\((?:talking|Talking) to [^()]*\)$/u','',strval($row['data']));
        $colon=strpos($text,': ');
        $text=trim($colon===false ? $text : substr($text,$colon+2));
        if ($text!=='') $messages[]=['role'=>$row['type']==='chat' ? 'assistant' : 'user','content'=>mb_substr($text,0,SYNTH_DIALOGUE_MAX_LINE)];
    }
    return $messages;
}

function synth_dialogue_messages(SynthTurnOwner $owner, array $history=[]): array
{
    $npc=$owner->npc;$payload=$owner->context['payload'];
    $system="You are {$owner->target['display_name']} in Fallout 4. Reply in character with spoken dialogue only, no narration, actions or markup.";
    foreach (['prompt_head','npc_static_bio','personality','occupation','speechstyle'] as $field) {
        $value=trim(strval($npc[$field] ?? ''));
        if ($value!=='') $system.="\n".ucfirst(str_replace('_',' ',$field)).": ".mb_substr($value,0,4000);
    }
    $situation=['player'=>strval($owner->context['player']['display_name'] ?? ''),'audience'=>array_values(array_filter(array_map(
        static fn($actor)=>is_array($actor) ? strval($actor['display_name'] ?? '') : '',$payload['audience'] ?? [])))];
    if (is_array($payload['world'] ?? null)) $situation['world']=array_intersect_key($payload['world'],array_flip(['location','time','weather']));
    $situation=json_encode($situation,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    return [['role'=>'system','content'=>$system."\nSituation: ".$situation],...$history,
        ['role'=>'user','content'=>trim(strval($owner->event['payload']['text']))]];
}

// Complete sentences become lines; a trailing fragment is flushed at stream end.
function synth_dialogue_take_sentences(string &$buffer, bool $final): array
{
    $lines=[];
    while (preg_match('/^(.+?[.!?…]+["\')\]]?)(\s+|$)/su',$buffer,$match)===1 && ($final || $match[2]!=='')) {
        $lines[]=trim($match[1]);$buffer=substr($buffer,strlen($match[0]));
    }
    if ($final && trim($buffer)!=='') { $lines[]=trim($buffer);$buffer=''; }
    return array_values(array_filter($lines,static fn($line)=>$line!==''));
}

// Visibility tokens for the existing insertion-time history ownership rules.
function synth_dialogue_people(array $context): string
{
    $payload=$context['payload'] ?? [];
    $names=[strval($payload['target']['display_name'] ?? ''),strval($payload['player']['display_name'] ?? '')];
    foreach ($payload['audience'] ?? [] as $actor) if (is_array($actor)) $names[]=strval($actor['display_name'] ?? '');
    return '|'.implode('|',array_unique(array_filter(array_map('trim',$names)))).'|';
}

function synth_dialogue_history_fields(array $context, string $type, string $data, ?string $utterance, string $state): array
{
    $world=is_array($context['payload']['world'] ?? null) ? $context['payload']['world'] : [];
    $ticks=$world['game_time_ticks'] ?? 0;
    $location=$world['location'] ?? '';
    return ['type'=>$type,'data'=>$data,'sess'=>'pending','gamets'=>is_int($ticks) ? $ticks : 0,
        'localts'=>time(),'ts'=>time(),'people'=>synth_dialogue_people($context),
        'location'=>is_scalar($location) ? strval($location) : '','party'=>'',
        'utterance_id'=>$utterance,'delivery_state'=>$state];
}

// History rows use the existing native insertion path, so saved-ancestry scopes stay non-destructive.
function synth_dialogue_history_row($db, array $context, string $type, string $data, ?string $utterance, string $state): void
{
    $db->insert('eventlog',synth_dialogue_history_fields($context,$type,$data,$utterance,$state));
}

function synth_dialogue_record_utterance($db, SynthTurnOwner $owner, string $lineId, string $text): void
{
    $event=$owner->event;
    $db->withNativeRequestLock(static function($connection) use($event,$owner,$lineId,$text): void {
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.dialogue_utterances
            (session_id,request_id,line_id,turn_id,generation,context_sequence,speaker_identity_id,character_id,text,text_sha256)
            VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)",[$event['session_id'],$event['request_id'],$lineId,$event['turn_id'],
            $event['generation'],$event['context_sequence'],$owner->targetIdentityId,$owner->characterId,$text,hash('sha256',$text)]);
        if (!$result) throw new RuntimeException('Unable to record utterance');
    });
}

// Queues the line on the existing durable TTS job with the owner's explicit profile speech inputs.
// A nonportable plan or enqueue failure is terminal for the turn, never a silent text-only line.
function synth_dialogue_speech(SynthTurnOwner $owner, string $lineId, string $text): array
{
    $root=dirname(__DIR__);
    require_once __DIR__.'/synth_tts_plan.php';
    require_once __DIR__.'/synth_tts_jobs.php';
    require_once __DIR__.'/synth_tts_worker.php';
    $speaker=strval($owner->target['display_name']);
    $plan=synth_capture_tts_plan($root,$speaker,$text,'default',$text,$owner->speech);
    if (empty($plan['portable'])) throw new RuntimeException('speech_unavailable');
    $entry=['speaker'=>$speaker,'speaker_identity'=>$owner->target,'text'=>$plan['text'],'mood'=>'default',
        'cache_seed'=>$plan['cache_seed'],'cache_key'=>md5($plan['cache_seed']),'tts_plan'=>$plan,'utterance_id'=>$lineId];
    $id=synth_tts_enqueue_job($GLOBALS['db'],$entry,$root);
    synth_tts_launch_job($id,$root);
    return ['schema'=>'synth.speech.v1','utterance_id'=>$lineId,'cache_key'=>$entry['cache_key'],'status'=>'pending','deadline_ms'=>30000];
}

// Truthful terminal failure. Lines already sent stay generated (unheard unless receipted); the turn ends error.
function synth_dialogue_fail($db, string $detail): void
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED'])) { synth_protocol_emit_terminal_error('provider_failed',$detail); return; }
    synth_protocol_write_response_line('status',['code'=>'provider_failed','detail'=>$detail],'status');
    synth_protocol_write_response_line('response_end',['status'=>'error'],'end');
    $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED']=true;
    synth_protocol_complete_request($db,'failed','application/x-ndjson',strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? ''),'provider_failed');
}

// Owns context, provider, stream and speech. Once admitted there is no legacy fallback: failures are terminal.
function synth_dialogue_turn_run($db, SynthTurnOwner $owner): void
{
    $GLOBALS['SYNTH_DIALOGUE_NATIVE_TURN_ADMITTED']=true;
    // Read prior history before this turn's own input row is inserted.
    try { $messages=synth_dialogue_messages($owner,synth_dialogue_history_messages($db,$owner)); }
    catch (SYNTHProtocolCancelled $error) { throw $error; }
    catch (Throwable) { synth_dialogue_fail($db,'history_unavailable'); return; }
    synth_dialogue_history_row($db,$owner->context,'inputtext',strval($owner->context['player']['display_name'] ?? 'Player')
        .': '.trim(strval($owner->event['payload']['text'])).' (Talking to '.$owner->target['display_name'].')',null,'received');
    $buffer='';$count=0;$total=0;
    // Over-limit output stops provider consumption and ends the turn as an error; nothing is truncated.
    $emit=static function(string $text) use($db,$owner,&$count): void {
        synth_protocol_assert_request_active();
        if ($count>=SYNTH_DIALOGUE_MAX_LINES) throw new LengthException('provider_output_too_many_lines');
        if (mb_strlen($text)>SYNTH_DIALOGUE_MAX_LINE) throw new LengthException('provider_line_oversized');
        $suffix=synth_protocol_next_line_suffix('dialogue');
        $lineId=$owner->event['request_id'].':'.$suffix;
        synth_dialogue_record_utterance($db,$owner,$lineId,$text);
        try { $speech=synth_dialogue_speech($owner,$lineId,$text); }
        catch (SYNTHProtocolCancelled $error) { throw $error; }
        catch (Throwable) { throw new RuntimeException('speech_unavailable'); }
        $payload=['speaker'=>$owner->target,'native_turn'=>true,'text'=>$text,'speech'=>$speech];
        synth_protocol_assert_request_active();
        synth_protocol_ensure_response_started();
        synth_protocol_write_response_line('dialogue',$payload,$suffix,['utterance_id'=>$lineId]);
        $count++;
    };
    try {
        synth_dialogue_provider_for($owner->connector)->stream($messages,
            static function(string $delta) use(&$buffer,&$total,$emit): void {
                synth_protocol_assert_request_active();
                $total+=strlen($delta);
                if ($total>SYNTH_DIALOGUE_MAX_OUTPUT) throw new LengthException('provider_output_oversized');
                $buffer.=$delta;
                foreach (synth_dialogue_take_sentences($buffer,false) as $line) $emit($line);
                if (mb_strlen($buffer)>SYNTH_DIALOGUE_MAX_LINE) throw new LengthException('provider_line_oversized');
            });
        synth_protocol_assert_request_active();
        foreach (synth_dialogue_take_sentences($buffer,true) as $line) $emit($line);
    } catch (SYNTHProtocolCancelled $error) { throw $error; }
    catch (Throwable $error) {
        synth_dialogue_fail($db,in_array($error->getMessage(),['provider_unsupported','provider_stream_incomplete',
            'provider_stream_malformed','provider_output_oversized','provider_output_too_many_lines','provider_line_oversized',
            'speech_unavailable'],true) ? $error->getMessage() : 'provider_failed');
        return;
    }
    if ($count===0) { synth_protocol_emit_terminal_error('provider_empty','provider_empty'); return; }
    // Own completion: no legacy relationship/inline side effects run for this route.
    synth_protocol_assert_request_active();
    synth_protocol_write_response_line('response_end',['status'=>'complete'],'end');
    $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED']=true;
    synth_protocol_complete_request($db,'complete','application/x-ndjson',strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? ''));
}
