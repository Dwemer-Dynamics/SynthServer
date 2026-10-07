<?php

require_once __DIR__.'/synth_diary_state.php';

// Admission and its replayable reply commit together; queued is never a generation-complete claim.
function synth_diary_admit_request($db): string
{
    require_once __DIR__.'/response.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db) {
        synth_protocol_validate_payload('diary_request',$event['payload'],$event['protocol_version']);
        synth_diary_assert_owner($connection,$event);
        // Automatic audiences can contain unprofiled strangers. Validate visibility first, without creating profiles.
        $actors=[];$queued=0;$disabled=0;
        foreach ($event['payload']['actors'] as $actor) {
            if ($event['payload']['reason']!=='manual') {
                $bindings=synth_profile_refresh_bindings($db,array_replace($event,
                    ['payload'=>['actors'=>[$actor],'include_narrator'=>false]]));
                if (!$bindings) { ++$disabled;continue; }
            }
            synth_diary_npc_binding($db,$event,$actor);
            $actors[]=$actor;
        }
        if ($event['payload']['include_narrator']) synth_diary_narrator_binding($db,$event);
        if ($event['payload']['include_player']) synth_diary_player_owned_binding($db,$event,'player');
        foreach ($actors as $actor) {
            if (synth_diary_enqueue_npc($db,$actor)===null) ++$disabled;else ++$queued;
        }
        if ($event['payload']['include_narrator']) {
            if (synth_diary_enqueue_narrator($db)===null) ++$disabled;else ++$queued;
        }
        if ($event['payload']['include_player']) {
            if (synth_diary_enqueue_character($db,null,'player')===null) ++$disabled;else ++$queued;
        }
        $disabledReason=$event['payload']['reason']!=='manual' ? 'no existing profile, role policy, cooldown, pending result or no diary connector'
            : ($event['payload']['include_player'] ? 'role setting or no diary connector' : 'no diary connector');
        $body=synth_protocol_encode_ack($queued ? 'diary_queued' : 'diary_disabled',
            ['detail'=>"Queued: $queued; disabled ($disabledReason): $disabled. Generation runs in the background."]);
        $result=@pg_query_params($connection,"UPDATE synth_meta.request_executions SET state='complete',
            response_content_type='application/x-ndjson',response_body=convert_to($3,'UTF8'),completed_at=clock_timestamp()
            WHERE session_id=$1 AND request_id=$2 AND state='claimed' RETURNING request_id",[$event['session_id'],$event['request_id'],$body]);
        if (!$result || pg_num_rows($result)!==1) throw new LogicException('Diary admission was not claimed.');
        return $body;
    },2000);
}

// The caller holds the active-generation lock: reserve one automatic role and consult only save-visible successful diaries.
function synth_diary_automation_available($db,array $event,array $binding,string $role,array $policy): bool
{
    if (!$policy['enabled']) return false;
    if ($event['payload']['reason']==='manual') return true;
    $pending=$db->fetchOne("SELECT 1 FROM synth_meta.diary_inputs i JOIN synth_meta.worker_jobs j ON j.diary_input_id=i.id
        LEFT JOIN synth_meta.diary_results r ON r.input_id=i.id
        JOIN synth_meta.context_snapshots c ON c.session_id=i.session_id AND c.generation=i.generation
        WHERE i.session_id=".$db->escapeLiteral($event['session_id']).' AND i.generation='.intval($event['generation'])
        .' AND c.snapshot_sequence='.intval($event['context_sequence']).' AND i.actor_identity_id='.intval($binding['actor_identity_id'])
        ." AND i.diary_role='$role' AND ((j.state IN ('pending','running') AND j.deadline_at>clock_timestamp()
            AND (j.attempts<3 OR (j.state='running' AND j.lease_until>clock_timestamp()))) OR r.history_event_id>c.history_event_id) LIMIT 1");
    if ($pending) return false;
    $last=synth_diary_visible_role($db,$event,$binding['actor_identity_id'],$role,true);
    if ($last===null) return true;
    if ($last['published_at']<1) throw new LogicException('Diary cooldown timestamp unavailable.');
    return time()-$last['published_at'] >= $policy['cooldown_seconds'];
}

