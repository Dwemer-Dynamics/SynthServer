<?php

// First-observed vanilla NPC dialogue (protocol/v2 context.observed_dialogue). The client copies the
// game-selected speaker/INFO/text and grants canonical witnesses once, at first observation, inside
// the published scene. This file admits that evidence and writes one provenance-bound history row.
// Visibility is the canonical witness binding; names and later audiences never grant anything.

require_once __DIR__.'/synth_protocol_state.php';

const SYNTH_OBSERVED_DIALOGUE_POLICIES=['same_cell_speaker_radius_1000_v1'];

function synthObservedDialogueInteger($value,int $minimum): bool
{
    return is_int($value) && $value>=$minimum && $value<=9007199254740991;
}

function synthObservedDialogueReference($value): bool
{
    return is_array($value) && !array_is_list($value) && count($value)===2
        && is_string($value['form_id'] ?? null) && preg_match('/^0x[0-9A-Fa-f]{8}$/D',$value['form_id'])===1
        && preg_match('/^0x(?:00000000|[Ff]{8})$/D',$value['form_id'])!==1
        && is_string($value['origin_plugin'] ?? null) && strlen($value['origin_plugin'])<=255
        && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#D',$value['origin_plugin'])===1;
}

function synthObservedDialogueActor($value,string $playthrough): bool
{
    return is_array($value) && !array_is_list($value) && count($value)===4
        && synthObservedDialogueReference(array_intersect_key($value,array_flip(['form_id','origin_plugin'])))
        && preg_match('/^0x(?:00000014|[Ff]{2}[0-9A-Fa-f]{6})$/D',$value['form_id'])!==1
        && ($value['playthrough_id'] ?? null)===$playthrough
        && is_string($value['display_name'] ?? null) && trim($value['display_name'])!=='' && strlen($value['display_name'])<=255;
}

function synthObservedDialogueKey(array $actor): string
{
    return strtoupper($actor['form_id']).'|'.strtolower($actor['origin_plugin']).'|'.$actor['playthrough_id'].'|'.($actor['display_name'] ?? '');
}

// Strict shape plus binding to this exact context: no target, audience is the speaker then exactly the
// witnesses, and the witness scene/clock are the published world's. Anything else grants nothing.
function synthValidateObservedDialogue($value,array $payload): array
{
    $playthrough=strval($payload['playthrough_id'] ?? '');
    $expected=['observation_id','source','speaker','topic_info','text','witnesses'];
    $witnesses=is_array($value) ? ($value['witnesses'] ?? null) : null;
    $evidenceKeys=['basis','policy','frame','game_time_ticks','age_ms','scene','actors'];
    if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value),$expected)!==[] || count($value)!==count($expected)
        || !is_string($value['observation_id']) || preg_match('/^dialogue:([1-9][0-9]{0,15})$/D',$value['observation_id'],$serial)!==1
        || intval($serial[1])>9007199254740991 || $value['source']!=='flat_subtitle_selection'
        || !synthObservedDialogueActor($value['speaker'],$playthrough) || !synthObservedDialogueReference($value['topic_info'])
        || !is_string($value['text']) || trim($value['text'])==='' || !mb_check_encoding($value['text'],'UTF-8')
        || mb_strlen($value['text'],'UTF-8')>512 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/',$value['text'])===1
        || !is_array($witnesses) || array_is_list($witnesses) || array_diff(array_keys($witnesses),$evidenceKeys)!==[]
        || count($witnesses)!==count($evidenceKeys) || $witnesses['basis']!=='first_observed'
        || !in_array($witnesses['policy'],SYNTH_OBSERVED_DIALOGUE_POLICIES,true)
        || !synthObservedDialogueInteger($witnesses['frame'],1) || !synthObservedDialogueInteger($witnesses['game_time_ticks'],1)
        || !synthObservedDialogueInteger($witnesses['age_ms'],0)
        || !is_array($witnesses['scene']) || count($witnesses['scene'])!==2 || !array_key_exists('cell',$witnesses['scene'])
        || !array_key_exists('worldspace',$witnesses['scene'])
        || !synthObservedDialogueReference($witnesses['scene']['cell'])
        || ($witnesses['scene']['worldspace']!==null && !synthObservedDialogueReference($witnesses['scene']['worldspace']))
        || !is_array($witnesses['actors']) || !array_is_list($witnesses['actors']) || count($witnesses['actors'])>8)
        throw new DomainException('invalid_observed_dialogue');
    $keys=[synthObservedDialogueKey($value['speaker'])];
    foreach ($witnesses['actors'] as $actor) {
        if (!synthObservedDialogueActor($actor,$playthrough)) throw new DomainException('invalid_observed_dialogue');
        $keys[]=synthObservedDialogueKey($actor);
    }
    $published=array_map(fn($actor)=>is_array($actor) ? synthObservedDialogueKey($actor+['display_name'=>'']) : '',$payload['audience'] ?? []);
    $speakerForms=array_map(fn($key)=>strstr($key,'|',true),$keys);
    if (count(array_unique($speakerForms))!==count($speakerForms) || array_key_exists('target',$payload) || $published!==$keys
        || !is_array($payload['world'] ?? null) || !is_array($payload['world']['scene'] ?? null)
        || synth_protocol_sort_keys($payload['world']['scene'])!==synth_protocol_sort_keys($witnesses['scene'])
        || ($payload['world']['game_time_ticks'] ?? null)!==$witnesses['game_time_ticks'])
        throw new DomainException('observed_dialogue_scene_unbound');
    return $value;
}

