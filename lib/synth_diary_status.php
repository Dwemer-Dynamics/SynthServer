<?php

require_once __DIR__.'/synth_diary_state.php';
require_once __DIR__.'/response.php';

// Unfinished siblings keep the batch live; a terminal partial success still needs a fresh save checkpoint.
function synth_diary_aggregate_status(array $states,bool $partialSupported): string
{
    if (count($states)>18 || array_filter($states,static fn($state)=>!in_array($state,['queued','running','ready','failed','cancelled'],true))) {
        throw new LogicException('Invalid diary batch states.');
    }
    foreach (['running','queued'] as $pending) {
        if (in_array($pending,$states,true)) return 'diary_'.$pending;
    }
    $failed=in_array('failed',$states,true);$cancelled=in_array('cancelled',$states,true);
    if (in_array('ready',$states,true)) {
        if (!$failed && !$cancelled) return 'diary_ready';
        if ($partialSupported) return 'diary_partial';
    }
    if ($failed) return 'diary_failed';
    if ($cancelled) return 'diary_cancelled';
    return 'diary_disabled';
}

// Accept original and current admission wording while requiring exact selected-role accounting.
function synth_diary_admission_queued(array $ack,array $event): int
{
    if (!preg_match('/^Queued: ([0-9]+); disabled \((?:(?:role setting or )?no diary connector|(?:no existing profile, )?role policy, cooldown, pending result or no diary connector)\): ([0-9]+)\. Generation runs in the background\.$/D',
        $ack['payload']['detail'] ?? '',$counts)) throw new LogicException('Diary admission receipt invalid.');
    $queued=intval($counts[1]);$disabled=intval($counts[2]);
    if ($queued+$disabled!==count($event['payload']['actors'])+intval($event['payload']['include_narrator'])+intval($event['payload']['include_player'])
        || ($ack['payload']['code'] ?? '')!==($queued ? 'diary_queued' : 'diary_disabled')) {
        throw new LogicException('Diary admission receipt conflicts.');
    }
    return $queued;
}