// Manual NPC diaries use their profile's diary connector; auto-diary toggles do not disable explicit requests.
function synth_diary_prepare_npc($db, array $identity): ?int
{
    require_once __DIR__.'/synth_diary_config.php';
    require_once __DIR__.'/llm_randomizer.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$identity) {
        synth_diary_assert_owner($connection,$event);
        $binding=synth_diary_npc_binding($db,$event,$identity);
        // An admitted input owns its original settings even if configuration changes before a replay.
        $existing=$db->fetchOne('SELECT id FROM synth_meta.diary_inputs WHERE session_id='.$db->escapeLiteral($event['session_id'])
            .' AND request_id='.$db->escapeLiteral($event['request_id']).' AND actor_identity_id='.$binding['actor_identity_id']." AND diary_role='npc'");
        if ($existing) return intval($existing['id']);
        $npc=synth_diary_lock_binding($db,$binding);
        $profile=$db->fetchOne('SELECT * FROM synth.core_profiles WHERE id='.intval($npc['profile_id'] ?? 0));
        if (!$profile) throw new LogicException('Diary profile unavailable.');
        $automation=synth_diary_resolve_automation('npc',$event['payload']['reason'],$npc,$profile,$GLOBALS);
        if (!synth_diary_automation_available($db,$event,$binding,'npc',$automation)) return null;
        $settings=synth_diary_resolve_settings('npc',$npc,$profile,$GLOBALS,
            static fn(int $id)=>$db->fetchOne('SELECT driver,max_tokens FROM synth.core_llm_connector WHERE id='.$id),
            static fn()=>null,intval(LLMRandomizer::getConnectorIdForField($profile,'diary_connector_id')));
        if ($settings===null) return null;
        return synth_diary_capture_npc($db,$identity,$settings['prompt'],$settings['connector_id'],
            $settings['max_tokens'],$settings['history_limit'],$settings['prompt_head'],$automation);
    },2000);
}

// Persist an idempotent job inside admission; dispatch waits for the original request to finish.
function synth_diary_enqueue_npc($db, array $identity): ?int
{
    return synth_diary_enqueue_character($db,$identity,'npc');
}

function synth_diary_enqueue_narrator($db): ?int
{
    return synth_diary_enqueue_character($db,null,'narrator');
}

// Resolve Narrator's configured profile and role-specific connector policy before freezing its owned journal input.
function synth_diary_prepare_narrator($db): ?int
{
    require_once __DIR__.'/synth_diary_config.php';
    require_once __DIR__.'/llm_randomizer.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db) {
        synth_diary_assert_owner($connection,$event);
        $binding=synth_diary_narrator_binding($db,$event);
        $existing=$db->fetchOne('SELECT id FROM synth_meta.diary_inputs WHERE session_id='.$db->escapeLiteral($event['session_id'])
            .' AND request_id='.$db->escapeLiteral($event['request_id']).' AND actor_identity_id='.$binding['actor_identity_id']." AND diary_role='narrator'");
        if ($existing) return intval($existing['id']);
        $narrator=synth_diary_lock_binding($db,$binding);
        $automation=synth_diary_resolve_automation('narrator',$event['payload']['reason'],$narrator,[],$GLOBALS);
        if (!synth_diary_automation_available($db,$event,$binding,'narrator',$automation)) return null;
        $profile=$db->fetchOne('SELECT * FROM synth.core_profiles WHERE id='.intval($narrator['profile_id'] ?? 0));
        if (!$profile) throw new LogicException('Narrator diary profile unavailable.');
        $settings=synth_diary_resolve_settings('narrator',$narrator,$profile,$GLOBALS,
            static fn(int $id)=>$db->fetchOne('SELECT driver,max_tokens FROM synth.core_llm_connector WHERE id='.$id),
            static fn()=>null,intval(LLMRandomizer::getConnectorIdForField($profile,'diary_connector_id')));
        if ($settings===null) return null;
        return synth_diary_capture_narrator($db,$settings['prompt'],$settings['connector_id'],
            $settings['max_tokens'],$settings['history_limit'],$settings['prompt_head'],$automation);
    },2000);
}

