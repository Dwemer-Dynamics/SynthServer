<?php

// Player reading (protocol/v2 context.reading_event): the text of a book, note, magazine, holotape title or
// terminal entry the player just read, bounded by the client. It is copied game text, never an instruction.
// One history row per reading, written through the existing observation timeline; no table of its own.

require_once __DIR__.'/synth_protocol_state.php';

const SYNTH_READING_KINDS=['book','note','magazine','holotape','terminal'];
const SYNTH_READING_TEXT_LIMIT=4000;

// Strict shape; anything outside the closed schema is rejected before it can reach a prompt.
function synthValidateReadingEvent($value): array
{
    $expected=['event_id','kind','title','source','text_truncated','age_ms'];
    if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value),[...$expected,'text'])!==[]
        || array_diff($expected,array_keys($value))!==[]
        || !is_string($value['event_id']) || preg_match('/^reading:([1-9][0-9]{0,15})$/D',$value['event_id'],$serial)!==1
        || intval($serial[1])>9007199254740991 || !in_array($value['kind'],SYNTH_READING_KINDS,true)
        || !is_string($value['title']) || !mb_check_encoding($value['title'],'UTF-8') || trim($value['title'])===''
        || mb_strlen($value['title'],'UTF-8')>128 || preg_match('/[\x00-\x1F\x7F]/',$value['title'])===1
        || !is_array($value['source']) || array_is_list($value['source']) || count($value['source'])!==2
        || !is_string($value['source']['form_id'] ?? null) || preg_match('/^0x[0-9A-Fa-f]{8}$/D',$value['source']['form_id'])!==1
        || preg_match('/^0x(?:00000000|[Ff]{8})$/D',$value['source']['form_id'])===1
        || !is_string($value['source']['origin_plugin'] ?? null) || strlen($value['source']['origin_plugin'])>255
        || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#D',$value['source']['origin_plugin'])!==1
        || !is_bool($value['text_truncated'])
        || !is_int($value['age_ms']) || $value['age_ms']<0 || $value['age_ms']>9007199254740991)
        throw new DomainException('invalid_reading_event');
    if (array_key_exists('text',$value)) {
        if (!is_string($value['text']) || !mb_check_encoding($value['text'],'UTF-8') || $value['text']===''
            || mb_strlen($value['text'],'UTF-8')>SYNTH_READING_TEXT_LIMIT
            || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/',$value['text'])===1)
            throw new DomainException('invalid_reading_event');
    } elseif ($value['text_truncated']) {
        throw new DomainException('invalid_reading_event');
    }
    return $value;
}

// Runs inside context admission's session lock/transaction; a retry of the same event is idempotent.
function synthPersistReadingEvent($db,array $event): void
{
    $payload=$event['payload'];
    if (!isset($payload['reading_event'])) return;
    if (($event['protocol_version'] ?? 1)!==2 || ($event['runtime_variant'] ?? '')!=='flat'
        || !in_array('context.reading_events',$event['capabilities'] ?? [],true))
        throw new DomainException('reading_events_capability_required');
    $reading=synthValidateReadingEvent($payload['reading_event']);
    $player=array_intersect_key($payload['player'],array_flip(['form_id','origin_plugin','playthrough_id']));
    $input=$reading;unset($input['age_ms']);
    $owner=[$event['session_id'],$event['generation'],$payload['playthrough_id']];
    $result=$db->execQuery("SELECT (event_payload->>'party')::jsonb AS party FROM synth_meta.history_events
        WHERE session_id=$1 AND generation=$2 AND playthrough_id=$3 AND event_payload->>'type'='reading_event'
          AND (event_payload->>'party')::jsonb->>'event_id'=$4 LIMIT 1",[...$owner,$reading['event_id']]);
    if ($result===false) throw new RuntimeException('reading_event_history_read_failed');
    if ($row=pg_fetch_assoc($result)) {
        $previous=json_decode($row['party'],true,flags:JSON_THROW_ON_ERROR);
        if (($previous['player'] ?? null)!=$player || ($previous['input_event'] ?? null)!=$input)
            throw new DomainException('reading_event_conflict');
        return;
    }
    $name=str_replace('|',' ',trim($payload['player']['display_name']));
    // The title and text are quoted game content: the framing says so, and nothing here is executable.
    $data=$name.' read the '.$reading['kind'].' "'.$reading['title'].'".';
    if (array_key_exists('text',$reading)) {
        $data.=' It reads: "'.$reading['text'].'"'.($reading['text_truncated'] ? ' [text truncated]' : '');
    } else {
        $data.=' Only its title is known; no text was captured.';
    }
    $metadata=['schema'=>'synth.reading.event.v1','event_id'=>$reading['event_id'],'player'=>$player,
        'input_event'=>$input,'capture_age_ms'=>$reading['age_ms'],'snapshot_sequence'=>$payload['snapshot_sequence']];
    $gamets=intval($payload['world']['game_time_ticks'] ?? 0);
    synth_protocol_persist_observation_history($db,$event,'reading_event',$data,'|'.$name.'|',
        strval($payload['world']['location'] ?? ''),$metadata,$gamets>0 ? $gamets : intval($payload['snapshot_sequence']));
}
