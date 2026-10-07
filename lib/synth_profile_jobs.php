<?php

require_once __DIR__.'/synth_profile_state.php';

// Only authoritative ownership failures are terminal; transient database/provider failures remain retryable.
function synth_profile_origin_rejected(Throwable $error): bool
{
    return $error instanceof SYNTHProtocolCancelled || in_array($error->getMessage(),[
        'Native profile queue has no valid owner.','Native profile queue origin mismatch.',
        'Native profile link changed after queue admission.','profile_refresh_context_unavailable',
        'profile_refresh_actor_not_in_snapshot','Native profile target link changed.'],true);
}

// Resolve every selected prompt before the first model call; retries never re-read prompt configuration.
function synth_profile_prepare_fields(string $name, array $fields, string $history): array
{
    if (!isset($GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$name]) || !$fields
        || array_diff($fields,array_keys(synth_profile_fields([])))) throw new LogicException('Invalid native profile field selection.');
    $fields=array_values(array_unique($fields));
    foreach ($fields as $field) if (updateDynamicProfileField($name,$field,$history,true)!==true) {
        throw new RuntimeException('Native profile prompt preparation failed.');
    }
    $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$name]['fields']=$fields;
    return $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$name];
}

// Transfer a batch atomically: deletion means durable per-actor jobs exist, never merely that work was attempted.
function synth_profile_enqueue_batch($db, string $queueId, array $queue): void
{
    synth_profile_with_origin($db,$queue,static function() use($db,$queueId,$queue) {
        $db->withNativeRequestLock(static function($connection,array $event) use($db,$queueId,$queue) {
            foreach ($queue['npcs'] as $name) {
                synth_profile_assert_write_target($db,$name);
                $binding=$queue['native_bindings'][$name];
                $narrator=$name==='The Narrator';
                $actor=$narrator ? intval($db->fetchOne('SELECT player_identity_id FROM synth_meta.context_snapshots WHERE session_id='
                    .$db->escapeLiteral($event['session_id']).' AND generation='.intval($event['generation'])
                    .' AND snapshot_sequence='.intval($event['context_sequence']))['player_identity_id'] ?? 0)
                    : $queue['native_bindings'][$name]['actor_identity_id'];
                if ($actor<1) throw new LogicException('Native profile job has no actor.');
                // Each durable job has one actor: its frozen label can safely address the legacy prompt facade.
                $label=$narrator ? 'The Narrator' : $binding['identity']['display_name'];
                $payload=['schema'=>'synth.profile.job.v1','context_sequence'=>$event['context_sequence'],
                    'profile_role'=>$narrator ? 'narrator' : 'npc','native_owner'=>$event,'npcs'=>[$label],
                    'native_bindings'=>[$label=>$binding],'gameRequest'=>$queue['gameRequest']];
                $result=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs
                    (job_type,session_id,request_id,turn_id,generation,actor_identity_id,payload,deadline_at)
                    VALUES('profile',$1,$2,$3,$4,$5,$6::jsonb,clock_timestamp()+INTERVAL '30 minutes')
                    ON CONFLICT DO NOTHING",[$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],
                        $actor,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                if (!$result) throw new RuntimeException('Unable to persist native profile job.');
            }
            if (!@pg_query_params($connection,'DELETE FROM synth.conf_opts WHERE id=$1',[$queueId])) throw new RuntimeException('Unable to finish profile queue transfer.');
        },2000);
    });
}