// Player settings are global character configuration; the journal identity still comes only from the saved player.
function synth_diary_prepare_player($db): ?int
{
    require_once __DIR__.'/synth_diary_config.php';
    require_once __DIR__.'/data_functions.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db) {
        synth_diary_assert_owner($connection,$event);
        $binding=synth_diary_player_owned_binding($db,$event,'player');
        $existing=$db->fetchOne('SELECT id FROM synth_meta.diary_inputs WHERE session_id='.$db->escapeLiteral($event['session_id'])
            .' AND request_id='.$db->escapeLiteral($event['request_id']).' AND actor_identity_id='.$binding['actor_identity_id']." AND diary_role='player'");
        if ($existing) return intval($existing['id']);
        $player=synth_diary_lock_binding($db,$binding);
        $automation=synth_diary_resolve_automation('player',$event['payload']['reason'],$player,[],$GLOBALS);
        if (!synth_diary_automation_available($db,$event,$binding,'player',$automation)) return null;
        $settings=synth_diary_resolve_settings('player',$player,[],$GLOBALS,
            static fn(int $id)=>$db->fetchOne('SELECT driver,max_tokens FROM synth.core_llm_connector WHERE id='.$id),
            static fn()=>synthResolveDiaryConnectorName($GLOBALS['CONNECTORS_DIARY'] ?? null,false));
        if ($settings===null) return null;
        return synth_diary_capture_character($db,null,$settings['prompt'],$settings['connector_id'],$settings['max_tokens'],
            $settings['history_limit'],$settings['prompt_head'],'player',$settings['connector_source']==='legacy_diary' ? $settings['connector_driver'] : null,$automation);
    },2000);
}

// All diary roles share transactional admission and fencing, with a distinct input identity for each role.
function synth_diary_enqueue_character($db, ?array $identity, string $role): ?int
{
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$identity,$role) {
        $inputId=match($role) {
            'npc'=>synth_diary_prepare_npc($db,$identity), 'narrator'=>synth_diary_prepare_narrator($db),
            'player'=>synth_diary_prepare_player($db), default=>throw new LogicException('Invalid diary admission role.')
        };
        if ($inputId===null) return null;
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs
            (job_type,session_id,request_id,turn_id,generation,actor_identity_id,diary_input_id,payload,deadline_at)
            SELECT 'diary',session_id,request_id,$2,generation,actor_identity_id,id,
                jsonb_build_object('schema','synth.diary.job.v1','context_sequence',context_sequence),clock_timestamp()+INTERVAL '30 minutes'
            FROM synth_meta.diary_inputs WHERE id=$1 ON CONFLICT DO NOTHING",[$inputId,$event['turn_id']]);
        if (!$result) throw new RuntimeException('Unable to admit diary job.');
        return intval($db->fetchOne('SELECT id FROM synth_meta.worker_jobs WHERE diary_input_id='.$inputId)['id']);
    },2000);
}

