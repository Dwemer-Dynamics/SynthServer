<?php

// First-observed vanilla NPC dialogue (protocol/v2 context.observed_dialogue). The client copies the
// game-selected speaker/INFO/text and grants canonical witnesses once, at first observation, inside
// the published scene. This file admits that evidence and writes one provenance-bound history row.
// Visibility is the canonical witness binding; names and later audiences never grant anything.

require_once dirname(__DIR__, 3).'/lib'.'/synth_protocol_state.php';

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
    $history=\Synth\Core\DbManager\Dao\HistoryEventDao::current($db);
    $previousInput=$history->observedDialogueInput(strval($owner[0]),intval($owner[1]),strval($owner[2]),strval($owner[3]));
    if ($previousInput===false) throw new RuntimeException('observed_dialogue_read_failed');
    if ($previousInput!==null) {
        // JSONB reorders object keys; compare canonical keys without coercing scalars.
        if (synth_protocol_sort_keys(json_decode($previousInput,true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_sort_keys($input))
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
    if (!$history->publishObservedDialogue(strval($event['session_id']),intval($event['generation']),intval($payload['snapshot_sequence']),strval($payload['playthrough_id']),
        strval($event['request_id']),$speakerName.': '.$observed['text'],intval($observed['witnesses']['game_time_ticks']),time(),
        strval($payload['world']['location'] ?? ''),$json($binding),$json(array_values(array_merge([$speakerId],$witnessIds)))))
        throw new RuntimeException('observed_dialogue_insert_failed');
}