// Structural validation happens before persistence and again on recovery, without storing connector credentials.
function synth_profile_validate_input(array $input, array $event, int $actor, string $role): void
{
    if (($input['schema'] ?? '')!=='synth.profile.input.v1' || ($input['actor_identity_id'] ?? null)!==$actor
        || ($input['profile_role'] ?? '')!==$role || !is_array($input['owner'] ?? null)
        || synth_protocol_canonical_json($input['owner'])!==synth_protocol_canonical_json($event)
        || !is_string($input['history'] ?? null) || !is_array($input['profile'] ?? null)
        || !is_array($input['baseline_fields'] ?? null) || !array_key_exists('prior_revision',$input)
        || !is_int($input['connector_id'] ?? null) || $input['connector_id']<1
        || !is_array($input['fields'] ?? null) || !array_is_list($input['fields']) || !$input['fields']
        || count($input['fields'])>5 || count(array_unique($input['fields']))!==count($input['fields'])
        || array_diff($input['fields'],array_keys(synth_profile_fields([])))) throw new LogicException('Invalid frozen profile input.');
    synth_profile_fields($input['profile']);synth_profile_fields($input['baseline_fields']);
    foreach ($input['fields'] as $field) {
        $prompt=$input['prompts'][$field] ?? null;$options=$input['options'][$field] ?? null;
        if (!is_string($prompt) || !is_array($options) || !is_int($options['max_tokens'] ?? null) || $options['max_tokens']<1) {
            throw new LogicException('Invalid frozen profile prompt.');
        }
        $messages=json_decode($prompt,true,flags:JSON_THROW_ON_ERROR);
        if (!is_array($messages) || !array_is_list($messages) || !$messages || count($messages)>8) throw new LogicException('Invalid frozen profile messages.');
        foreach ($messages as $message) if (!is_array($message) || !in_array($message['role'] ?? '',['system','user','assistant'],true)
            || !is_string($message['content'] ?? null) || str_contains($message['content'],"\0")) throw new LogicException('Invalid frozen profile message.');
    }
    if (strlen(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>1048576) throw new LengthException('Frozen profile input exceeds its bound.');
}

// Use the frozen messages and token options, with live credentials from exactly the selected connector ID.
function synth_profile_generate_field($db, array $input, string $field): string
{
    if (!$db->isTransactionIdle()) throw new LogicException('Profile provider requires an idle database connection.');
    synth_protocol_assert_request_active();
    require_once __DIR__.'/core/llm_connector.class.php';
    $connector=new LLMConnector();$settings=$connector->getById($input['connector_id']);
    if (!$settings) throw new RuntimeException('Native profile connector unavailable.');
    $GLOBALS['SYNTH_CORE_CURRENT_CONNECTOR_DATA']=$settings;$connector->setOldGlobals($settings);
    $GLOBALS['CURRENT_CONNECTOR']=$settings['driver'];
    $GLOBALS['FORCE_MAX_TOKENS']=$input['options'][$field]['max_tokens'];
    $GLOBALS['COMMAND_PROMPT']='';$GLOBALS['BASE_FUNCTIONS']=[];$GLOBALS['SYNTH_CLIENT_CAPABILITIES']=[];
    $GLOBALS['DIRECT_NARRATOR_DIALOGUE']=false;
    $handler=$connector->getConnector($settings);
    $text=$handler->fast_request(json_decode($input['prompts'][$field],true,flags:JSON_THROW_ON_ERROR),$input['options'][$field],'profile');
    if (!is_string($text)) throw new RuntimeException('Native profile provider returned no text.');
    return $text;
}

// Claim/recover a fenced actor attempt, checkpoint each field, then publish the complete selected set atomically.
function synth_profile_run_job($db, int $jobId, ?callable $prepare=null, ?callable $generate=null): bool
{
    if (!$db->isTransactionIdle()) throw new LogicException('Profile worker requires an idle database connection.');
    $job=$db->fetchOne("SELECT * FROM synth_meta.worker_jobs WHERE id=".intval($jobId)
        ." AND job_type='profile' AND payload->>'schema'='synth.profile.job.v1' AND state IN ('pending','running')");
    if (!$job) return false;
    $queue=json_decode($job['payload'],true,flags:JSON_THROW_ON_ERROR);$done=false;
    synth_profile_with_origin($db,$queue,static function() use($db,$job,$jobId,$queue,$prepare,$generate,&$done) {
        $token=$db->withNativeRequestLock(static function($connection,array $event) use($db,$job,$jobId,$queue) {
            if ($job['session_id']!==$event['session_id'] || $job['request_id']!==$event['request_id']
                || intval($job['generation'])!==$event['generation'] || $job['turn_id']!==$event['turn_id']
                || $queue['context_sequence']!==$event['context_sequence']) throw new LogicException('Profile job owner mismatch.');
            if (count($queue['npcs'])!==1) throw new LogicException('Profile job requires exactly one actor.');
            $name=$queue['npcs'][0];$narrator=$name==='The Narrator';
            $actor=$narrator ? intval($db->fetchOne('SELECT player_identity_id FROM synth_meta.context_snapshots WHERE session_id='
                .$db->escapeLiteral($event['session_id']).' AND generation='.intval($event['generation'])
                .' AND snapshot_sequence='.intval($event['context_sequence']))['player_identity_id'] ?? 0)
                : $GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'][$name]['actor_identity_id'];
            if (intval($job['actor_identity_id'])!==$actor || $queue['profile_role']!==($narrator ? 'narrator' : 'npc')) throw new LogicException('Profile job actor mismatch.');
            if (!@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtext($1))",['synth.profile.actor:'.$job['actor_identity_id']])) throw new RuntimeException('Unable to lock profile actor.');
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs j SET state='running',attempts=attempts+1,lease_token=$2,
                lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
                WHERE j.id=$1 AND j.attempts<3 AND j.deadline_at>clock_timestamp() AND j.available_at<=clock_timestamp()
                    AND (j.state='pending' OR (j.state='running' AND j.lease_until<=clock_timestamp()))
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.worker_jobs other WHERE other.id<>j.id AND other.job_type='profile'
                        AND other.payload->>'schema'='synth.profile.job.v1' AND other.actor_identity_id=j.actor_identity_id
                        AND other.state='running' AND other.lease_until>clock_timestamp() AND other.deadline_at>clock_timestamp())
                RETURNING lease_token",[$jobId,bin2hex(random_bytes(16))]);
            if (!$result) throw new RuntimeException('Unable to claim native profile job.');
            return pg_fetch_assoc($result)['lease_token'] ?? null;
        },2000);
        if (!$token) return;
        $GLOBALS['SYNTH_PROFILE_JOB_LEASE']=['id'=>$jobId,'token'=>$token];$name=$queue['npcs'][0];
        try {
            synth_profile_assert_write_target($db,$name);
            $GLOBALS['SYNTH_NAME']=$name;$GLOBALS['gameRequest']=$queue['gameRequest'];
            $row=$db->fetchOne("SELECT input_payload,input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex') AS valid
                FROM synth_meta.profile_job_inputs WHERE job_id=$jobId");
            if ($row && !synthPostgresBoolean($row['valid'])) throw new LogicException('Frozen profile input hash mismatch.');
            $input=$row ? json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR)
                : ($prepare ? $prepare($name,$queue['gameRequest']) : processSingleDynamicProfile($name,$queue['gameRequest'],true));
            if ($input===false) {
                $db->withNativeRequestLock(static function($connection) use($jobId) {
                    if (!@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='ready',last_error='profile_disabled',
                        lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE id=$1",[$jobId])) throw new RuntimeException('Unable to finish disabled profile job.');
                },2000);
                return;
            }
            synth_profile_validate_input($input,$queue['native_owner'],intval($job['actor_identity_id']),$queue['profile_role']);
            if (!$row) $db->withNativeRequestLock(static function($connection) use($jobId,$input) {
                $json=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if (!@pg_query_params($connection,"INSERT INTO synth_meta.profile_job_inputs(job_id,input_payload,input_sha256)
                    VALUES($1,$2::jsonb,encode(sha256(convert_to(($2::jsonb)::text,'UTF8')),'hex'))",[$jobId,$json])) throw new RuntimeException('Unable to freeze native profile job input.');
            },2000);
            $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$name]=$input;$updates=[];
            $db->withNativeRequestLock(static function($connection,array $event) use($db,$name,$input) {
                synth_profile_assert_write_target($db,$name);
                if ($name==='The Narrator') {
                    require_once __DIR__.'/core/narrator.class.php';
                    $base=(new Narrator())->getAll();
                } else $base=$db->fetchOne('SELECT * FROM synth.core_npc_master WHERE id='.intval($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'][$name]['npc_id']));
                if (synth_profile_fields($base)!==synth_profile_fields($input['baseline_fields'])
                    || (synth_profile_visible($db,$event,$input['actor_identity_id'],$name==='The Narrator')['revision'] ?? null)!==$input['prior_revision']) {
                    throw new LogicException('Frozen profile baseline no longer valid.');
                }
            },2000);
            foreach ($input['fields'] as $field) {
                $checkpoint=$db->fetchOne('SELECT result_text,result_sha256=encode(sha256(convert_to(result_text,\'UTF8\')),\'hex\') AS valid
                    FROM synth_meta.profile_job_fields WHERE job_id='.$jobId.' AND field='.$db->escapeLiteral($field));
                if ($checkpoint) {
                    if (!synthPostgresBoolean($checkpoint['valid'])) throw new LogicException('Profile field checkpoint hash mismatch.');
                    $updates[$field]=$checkpoint['result_text'];continue;
                }
                $db->withNativeRequestLock(static function($connection) use($jobId) {
                    if (!@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes') WHERE id=$1",[$jobId])) throw new RuntimeException('Unable to renew profile attempt.');
                },2000);
                $text=$generate ? $generate($input,$field) : synth_profile_generate_field($db,$input,$field);
                if (!is_string($text) || trim($text)==='') throw new RuntimeException('Empty profile field output.');
                $text=trim($text);synth_profile_fields([$field=>$text]);
                $db->withNativeRequestLock(static function($connection) use($db,$name,$jobId,$field,$text) {
                    synth_profile_assert_write_target($db,$name);
                    if (!@pg_query_params($connection,"INSERT INTO synth_meta.profile_job_fields(job_id,field,result_text,result_sha256)
                        VALUES($1,$2,$3,encode(sha256(convert_to($3,'UTF8')),'hex'))",[$jobId,$field,$text])) throw new RuntimeException('Unable to checkpoint profile field.');
                },2000);
                $updates[$field]=$text;
            }
            $db->withNativeRequestLock(static function($connection) use($db,$name,$updates,$jobId) {
                if (!synth_profile_record_result($db,$name,$updates)) throw new RuntimeException('Unable to publish complete profile.');
                if (!@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='ready',lease_token=NULL,lease_until=NULL,
                    last_error=NULL,updated_at=clock_timestamp() WHERE id=$1",[$jobId])) throw new RuntimeException('Unable to finish profile job.');
            },2000);
            $done=true;
        } catch (Throwable $error) {
            unset($GLOBALS['SYNTH_PROFILE_JOB_LEASE']);
            // A late attempt cannot change a replacement token. Persist only fixed, non-sensitive error codes.
            $db->withNativeRequestLock(static function($connection) use($jobId,$token,$error) {
                $terminal=$error instanceof LogicException || $error instanceof InvalidArgumentException || $error instanceof LengthException;
                if (!@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state=CASE WHEN $3::boolean OR attempts>=3
                    OR deadline_at<=clock_timestamp() THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,
                    available_at=clock_timestamp()+INTERVAL '10 seconds',last_error=$4,updated_at=clock_timestamp()
                    WHERE id=$1 AND state='running' AND lease_token=$2",[$jobId,$token,$terminal ? 't' : 'f',
                        $terminal ? 'input_or_result_invalid' : 'attempt_failed'])) throw new RuntimeException('Unable to record profile attempt failure.');
            },2000);
            Logger::warn('Native profile attempt did not complete; durable state retained.');
        } finally { unset($GLOBALS['SYNTH_PROFILE_JOB_LEASE']); }
    });
    return $done;
}

// The scheduled service recovers expired attempts, retires old lifecycles, and bounds work per tick.
function synth_profile_dispatch_jobs($db, ?callable $prepare=null, ?callable $generate=null): int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Profile dispatch requires an idle database connection.');
    if ($db->execQuery("UPDATE synth_meta.worker_jobs j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3 THEN 'failed' ELSE 'stale' END,
        lease_token=NULL,lease_until=NULL,last_error='owner_or_attempt_expired',updated_at=clock_timestamp()
        WHERE j.job_type='profile' AND j.payload->>'schema'='synth.profile.job.v1' AND j.state IN ('pending','running')
            AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND (state='pending' OR lease_until<=clock_timestamp()))
                OR NOT EXISTS(SELECT 1 FROM synth_meta.runtime_sessions r JOIN synth_meta.request_executions e USING(session_id)
                    WHERE r.session_id=j.session_id AND r.state='active' AND r.active_generation=j.generation
                        AND e.request_id=j.request_id AND e.generation=j.generation AND e.state='complete'))")===false) throw new RuntimeException('Unable to retire old profile work.');
    $jobs=$db->fetchAll("SELECT id FROM synth_meta.worker_jobs WHERE job_type='profile' AND payload->>'schema'='synth.profile.job.v1'
        AND available_at<=clock_timestamp() AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) ORDER BY available_at,id LIMIT 5");
    $done=0;
    foreach ($jobs as $job) try { if (synth_profile_run_job($db,intval($job['id']),$prepare,$generate)) ++$done; }
    catch (Throwable $error) {
        if (synth_profile_origin_rejected($error) || $error instanceof LogicException) {
            $db->execQuery("UPDATE synth_meta.worker_jobs SET state='stale',last_error='origin_unavailable',lease_token=NULL,lease_until=NULL,
                updated_at=clock_timestamp() WHERE id=".intval($job['id'])." AND job_type='profile' AND payload->>'schema'='synth.profile.job.v1'
                    AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))");
        }
        Logger::warn('Native profile job unavailable; durable diagnostic retained.');
    }
    return $done;
}