// Workers restore every request/provider global they touch so separate actors and task types cannot inherit it.
function synth_diary_with_owner($db, array $owner, callable $work): mixed
{
    if (($owner['protocol_version'] ?? null)!==2 || ($owner['type'] ?? '')!=='diary_request') throw new LogicException('Invalid diary worker owner.');
    $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_DIARY_JOB_LEASE',
        'SYNTH_MEMORY_JOB_LEASE','SYNTH_PROFILE_JOB_LEASE','SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_INTERACTION_JOB_LEASE',
        'SYNTH_CORE_CURRENT_CONNECTOR_DATA','CURRENT_CONNECTOR','CONNECTOR','FORCE_MAX_TOKENS','DEBUG_DATA',
        'COMMAND_PROMPT','BASE_FUNCTIONS','SYNTH_CLIENT_CAPABILITIES','DIRECT_NARRATOR_DIALOGUE',
        'PATCH_PROMPT_ENFORCE_ACTIONS','COMMAND_PROMPT_ENFORCE_ACTIONS','gameRequest','SYNTH_NAME','PLAYER_NAME'];
    $previous=array_intersect_key($GLOBALS,array_flip($keys));
    try {
        foreach (['SYNTH_DIARY_JOB_LEASE','SYNTH_MEMORY_JOB_LEASE','SYNTH_PROFILE_JOB_LEASE','SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_INTERACTION_JOB_LEASE'] as $key) unset($GLOBALS[$key]);
        $GLOBALS['db']=$db;$GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner];$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        synth_protocol_assert_request_active();
        return $work();
    } finally {
        foreach ($keys as $key) { if (array_key_exists($key,$previous)) $GLOBALS[$key]=$previous[$key];else unset($GLOBALS[$key]); }
    }
}

// Only frozen bounded messages can reach a provider; connector secrets remain live configuration.
function synth_diary_generate($db, array $input): array
{
    if (!$db->isTransactionIdle()) throw new LogicException('Diary provider requires an idle database connection.');
    synth_protocol_assert_request_active();
    require_once __DIR__.'/core/llm_connector.class.php';
    $selection=synth_diary_input_connector($input);
    if ($selection['kind']==='core') {
        $manager=new LLMConnector();$settings=$manager->getById($selection['id']);
        if (!$settings) throw new RuntimeException('Diary connector unavailable.');
        $GLOBALS['SYNTH_CORE_CURRENT_CONNECTOR_DATA']=$settings;$manager->setOldGlobals($settings);
        if ($input['diary_role']==='player') unset($GLOBALS['SYNTH_CORE_CURRENT_CONNECTOR_DATA']['stop']);
        $driver=$settings['driver'];
    } else {
        // Never look up an arbitrary core record for a frozen legacy selection or inherit a previous worker's core metadata.
        unset($GLOBALS['SYNTH_CORE_CURRENT_CONNECTOR_DATA']);
        $driver=$selection['driver'];
        require_once __DIR__.'/../connector/'.$driver.'.php';
    }
    $GLOBALS['CURRENT_CONNECTOR']=$driver;$GLOBALS['FORCE_MAX_TOKENS']=$input['max_tokens'];
    $GLOBALS['COMMAND_PROMPT']='';$GLOBALS['BASE_FUNCTIONS']=[];$GLOBALS['SYNTH_CLIENT_CAPABILITIES']=[];$GLOBALS['DIRECT_NARRATOR_DIALOGUE']=false;
    $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS']=false;$GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS']='';
    $GLOBALS['SYNTH_NAME']=$input['binding']['name'];$GLOBALS['PLAYER_NAME']=$input['diary_role']==='player'
        ? ($input['character_name'] ?? $input['context']['player']['display_name']) : $input['context']['player']['display_name'];
    $reason=$input['owner']['payload']['reason'] ?? 'manual';
    $GLOBALS['gameRequest']=[$input['diary_role']==='player' ? 'diary_player' : 'diary',time(),intval($input['context']['world']['game_time_ticks'] ?? 0),'Native '.$reason.' diary'];
    $handler=$selection['kind']==='core' ? $manager->getConnector($settings) : new $driver();
    $text=$handler->fast_request($input['messages'],['MAX_TOKENS'=>$input['max_tokens']],'diary');
    if (!is_string($text) || trim($text)==='') throw new RuntimeException('Diary provider returned no text.');
    return ['topic'=>ucfirst($reason).' diary','content'=>trim($text)];
}

