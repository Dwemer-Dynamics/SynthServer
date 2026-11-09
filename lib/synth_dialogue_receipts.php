<?php

// Delivery receipts for native-turn lines. Text and speaker come from the server record, never the request.
require_once __DIR__.'/synth_protocol_state.php';
require_once __DIR__.'/core/dialogue/UtteranceRepository.php';

const SYNTH_DIALOGUE_RECEIPT_STATES=['audio_completed'=>'spoken','text_presented'=>'presented',
    'interrupted'=>'interrupted','failed'=>'failed'];

// Returns the stored state. The first terminal outcome wins; exact repeats are idempotent and a
// different outcome is a conflict. Only spoken/presented lines enter conversation history.
function synth_dialogue_receipt_admit($db, array $event): string
{
    $payload=$event['payload'];
    $state=SYNTH_DIALOGUE_RECEIPT_STATES[$payload['outcome'] ?? ''] ?? null;
    $character=is_array($payload['character'] ?? null) ? $payload['character'] : [];
    $characterId=\Synth\Core\CharacterKey::of($character);
    if ($state===null || $characterId===null) throw new DomainException('receipt_invalid');
    $context=synth_protocol_original_context($db,$event);
    if ($context===null) throw new DomainException('receipt_owner_mismatch');
    return $db->withNativeRequestLock(static function($connection) use($db,$event,$payload,$state,$characterId,$character,$context): string {
        $result=@pg_query_params($connection,"SELECT u.state,u.character_id,u.text,a.full_form_id,a.origin_plugin,a.playthrough_id
            FROM synth_meta.dialogue_utterances u
            JOIN synth.actor_identities a ON a.id=u.speaker_identity_id
            JOIN synth_meta.runtime_sessions r ON r.session_id=u.session_id AND r.state='active' AND r.active_generation=u.generation
            WHERE u.session_id=$1 AND u.request_id=$2 AND u.line_id=$3 AND u.generation=$4 AND u.context_sequence=$5 AND u.turn_id=$6
            FOR UPDATE OF u",[$event['session_id'],$payload['dialogue_request_id'],$payload['line_id'],$event['generation'],
            $event['context_sequence'],$event['turn_id']]);
        if (!$result) throw new RuntimeException('Unable to read utterance');
        $row=pg_fetch_assoc($result);
        // Exact wire identity: same placed reference key and the same runtime binding as the emitted speaker.
        if (!$row || !hash_equals($row['character_id'],$characterId)
            || strcasecmp($row['full_form_id'],$character['form_id'])!==0
            || strcasecmp($row['origin_plugin'],$character['origin_plugin'])!==0
            || $row['playthrough_id']!==$character['playthrough_id']) throw new DomainException('receipt_owner_mismatch');
        if ($row['state']===$state) return $state;
        if ($row['state']!=='generated') throw new DomainException('receipt_conflict');
        $update=@pg_query_params($connection,"UPDATE synth_meta.dialogue_utterances SET state=$4,receipt_request_id=$5,
            receipt_at=NOW() WHERE session_id=$1 AND request_id=$2 AND line_id=$3 AND state='generated'",
            [$event['session_id'],$payload['dialogue_request_id'],$payload['line_id'],$state,$event['request_id']]);
        if (!$update || pg_affected_rows($update)!==1) throw new RuntimeException('Unable to record receipt');
        if (in_array($state,['spoken','presented'],true)) {
            $speaker=strval($context['target']['display_name'] ?? $character['display_name'] ?? '');
            synth_dialogue_heard_history_locked($connection,$event,$payload['dialogue_request_id'],\Synth\Core\UtteranceRepository::historyFields(
                $context,'chat',$speaker.': '.$row['text'].' (talking to '.strval($context['player']['display_name'] ?? 'Player').')',
                $payload['line_id'],$state));
        }
        return $state;
    });
}

// Same native insertion as sql::insert, but provenance is the original dialogue request (whose generation,
// context and turn the receipt owner already matched) rather than the receipt event. The history_events id
// is allocated now, inside the receipt transaction, so receipt time is the insertion frontier.
function synth_dialogue_heard_history_locked($connection, array $event, string $dialogueRequestId, array $data): void
{
    $data['synth_session_id']=$event['session_id'];
    $data['synth_generation']=$event['generation'];
    $data['synth_context_sequence']=$event['context_sequence'];
    $data['synth_request_id']=$dialogueRequestId;
    $columns=array_keys($data);
    $values=implode(',',array_map(static fn($i)=>'$'.($i+1),array_keys($columns)));
    $snapshot=@pg_query_params($connection,'SELECT playthrough_id FROM synth_meta.context_snapshots
        WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',[$event['session_id'],$event['generation'],$event['context_sequence']]);
    $snapshot=$snapshot ? pg_fetch_assoc($snapshot) : false;
    if (!$snapshot) throw new RuntimeException('Native history context unavailable');
    $result=@pg_query_params($connection,'WITH inserted AS (INSERT INTO synth.eventlog ('.implode(',',$columns).') VALUES ('.$values.') RETURNING *)
        INSERT INTO synth_meta.history_events
            (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload,scene_context_sequence)
        SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$'.(count($columns)+1).',rowid,to_jsonb(inserted),NULL
        FROM inserted RETURNING id',[...array_values($data),$snapshot['playthrough_id']]);
    if (!$result || pg_num_rows($result)!==1) throw new RuntimeException('Unable to persist heard history');
}
