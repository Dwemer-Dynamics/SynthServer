<?php

require_once __DIR__.'/synth_diary_state.php';
require_once dirname(__DIR__, 3).'/lib/response.php';

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
        $row=synth_diary_dao(static fn()=>\Synth\Core\DbManager\Dao\RequestExecutionDao::current($db)->responseBody(
            strval($event['session_id']),strval($event['request_id'])),'Diary admission unavailable.');
        if (!$row) throw new RuntimeException('Diary admission unavailable.');
        $lines=explode("\n",trim(synth_protocol_decode_bytea($row['response_body'])));
        $ack=count($lines)===3 ? json_decode($lines[1],true,flags:JSON_THROW_ON_ERROR) : null;
        // Admission already records these counts atomically. Do not infer disabled actors from missing jobs.
        if (!is_array($ack)) throw new LogicException('Diary admission receipt invalid.');
        $queued=synth_diary_admission_queued($ack,$event);
        $jobs=synth_diary_dao(static fn()=>\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->diaryStatusRows(
            strval($event['session_id']),strval($event['request_id']),intval($event['generation']),strval($event['turn_id']),intval($event['context_sequence'])),
            'Unable to read diary jobs.');
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
                $sources=\Synth\Core\DbManager\Dao\DiaryInputSourceDao::current($db)->visibleSourceCounts(
                    synth_protocol_history_cte($db,$event,'',$playerOwned ? null : $binding['actor_identity_id'],$playerOwned),$inputId);
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
