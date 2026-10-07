<?php

require_once __DIR__.'/request.php';
require_once __DIR__.'/response.php';
require_once __DIR__.'/synth_tts_plan.php';

// Credential-bearing snapshots live outside the document root, with one private directory per server.
function synth_tts_job_input_path(string $root, string $id, bool $create = false): string
{
    $server=realpath($root);
    if ($server===false || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new LogicException('Invalid speech input identity');
    $directory=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/synth-tts-'.substr(hash('sha256',$server),0,24);
    if ($create && !is_dir($directory) && !@mkdir($directory,0700) && !is_dir($directory)) {
        throw new RuntimeException('Private speech storage unavailable');
    }
    if (!is_dir($directory) || is_link($directory)
        || (PHP_OS_FAMILY!=='Windows' && ((fileperms($directory)&0077)!==0
            || (function_exists('posix_geteuid') && fileowner($directory)!==posix_geteuid())))) {
        throw new RuntimeException('Private speech storage is not isolated');
    }
    return $directory.'/'.$id.'.json';
}

// Validate the frozen speech role against this exact ingress snapshot, never a display-name registry.
function synth_tts_job_validate_entry($db, array $entry, array $event, string $root): ?int
{
    $plan=$entry['tts_plan'] ?? null;
    if (($event['protocol_version'] ?? null)!==2 || !synth_protocol_owns_dialogue_turn($event)
        || !is_array($plan) || empty($plan['portable'])
        || !in_array($plan['inputs']['TTSFUNCTION'] ?? '',['xtts-fastapi','chatterbox','pockettts','cartesia','inworld'],true)
        || !is_string($entry['speaker'] ?? null) || !is_string($entry['cache_seed'] ?? null)
        || ($entry['cache_key'] ?? null)!==md5($entry['cache_seed'])
        || ($entry['text'] ?? null)!==($plan['text'] ?? null) || ($entry['mood'] ?? null)!==($plan['mood'] ?? null)
        || !is_string($entry['utterance_id'] ?? null)
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$entry['utterance_id'])) {
        throw new LogicException('Invalid durable speech input');
    }
    synth_validate_tts_plan($plan,$entry['cache_seed'],$entry['speaker'],$root);
    $identity=synth_protocol_response_speaker($entry['speaker'],$plan['inputs']['SYNTH_SPEECH_ROLE'] ?? 'npc');
    if ($identity===null || synth_protocol_canonical_json($identity)!==synth_protocol_canonical_json($entry['speaker_identity'] ?? null)) {
        throw new LogicException('Durable speech actor mismatch');
    }
    return isset($identity['kind']) ? null : synth_protocol_actor_identity_id($db,$identity);
}