// Recovered input is checked independently of its hash before provider use.
function synth_diary_validate_job_input(array $input): void
{
    synth_diary_input_connector($input);
    if (($input['owner']['payload']['reason'] ?? 'manual')!=='manual'
        && (!synth_diary_reason_allowed($input['owner']) || ($input['automation']['enabled'] ?? null)!==true
            || !is_int($input['automation']['cooldown_seconds'] ?? null) || $input['automation']['cooldown_seconds']<0)) {
        throw new LogicException('Invalid frozen diary automation policy.');
    }
    if (($input['schema'] ?? '')!=='synth.diary.input.v1' || !in_array($input['diary_role'] ?? '',['npc','narrator','player'],true)
        || !is_int($input['max_tokens'] ?? null) || $input['max_tokens']<1
        || !is_array($input['messages'] ?? null) || !array_is_list($input['messages']) || count($input['messages'])!==3) {
        throw new LogicException('Invalid frozen diary input.');
    }
    foreach ($input['messages'] as $index=>$message) {
        if (!is_array($message) || array_diff(array_keys($message),['role','content'])
            || ($message['role'] ?? '')!==($index===0 ? 'system' : 'user') || !is_string($message['content'] ?? null)
            || str_contains($message['content'],"\0") || !preg_match('//u',$message['content'])) throw new LogicException('Invalid frozen diary message.');
    }
    if (strlen(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>1048576) throw new LengthException('Frozen diary input exceeds its bound.');
}

// A fenced attempt checkpoints accepted text, then commits journal publication and ready state together.
function synth_diary_run_job($db, int $jobId, ?callable $generate=null, ?callable $embed=null): bool
{
    if (!$db->isTransactionIdle()) throw new LogicException('Diary worker requires an idle database connection.');
    $job=$db->fetchOne("SELECT j.*,i.input_payload,i.input_sha256,i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex') AS valid
        FROM synth_meta.worker_jobs j JOIN synth_meta.diary_inputs i ON i.id=j.diary_input_id
        WHERE j.id=".intval($jobId)." AND j.job_type='diary' AND j.payload->>'schema'='synth.diary.job.v1' AND j.state IN ('pending','running')");
    if (!$job) return false;
    if ($job['valid']!=='t') throw new LogicException('Frozen diary input hash mismatch.');
    $input=json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);synth_diary_validate_job_input($input);
    return synth_diary_with_owner($db,$input['owner'],static function() use($db,$jobId,$job,$input,$generate,$embed) {
        $token=$db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId,$job,$input) {
            synth_diary_assert_owner($connection,$event,true);
            if ($job['session_id']!==$event['session_id'] || $job['request_id']!==$event['request_id']
                || intval($job['generation'])!==$event['generation'] || $job['turn_id']!==$event['turn_id']
                || intval($job['actor_identity_id'])!==$input['actor_identity_id']) throw new LogicException('Diary job owner mismatch.');
            $binding=$input['diary_role']!=='npc' ? synth_diary_player_owned_binding($db,$event,$input['diary_role'])
                : synth_diary_npc_binding($db,$event,$input['binding']['identity']);
            if (synth_protocol_canonical_json($binding)!==synth_protocol_canonical_json($input['binding'])) throw new LogicException('Diary job binding changed.');
            synth_diary_lock_binding($db,$binding);
            if (!@pg_query_params($connection,'SELECT pg_advisory_xact_lock(hashtext($1))',['synth.diary.actor:'.$job['actor_identity_id']])) throw new RuntimeException('Unable to lock diary actor.');
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs j SET state='running',attempts=attempts+1,lease_token=$2,
                lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
                WHERE j.id=$1 AND attempts<3 AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp()
                    AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.worker_jobs other WHERE other.id<>j.id AND other.job_type='diary'
                        AND other.actor_identity_id=j.actor_identity_id AND other.state='running'
                        AND other.lease_until>clock_timestamp() AND other.deadline_at>clock_timestamp()) RETURNING lease_token",[$jobId,bin2hex(random_bytes(16))]);
            if (!$result) throw new RuntimeException('Unable to claim diary job.');
            return pg_fetch_assoc($result)['lease_token'] ?? null;
        },2000);
        if (!$token) return false;
        $GLOBALS['SYNTH_DIARY_JOB_LEASE']=['id'=>$jobId,'token'=>$token];
        try {
            $saved=$db->fetchOne("SELECT result_payload,result_sha256=encode(sha256(convert_to(result_payload::text,'UTF8')),'hex')
                AND input_sha256=".$db->escapeLiteral($job['input_sha256'])." AS valid
                FROM synth_meta.diary_job_results WHERE job_id=$jobId");
            if ($saved && $saved['valid']!=='t') throw new LogicException('Diary checkpoint hash mismatch.');
            $value=$saved ? json_decode($saved['result_payload'],true,flags:JSON_THROW_ON_ERROR)
                : ($generate ? $generate($input) : synth_diary_generate($db,$input));
            synth_diary_validate_result($value);
            if (!$saved) $db->withNativeRequestLock(static function($connection) use($jobId,$job,$value) {
                if (!@pg_query_params($connection,"INSERT INTO synth_meta.diary_job_results(job_id,input_sha256,result_payload,result_sha256)
                    VALUES($1,$3,$2::jsonb,encode(sha256(convert_to(($2::jsonb)::text,'UTF8')),'hex'))",[$jobId,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$job['input_sha256']])) throw new RuntimeException('Unable to checkpoint diary output.');
            },2000);
            require_once __DIR__.'/synth_memory_recall.php';
            $vector=$db->fetchOne("SELECT embedding_attempted,
                (embedding IS NULL AND embedding_sha256 IS NULL AND embedding_text_sha256 IS NULL) OR
                    (embedding_attempted AND embedding_sha256=encode(sha256(convert_to(embedding::text,'UTF8')),'hex')
                        AND (embedding_text_sha256 IS NULL OR embedding_text_sha256=encode(sha256(convert_to(result_payload->>'content','UTF8')),'hex'))) AS valid
                FROM synth_meta.diary_job_results WHERE job_id=$jobId");
            if (!$vector || $vector['valid']!=='t') throw new LogicException('Diary embedding checkpoint invalid.');
            if ($vector['embedding_attempted']!=='t') {
                $embedding=synth_memory_optional_embedding($value['content'],
                    $embed ?? ($generate===null ? 'synth_memory_fetch_embedding' : null));
                if ($embedding!==null) $embedding=synth_memory_vector_json($embedding);
                $embeddingTextHash=$embedding===null ? null : hash('sha256',$value['content']);
                $db->withNativeRequestLock(static function($connection) use($jobId,$embedding,$embeddingTextHash) {
                    if (!@pg_query_params($connection,"UPDATE synth_meta.diary_job_results SET embedding=$2::public.vector,
                        embedding_sha256=CASE WHEN $2 IS NULL THEN NULL ELSE encode(sha256(convert_to(($2::public.vector)::text,'UTF8')),'hex') END,
                        embedding_text_sha256=$3,embedding_attempted=true WHERE job_id=$1 AND NOT embedding_attempted",[$jobId,$embedding,$embeddingTextHash])) throw new RuntimeException('Unable to checkpoint diary embedding.');
                },2000);
            }
            $db->withNativeRequestLock(static function($connection) use($db,$job,$jobId,$value,$input) {
                $revision=synth_diary_publish_character($db,intval($job['diary_input_id']),$value,$job['input_sha256'],$input['diary_role']);
                // Preserve old checkpoints without inventing provenance; publish their accepted text without an unbound vector.
                $attached=@pg_query_params($connection,"UPDATE synth_meta.diary_results r SET
                        embedding=CASE WHEN c.embedding_text_sha256 IS NOT NULL THEN c.embedding ELSE NULL END,
                        embedding_sha256=CASE WHEN c.embedding_text_sha256 IS NOT NULL THEN c.embedding_sha256 ELSE NULL END,
                        embedding_text_sha256=c.embedding_text_sha256
                    FROM synth_meta.diary_job_results c WHERE r.history_event_id=$1 AND c.job_id=$2
                        AND r.result_sha256=c.result_sha256 AND r.input_id=$3 AND c.embedding_attempted
                        AND ((c.embedding IS NULL AND c.embedding_sha256 IS NULL AND c.embedding_text_sha256 IS NULL) OR
                            (c.embedding_sha256=encode(sha256(convert_to(c.embedding::text,'UTF8')),'hex')
                                AND (c.embedding_text_sha256 IS NULL OR c.embedding_text_sha256=encode(sha256(convert_to(r.result_payload->>'content','UTF8')),'hex'))))
                    RETURNING r.history_event_id",[$revision,$jobId,$job['diary_input_id']]);
                if (!$attached || pg_num_rows($attached)!==1) throw new RuntimeException('Unable to publish diary embedding.');
                if (!@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='ready',last_error=NULL,
                    lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE id=$1",[$jobId])) throw new RuntimeException('Unable to complete diary job.');
            },2000);
            return true;
        } catch (Throwable $error) {
            unset($GLOBALS['SYNTH_DIARY_JOB_LEASE']);
            $db->withNativeRequestLock(static function($connection) use($jobId,$token,$error) {
                $terminal=$error instanceof LogicException || $error instanceof LengthException;
                if (!@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state=CASE WHEN $3::boolean OR attempts>=3 OR deadline_at<=clock_timestamp()
                    THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
                    last_error=$4,updated_at=clock_timestamp() WHERE id=$1 AND state='running' AND lease_token=$2",
                    [$jobId,$token,$terminal ? 't' : 'f',$terminal ? 'input_or_result_invalid' : 'attempt_failed'])) throw new RuntimeException('Unable to retain diary failure.');
            },2000);
            Logger::warn('Native diary attempt did not complete; durable state retained.');
            return false;
        } finally { unset($GLOBALS['SYNTH_DIARY_JOB_LEASE']); }
    });
}

// Unfinished admission waits; obsolete generations, cancelled owners and exhausted attempts retire without provider work.
function synth_diary_dispatch_jobs($db, ?callable $generate=null): int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Diary dispatch requires an idle database connection.');
    if ($db->execQuery("UPDATE synth_meta.worker_jobs j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3 THEN 'failed' ELSE 'stale' END,
        lease_token=NULL,lease_until=NULL,last_error='owner_or_attempt_expired',updated_at=clock_timestamp()
        WHERE j.job_type='diary' AND j.payload->>'schema'='synth.diary.job.v1' AND j.state IN ('pending','running')
            AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND (state='pending' OR lease_until<=clock_timestamp()))
                OR NOT EXISTS(SELECT 1 FROM synth_meta.runtime_sessions r JOIN synth_meta.request_executions e USING(session_id)
                    WHERE r.session_id=j.session_id AND r.state='active' AND r.active_generation=j.generation
                        AND e.request_id=j.request_id AND e.generation=j.generation AND e.state IN ('claimed','streaming','complete')))")===false) throw new RuntimeException('Unable to retire diary work.');
    $jobs=$db->fetchAll("SELECT j.id FROM synth_meta.worker_jobs j JOIN synth_meta.request_executions e
        ON e.session_id=j.session_id AND e.request_id=j.request_id AND e.generation=j.generation AND e.state='complete'
        WHERE j.job_type='diary' AND j.payload->>'schema'='synth.diary.job.v1' AND j.available_at<=clock_timestamp()
            AND (j.state='pending' OR (j.state='running' AND j.lease_until<=clock_timestamp())) ORDER BY j.available_at,j.id LIMIT 5");
    $done=0;
    foreach ($jobs as $job) try { if (synth_diary_run_job($db,intval($job['id']),$generate)) ++$done; }
    catch (Throwable $error) {
        if ($error instanceof LogicException) $db->execQuery("UPDATE synth_meta.worker_jobs SET state='failed',last_error='input_or_owner_invalid',
            lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE id=".intval($job['id'])."
            AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))");
        Logger::warn('Native diary job unavailable; durable diagnostic retained.');
    }
    return $done;
}