// Context admission holds the session transaction lock. One row per observation; a retry is idempotent.
function synthPersistObservedDialogue($db,array $event): void
{
    $payload=$event['payload'];
    if (!isset($payload['observed_dialogue'])) return;
    if (($event['protocol_version'] ?? 1)!==2 || ($event['runtime_variant'] ?? '')!=='flat'
        || !in_array('context.observed_dialogue',$event['capabilities'] ?? [],true))
        throw new DomainException('observed_dialogue_capability_required');
    $observed=synthValidateObservedDialogue($payload['observed_dialogue'],$payload);
    $input=$observed;unset($input['witnesses']['age_ms']);
    $owner=[$event['session_id'],$event['generation'],$payload['playthrough_id'],$observed['observation_id']];
    $result=$db->execQuery("SELECT h.event_payload->'synth_witness_observation'->'input' AS input
        FROM synth_meta.history_events h JOIN synth_meta.runtime_sessions s ON s.session_id=h.session_id
        WHERE h.session_id=$1 AND h.generation=$2 AND h.playthrough_id=$3
          AND h.event_payload->'synth_witness_observation'->>'observation_id'=$4
          AND s.active_generation=h.generation AND s.state='active' LIMIT 1",$owner);
    if ($result===false) throw new RuntimeException('observed_dialogue_read_failed');
    if ($row=pg_fetch_assoc($result)) {
        // JSONB reorders object keys; compare canonical keys without coercing scalars.
        if (synth_protocol_sort_keys(json_decode($row['input'],true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_sort_keys($input))
            throw new DomainException('observed_dialogue_conflict');
        return; // The original row keeps its first-observation scene; the retry's later context grants nothing.
    }
    $speakerId=synth_protocol_actor_identity_id($db,$observed['speaker']);
    $witnessIds=[];
    foreach ($observed['witnesses']['actors'] as $actor) $witnessIds[]=synth_protocol_actor_identity_id($db,$actor);
    if (in_array($speakerId,$witnessIds,true) || count(array_unique($witnessIds))!==count($witnessIds))
        throw new DomainException('observed_dialogue_identity_ambiguous');
    $binding=['schema'=>'synth.dialogue.observed.v1','observation_id'=>$observed['observation_id'],
        'basis'=>'first_observed','policy'=>$observed['witnesses']['policy'],'speaker_identity_id'=>$speakerId,
        'witness_identity_ids'=>$witnessIds,'snapshot_sequence'=>$payload['snapshot_sequence'],'input'=>$input,
        'capture_age_ms'=>$observed['witnesses']['age_ms']];
    $json=fn($value)=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $speakerName=str_replace('|',' ',trim($observed['speaker']['display_name']));
    // `people` stays empty: legacy name readers must not turn this row into a name-matched memory.
    $result=$db->execQuery("WITH captured AS (
        SELECT c.session_id,c.generation,c.snapshot_sequence,c.playthrough_id
          FROM synth_meta.context_snapshots c JOIN synth_meta.runtime_sessions s USING(session_id)
         WHERE c.session_id=$1 AND c.generation=$2 AND c.snapshot_sequence=$3 AND c.playthrough_id=$4
           AND s.active_generation=c.generation AND s.state='active'
    ), publication AS (
        INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
            synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
        SELECT 'backgroundchat',$6,playthrough_id,$7,$8,$8,'',$9,$10,'received',
            session_id,generation,snapshot_sequence,$5 FROM captured RETURNING *
    ) INSERT INTO synth_meta.history_events
        (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
        SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,sess,rowid,
            to_jsonb(publication)||jsonb_build_object('synth_witness_observation',$10::jsonb,'synth_dialogue_hearer_ids',$11::jsonb)
          FROM publication RETURNING id",
        [$event['session_id'],$event['generation'],$payload['snapshot_sequence'],$payload['playthrough_id'],$event['request_id'],
         $speakerName.': '.$observed['text'],$observed['witnesses']['game_time_ticks'],time(),
         strval($payload['world']['location'] ?? ''),$json($binding),$json(array_values(array_merge([$speakerId],$witnessIds)))]);
    if ($result===false || pg_fetch_assoc($result)===false) throw new RuntimeException('observed_dialogue_insert_failed');
}