// Admission is idempotent per utterance and stores only an opaque input reference/hash in general job metadata.
function synth_tts_enqueue_job($db, array $entry, string $root): int
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) throw new LogicException('Speech admission requires an owner');
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
    synth_protocol_assert_request_active();
    $actor=synth_tts_job_validate_entry($db,$entry,$event,$root);
    $input=array_intersect_key($entry,array_flip(['speaker','text','mood','cache_seed','cache_key','tts_plan','speaker_identity','utterance_id']));
    $input['protocol_event']=$event;
    $input['request_id']=$event['request_id'];$input['turn_id']=$event['turn_id'];$input['generation']=$event['generation'];
    $input['speaker_identity_id']=$actor;
    $bytes=json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (strlen($bytes)>1048576) throw new LengthException('Speech input exceeds its bound');
    $hash=hash('sha256',$bytes);$id=bin2hex(random_bytes(16));
    $path=synth_tts_job_input_path($root,$id,true);
    $file=@fopen($path,'x');$keep=false;
    if ($file===false) throw new RuntimeException('Unable to create private speech input');
    try {
        if (!@chmod($path,0600) || fwrite($file,$bytes)!==strlen($bytes) || !fflush($file)) {
            throw new RuntimeException('Unable to persist private speech input');
        }
        fclose($file);$file=null;
        return $db->withNativeRequestLock(static function($connection) use($db,$event,$input,$hash,$id,$actor,$root,&$keep): int {
            $origin=@pg_query_params($connection,"SELECT 1 FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e
                USING(session_id,request_id,generation,turn_id) WHERE p.session_id=$1 AND p.request_id=$2 AND p.generation=$3
                AND p.event_sha256=e.request_sha256 AND p.event_sha256=$4",[$event['session_id'],$event['request_id'],$event['generation'],
                    hash('sha256',synth_protocol_canonical_json($event))]);
            if (!$origin || !pg_fetch_assoc($origin)) throw new LogicException('Speech ingress mismatch');
            $prior=@pg_query_params($connection,"SELECT id,payload->>'input_sha256' AS input_sha256 FROM synth_meta.worker_jobs
                WHERE job_type='tts' AND payload->>'schema'='synth.tts.job.v1' AND session_id=$1 AND request_id=$2
                    AND generation=$3 AND utterance_id=$4 ORDER BY id LIMIT 1",[$event['session_id'],$event['request_id'],$event['generation'],$input['utterance_id']]);
            if (!$prior) throw new RuntimeException('Unable to check speech admission');
            if ($row=pg_fetch_assoc($prior)) {
                if (!hash_equals($hash,$row['input_sha256'])) throw new LogicException('Speech utterance input changed');
                return intval($row['id']);
            }
            $payload=json_encode(['schema'=>'synth.tts.job.v1','context_sequence'=>$event['context_sequence'],
                'input_id'=>$id,'input_sha256'=>$hash],JSON_THROW_ON_ERROR);
            $insert=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs
                (job_type,session_id,request_id,turn_id,generation,actor_identity_id,utterance_id,cache_key,payload,deadline_at)
                VALUES('tts',$1,$2,$3,$4,$5,$6,$7,$8::jsonb,clock_timestamp()+INTERVAL '30 seconds') RETURNING id",
                [$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],$actor,$input['utterance_id'],$input['cache_key'],$payload]);
            if (!$insert || !($row=pg_fetch_assoc($insert))) throw new RuntimeException('Unable to admit speech job');
            // Publish pending before the admission transaction exposes work to the dispatcher.
            // Re-admission of an existing utterance must never downgrade already-ready status.
            if (!synth_write_npc_tts_status($input['cache_key'],'pending',['job_id'=>intval($row['id'])],$root)) {
                throw new RuntimeException('Unable to publish admitted speech status');
            }
            // Retain the input even if an outer transaction later fails; no recovery ever trusts an unregistered file.
            $keep=true;
            return intval($row['id']);
        },2000);
    } finally {
        if (is_resource($file)) fclose($file);
        if (!$keep) @unlink($path);
    }
}