// A status read never claims/replays admission, dispatches a provider or advances a save frontier.
function synth_diary_request_status($db): string
{
    return $db->withNativeRequestLock(static function($connection,array $event) use($db) {
        synth_diary_assert_owner($connection,$event,true);
        if (!synth_protocol_current_context($db,$event)) throw new LogicException('Diary context unavailable.');
        $result=@pg_query_params($connection,'SELECT response_body FROM synth_meta.request_executions WHERE session_id=$1 AND request_id=$2',
            [$event['session_id'],$event['request_id']]);
        if (!$result || !($row=pg_fetch_assoc($result))) throw new RuntimeException('Diary admission unavailable.');
        $lines=explode("\n",trim(synth_protocol_decode_bytea($row['response_body'])));
        $ack=count($lines)===3 ? json_decode($lines[1],true,flags:JSON_THROW_ON_ERROR) : null;
        // Admission already records these counts atomically. Do not infer disabled actors from missing jobs.
        if (!is_array($ack)) throw new LogicException('Diary admission receipt invalid.');
        $queued=synth_diary_admission_queued($ack,$event);
        $result=@pg_query_params($connection,"SELECT j.state,j.deadline_at<=clock_timestamp() AS expired,
                j.attempts>=3 AND (j.state='pending' OR j.lease_until<=clock_timestamp()) AS exhausted,
                j.lease_until>clock_timestamp() AS live_lease,i.id,i.actor_identity_id,i.diary_role,i.input_payload,i.input_sha256,
                i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex') AS valid_input,
                r.history_event_id,r.result_payload,
                r.result_sha256=encode(sha256(convert_to(r.result_payload::text,'UTF8')),'hex') AS valid_result,
                h.session_id=i.session_id AND h.request_id=i.request_id AND h.generation=i.generation
                    AND h.context_sequence=i.context_sequence AND h.playthrough_id=c.playthrough_id
                    AND h.event_payload->>'type'='diary_result' AND h.event_payload->>'delivery_state'='derived'
                    AND h.event_payload->'diary_result'=r.result_payload
                    AND h.event_payload->>'diary_result_sha256'=r.result_sha256
                    AND h.event_payload->>'diary_input_sha256'=i.input_sha256 AS valid_history
            FROM synth_meta.diary_inputs i JOIN synth_meta.worker_jobs j ON j.diary_input_id=i.id
                AND j.session_id=i.session_id AND j.request_id=i.request_id AND j.generation=i.generation
                AND j.actor_identity_id=i.actor_identity_id AND j.turn_id=$4 AND j.job_type='diary'
                AND j.payload->>'schema'='synth.diary.job.v1' AND j.payload->>'context_sequence'=i.context_sequence::text
            JOIN synth_meta.context_snapshots c ON c.session_id=i.session_id AND c.generation=i.generation
                AND c.snapshot_sequence=i.context_sequence
            LEFT JOIN synth_meta.diary_results r ON r.input_id=i.id
            LEFT JOIN synth_meta.history_events h ON h.id=r.history_event_id
            WHERE i.session_id=$1 AND i.request_id=$2 AND i.generation=$3 AND i.context_sequence=$5 AND i.diary_role IN ('npc','narrator','player')
                AND i.input_payload->>'actor_identity_id'=i.actor_identity_id::text
                AND i.input_payload->>'diary_role'=i.diary_role AND i.input_payload->'context'=c.context_payload
                AND i.input_payload->'prior_revision'=COALESCE(to_jsonb(i.prior_history_event_id),'null'::jsonb)
                AND i.input_payload->'profile_revision'=COALESCE(to_jsonb(i.profile_revision_id),'null'::jsonb)",
            [$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to read diary jobs.');
        $jobs=pg_fetch_all($result) ?: [];
        if (count($jobs)!==$queued) throw new LogicException('Diary job ownership incomplete.');
        $states=[];
        foreach ($jobs as $job) {
            $input=json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);
            if ($job['valid_input']!=='t' || synth_protocol_canonical_json($input['owner'])!==synth_protocol_canonical_json($event)) {
                throw new LogicException('Diary input integrity invalid.');
            }
            $role=$job['diary_role'];$playerOwned=$role!=='npc';
            synth_diary_input_connector($input);
            $binding=$playerOwned ? synth_diary_player_owned_binding($db,$event,$role) : synth_diary_npc_binding($db,$event,$input['binding']['identity']);
            if (synth_protocol_canonical_json($binding)!==synth_protocol_canonical_json($input['binding'])) {
                throw new LogicException('Diary actor binding changed.');
            }
            $state=$job['state'];
            if ($state==='ready') {
                if ($job['valid_result']!=='t' || $job['valid_history']!=='t') throw new LogicException('Diary result integrity invalid.');
                synth_diary_validate_result(json_decode($job['result_payload'],true,flags:JSON_THROW_ON_ERROR));
                if ((synth_diary_visible_role($db,$event,$binding['actor_identity_id'],$role)['revision'] ?? null)!==$input['prior_revision']
                    || ($role==='player' ? null : (synth_profile_visible($db,$event,$binding['actor_identity_id'],$role==='narrator')['revision'] ?? null))!==$input['profile_revision']) {
                    throw new LogicException('Diary saved evidence changed.');
                }
                $inputId=intval($job['id']);
                $sources=$db->fetchOne(synth_protocol_history_cte($db,$event,'',$playerOwned ? null : $binding['actor_identity_id'],$playerOwned)." SELECT count(*) AS n,
                    (SELECT count(*) FROM synth_meta.diary_input_sources WHERE input_id=$inputId) AS total
                    FROM synth_meta.diary_input_sources s JOIN native_history v ON v.history_event_id=s.history_event_id
                    JOIN synth_meta.history_events h ON h.id=s.history_event_id JOIN synth_meta.diary_inputs i ON i.id=s.input_id
                    WHERE s.input_id=$inputId AND s.source_sha256=encode(sha256(convert_to(h.event_payload::text,'UTF8')),'hex')
                        AND EXISTS(SELECT 1 FROM jsonb_array_elements(i.input_payload->'history') evidence
                            WHERE evidence->>'history_event_id'=s.history_event_id::text AND evidence->>'source_sha256'=s.source_sha256)");
                if (intval($sources['n'])!==count($input['history']) || intval($sources['total'])!==count($input['history'])) {
                    throw new LogicException('Diary source evidence unavailable.');
                }
            } elseif (in_array($state,['cancelled','stale'],true)) $state='cancelled';
            elseif ($state==='failed' || $job['expired']==='t' || $job['exhausted']==='t') $state='failed';
            elseif ($state==='pending' || ($state==='running' && $job['live_lease']!=='t')) $state='queued';
            elseif ($state!=='running') throw new LogicException('Diary job state invalid.');
            $states[]=$state;
        }
        return synth_protocol_encode_ack(synth_diary_aggregate_status($states,
            in_array('diary.batch.status',$event['capabilities'],true)));
    },2000);
}
