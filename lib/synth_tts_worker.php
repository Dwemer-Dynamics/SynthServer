<?php

require_once __DIR__.'/synth_tts_jobs.php';
require_once __DIR__.'/chat_helper_functions.php';
require_once __DIR__.'/speech_cache.php';

// The durable attempt owns provider work; the OS cache lock prevents duplicate synthesis across jobs.
function synth_tts_generate_job_audio(array $entry, string $root): bool
{
    $key=$entry['cache_key'];
    $directory=$root.'/data/tmp/npc_tts_jobs';
    if (!is_dir($directory) && !@mkdir($directory,0775,true) && !is_dir($directory)) return false;
    // A converter child must not retain the cache lock after its owning worker dies.
    $lock=@fopen($directory.'/'.$key.'.media-lock',PHP_OS_FAMILY==='Windows'?'c':'ce');
    if ($lock===false) return false;
    try {
        while (!flock($lock,LOCK_EX|LOCK_NB)) {
            synth_protocol_assert_request_active();
            usleep(50000);
        }
        synth_protocol_assert_request_active();
        $cache=$root.'/soundcache/'.$key.'.wav';
        if (!synth_speech_cache_valid($cache)) {
            $output=synth_call_planned_npc_tts($entry['tts_plan'],$entry['text'],$entry['mood'],$entry['cache_seed']);
            if (!$output) return false;
        }
        $metadata=array_intersect_key($entry,array_flip(['request_id','turn_id','generation','utterance_id',
            'speaker_identity_id','speaker_identity','speaker']));
        $metadata['job_id']=$GLOBALS['SYNTH_TTS_JOB_LEASE']['id'];
        return synth_register_npc_tts_media($key,$GLOBALS['db'],$metadata,$root,$metadata)!==null;
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
}

// Detached execution is only a wakeup: PostgreSQL admission, not process creation, guarantees recoverability.
function synth_tts_launch_job(int $id, string $root): bool
{
    if ($id<1) throw new InvalidArgumentException('Invalid speech job');
    $php=synth_npc_tts_php_binary();$worker=$root.'/service/tts_worker.php';
    $command=PHP_OS_FAMILY==='Windows'
        ? 'cmd /C start "" /B "'.str_replace('"','\\"',$php).'" "'.str_replace('"','\\"',$worker).'" '.$id.' >NUL 2>NUL'
        : 'nohup '.escapeshellarg($php).' '.escapeshellarg($worker).' '.$id.' >/dev/null 2>&1 </dev/null &';
    $process=@popen($command,'r');
    if (!is_resource($process)) return false;
    return pclose($process)===0;
}

// Polling reflects durable terminal state without publishing audio or modifying jobs/status files.
function synth_tts_resolve_job_status($db, array $status, ?string $root = null): array
{
    $id=intval($status['_job_id'] ?? 0);
    unset($status['_job_id']);
    if ($id<1 || ($status['status'] ?? '')!=='pending') return $status;
    // A later admission cannot hide reusable audio, but a file alone is not proof of completed publication.
    $media=synth_resolve_npc_tts_media($status['cache_key'],$root);
    if ($media!==null) {
        try {
            synth_media_require_ready($db,$media);
            $status['status']='ready';$status['media']=$media;
            return $status;
        } catch (SynthMediaException) {
            // Publication may still be between filesystem and database writes; retain durable job semantics.
        }
    }
    $row=$db->fetchOne("SELECT state,deadline_at>clock_timestamp() AS within_deadline FROM synth_meta.worker_jobs
        WHERE id=$id AND job_type='tts' AND payload->>'schema'='synth.tts.job.v1'
        AND cache_key=".$db->escapeLiteral($status['cache_key']));
    if (!$row || in_array($row['state'],['failed','cancelled','stale'],true) || !in_array($row['within_deadline'],[true,'t',1,'1'],true)) {
        // Status belongs to immutable audio, not the last writer: another live owner may still produce it.
        // Keep ordinary polling on the primary key; only failed owners need the active-job lookup.
        $other=$db->fetchOne("SELECT state,deadline_at>clock_timestamp() AS within_deadline FROM synth_meta.worker_jobs
            WHERE job_type='tts' AND payload->>'schema'='synth.tts.job.v1'
            AND state IN ('pending','running') AND deadline_at>clock_timestamp()
            AND cache_key=".$db->escapeLiteral($status['cache_key'])." LIMIT 1");
        if (!$other || !in_array($other['state'],['pending','running'],true)
            || !in_array($other['within_deadline'],[true,'t',1,'1'],true)) {
            $status['status']='failed';$status['error_code']='synthesis_failed';
        }
    }
    return $status;
}