// Every provider wait and publication checks the exact attempt token; only a live token may renew itself.
function synth_tts_assert_job_lease($db, array $lease): void
{
    $id=intval($lease['id'] ?? 0);$token=$db->escapeLiteral(strval($lease['token'] ?? ''));
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
    $row=$db->fetchOne("SELECT 1 FROM synth_meta.worker_jobs WHERE id=$id AND job_type='tts'
        AND payload->>'schema'='synth.tts.job.v1' AND state='running' AND lease_token=$token
        AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp()
        AND session_id=".$db->escapeLiteral($event['session_id'])." AND request_id=".$db->escapeLiteral($event['request_id'])
        .' AND generation='.intval($event['generation']).' AND turn_id='.$db->escapeLiteral($event['turn_id'])
        ." AND payload->>'context_sequence'=".$db->escapeLiteral(strval($event['context_sequence'])));
    if (!$row) throw new SYNTHProtocolCancelled('speech_lease_lost');
    if (microtime(true)-($lease['heartbeat_at'] ?? 0)>=1) {
        $renewed=$db->execQuery("UPDATE synth_meta.worker_jobs SET lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '5 seconds'),
            updated_at=clock_timestamp() WHERE id=$id AND state='running' AND lease_token=$token
            AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp()");
        if ($renewed===false || pg_affected_rows($renewed)!==1) throw new SYNTHProtocolCancelled('speech_lease_lost');
        $GLOBALS['SYNTH_TTS_JOB_LEASE']['heartbeat_at']=microtime(true);
    }
}

// Bind verified ingress and immutable input, claim a bounded attempt, then generate without a database lock.
function synth_tts_run_job($db, int $jobId, string $root, callable $generate): bool
{
    if (!$db->isTransactionIdle()) throw new LogicException('Speech worker requires an idle database connection');
    $job=$db->fetchOne("SELECT j.*,p.canonical_event,p.event_sha256 FROM synth_meta.worker_jobs j
        JOIN synth_meta.request_executions e USING(session_id,request_id,generation,turn_id)
        JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_sha256=e.request_sha256
        WHERE j.id=".intval($jobId)." AND j.job_type='tts' AND j.payload->>'schema'='synth.tts.job.v1'
            AND j.state IN ('pending','running')");
    if (!$job) return false;
    $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_TTS_JOB_LEASE'];
    $saved=array_intersect_key($GLOBALS,array_flip($keys));$token=null;
    try {
        $event=synth_protocol_decode_json($job['canonical_event']);
        $payload=synth_protocol_decode_json($job['payload']);
        if (!is_array($event) || !is_array($payload)
            || !hash_equals($job['event_sha256'],hash('sha256',synth_protocol_canonical_json($event)))
            || ($event['context_sequence'] ?? null)!==($payload['context_sequence'] ?? null)) throw new LogicException('Speech job ingress mismatch');
        $GLOBALS['db']=$db;$GLOBALS['SYNTH_REQUEST_EVENT']=synth_normalize_json_event($event);
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        unset($GLOBALS['SYNTH_TTS_JOB_LEASE']);
        synth_protocol_assert_request_active();
        $path=synth_tts_job_input_path($root,strval($payload['input_id'] ?? ''));
        $size=@filesize($path);
        if (is_link($path) || !is_int($size) || $size<1 || $size>1048576
            || (PHP_OS_FAMILY!=='Windows' && (fileperms($path)&0077)!==0)) throw new LogicException('Speech input unavailable');
        $bytes=file_get_contents($path);
        if (!hash_equals(strval($payload['input_sha256'] ?? ''),hash('sha256',$bytes))) throw new LogicException('Speech input changed');
        $input=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
        if (synth_protocol_canonical_json($input['protocol_event'] ?? null)!==synth_protocol_canonical_json($event)
            || ($input['cache_key'] ?? null)!==$job['cache_key'] || ($input['utterance_id'] ?? null)!==$job['utterance_id']) {
            throw new LogicException('Speech input owner mismatch');
        }
        $actor=synth_tts_job_validate_entry($db,$input,$event,$root);
        if ($actor!==($job['actor_identity_id']===null ? null : intval($job['actor_identity_id']))) throw new LogicException('Speech job actor mismatch');
        $token=$db->withNativeRequestLock(static function($connection) use($jobId): ?string {
            $claimed=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='running',attempts=attempts+1,
                lease_token=$2,lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '5 seconds'),updated_at=clock_timestamp()
                WHERE id=$1 AND attempts<3 AND available_at<=clock_timestamp() AND deadline_at>clock_timestamp()
                    AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) RETURNING lease_token",
                [$jobId,bin2hex(random_bytes(16))]);
            if (!$claimed) throw new RuntimeException('Unable to claim speech attempt');
            return pg_fetch_assoc($claimed)['lease_token'] ?? null;
        },2000);
        if ($token===null) return false;
        $GLOBALS['SYNTH_TTS_JOB_LEASE']=['id'=>$jobId,'token'=>$token,'heartbeat_at'=>microtime(true)];
        if (!$generate($input)) throw new RuntimeException('Speech generation failed');
        return $db->withNativeRequestLock(static function($connection) use($db,$jobId,$token,$input,$root): bool {
            $status=synth_read_npc_tts_status($input['cache_key'],$root);
            if (($status['status'] ?? '')!=='ready' || !is_array($status['media'] ?? null)) throw new RuntimeException('Speech media unavailable');
            synth_media_require_ready($db,$status['media']);
            $done=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='ready',lease_token=NULL,
                lease_until=NULL,last_error=NULL,updated_at=clock_timestamp() WHERE id=$1 AND state='running' AND lease_token=$2
                AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp()",[$jobId,$token]);
            if (!$done || pg_affected_rows($done)!==1) throw new RuntimeException('Unable to finish speech attempt');
            return true;
        },2000);
    } catch (Throwable $error) {
        if ($token!==null) {
            $ownerCancelled=$error instanceof SYNTHProtocolCancelled && $error->getMessage()!=='speech_lease_lost';
            $terminal=$ownerCancelled || $error instanceof LogicException || $error instanceof JsonException;
            $state=$ownerCancelled ? 'cancelled' : 'failed';
            $result=$db->execQuery("UPDATE synth_meta.worker_jobs SET state=CASE WHEN ".($terminal?'TRUE':'FALSE')."
                OR attempts>=3 OR deadline_at<=clock_timestamp() THEN '$state' ELSE 'pending' END,
                lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '1 second',
                last_error='attempt_failed',updated_at=clock_timestamp() WHERE id=".intval($jobId)
                ." AND state='running' AND lease_token=".$db->escapeLiteral($token));
            if ($result===false) throw new RuntimeException('Unable to record speech failure');
        } elseif ($error instanceof SYNTHProtocolCancelled || $error instanceof LogicException || $error instanceof JsonException) {
            // Invalid queued input is terminal, but a competing live attempt keeps its state/token.
            $state=$error instanceof SYNTHProtocolCancelled ? 'cancelled' : 'failed';
            if ($db->execQuery("UPDATE synth_meta.worker_jobs SET state='$state',lease_token=NULL,lease_until=NULL,
                last_error='input_or_owner_invalid',updated_at=clock_timestamp() WHERE id=".intval($jobId)
                ." AND job_type='tts' AND payload->>'schema'='synth.tts.job.v1'
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))")===false) {
                throw new RuntimeException('Unable to retire invalid speech input');
            }
        }
        throw $error;
    } finally {
        foreach($keys as $key) {
            unset($GLOBALS[$key]);
            if(array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key];
        }
    }
}

// A scheduler selects only bounded, attributable current work; legacy/unowned jobs are never adopted.
function synth_tts_due_jobs($db): array
{
    if (!$db->isTransactionIdle()) throw new LogicException('Speech dispatch requires an idle database connection');
    if ($db->execQuery("UPDATE synth_meta.worker_jobs j SET state=CASE
            WHEN deadline_at<=clock_timestamp() OR attempts>=3 THEN 'failed' ELSE 'cancelled' END,
            lease_token=NULL,lease_until=NULL,last_error='owner_or_attempt_expired',updated_at=clock_timestamp()
        WHERE job_type='tts' AND payload->>'schema'='synth.tts.job.v1' AND state IN ('pending','running')
            AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND (state='pending' OR lease_until<=clock_timestamp()))
                OR NOT EXISTS(SELECT 1 FROM synth_meta.runtime_sessions r JOIN synth_meta.request_executions e USING(session_id)
                    WHERE r.session_id=j.session_id AND r.state='active' AND r.active_generation=j.generation
                        AND r.active_turn_id=j.turn_id AND r.active_turn_sequence::text=j.payload->>'context_sequence'
                        AND e.request_id=j.request_id AND e.generation=j.generation AND e.turn_id=j.turn_id
                        AND e.state IN ('claimed','streaming','complete')))")===false) throw new RuntimeException('Unable to retire expired speech work');
    return array_map(static fn(array $row): int=>intval($row['id']),$db->fetchAll("SELECT id FROM synth_meta.worker_jobs
        WHERE job_type='tts' AND payload->>'schema'='synth.tts.job.v1' AND available_at<=clock_timestamp()
            AND deadline_at>clock_timestamp() AND attempts<3
            AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) ORDER BY available_at,id LIMIT 5"));
}

// Retire only hash-matching private inputs belonging to terminal native jobs; preserve altered files for inspection.
function synth_tts_cleanup_job_inputs($db, string $root): int
{
    $removed=0;
    foreach($db->fetchAll("SELECT id,payload FROM synth_meta.worker_jobs WHERE job_type='tts'
        AND payload->>'schema'='synth.tts.job.v1' AND state IN ('ready','failed','cancelled','stale')
        AND updated_at<clock_timestamp()-INTERVAL '10 seconds' AND NOT(payload ? 'input_cleanup_state') ORDER BY id LIMIT 20") as $row) {
        $payload=synth_protocol_decode_json($row['payload']);
        $path=synth_tts_job_input_path($root,strval($payload['input_id'] ?? ''));
        $state='missing';
        if (file_exists($path) || is_link($path)) {
            $state='changed';
            if (!is_link($path) && is_file($path) && hash_equals(strval($payload['input_sha256'] ?? ''),strval(@hash_file('sha256',$path)))) {
                if (!@unlink($path)) throw new RuntimeException('Unable to retire private speech input');
                $state='removed';++$removed;
            }
        }
        if ($db->execQuery("UPDATE synth_meta.worker_jobs SET payload=payload || jsonb_build_object('input_cleanup_state',"
            .$db->escapeLiteral($state)."::text) WHERE id=".intval($row['id']))===false) throw new RuntimeException('Unable to record speech input cleanup');
    }
    return $removed;
}
