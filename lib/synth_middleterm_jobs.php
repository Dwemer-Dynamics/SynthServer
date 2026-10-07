<?php

require_once __DIR__.'/synth_middleterm.php';
require_once __DIR__.'/synth_middleterm_approval.php';
require_once __DIR__.'/synth_worker_origin.php';

// Revalidate the exact frozen source versions and prior chain while the caller owns a short request transaction.
function synth_middleterm_validate_input($db, array $row): array
{
    $input=synth_protocol_decode_json($row['input_payload']);$event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
    $regeneration=($row['regeneration_approval_id']??null)!==null;
    $context=synth_protocol_current_context($db,$event);
    $identity=$context ? synth_protocol_response_actor_identity($db,$event,$context) : null;
    if (($input['schema'] ?? '')!==($regeneration?'synth.middleterm.input.v2':'synth.middleterm.input.v1') || !is_array($identity)
        || (!$regeneration && array_key_exists('regeneration',$input)) || ($regeneration && !is_array($input['regeneration']??null))
        || synth_protocol_canonical_json($input['owner'] ?? [])!==synth_protocol_canonical_json($event)
        || synth_protocol_canonical_json($input['identity'] ?? [])!==synth_protocol_canonical_json($identity)
        || intval($input['actor_identity_id'] ?? 0)!==intval($row['actor_identity_id'])
        || synth_protocol_actor_identity_id($db,$identity)!==intval($row['actor_identity_id'])
        || $row['session_id']!==$event['session_id'] || $row['request_id']!==$event['request_id']
        || intval($row['generation'])!==$event['generation'] || intval($row['context_sequence'])!==$event['context_sequence']
        || $row['playthrough_id']!==$identity['playthrough_id'] || !is_array($input['sources'] ?? null)
        || !array_key_exists('prior',$input) || ($input['prior']!==null && !is_array($input['prior']))
        || count($input['sources'])<($input['prior']===null?5:10) || count($input['sources'])>100) {
        throw new LogicException('Native narrative input owner mismatch.');
    }
    if (($input['prior']['history_event_id']??null)===null ? $row['prior_history_event_id']!==null
        : strval($input['prior']['history_event_id'])!==strval($row['prior_history_event_id'])) {
        throw new LogicException('Native narrative normalized prior mismatch.');
    }
    $digest=$db->fetchOne('SELECT 1 FROM synth_meta.middleterm_inputs WHERE id='.intval($row['id'])
        ." AND input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')");
    if (!$digest) throw new LogicException('Native narrative input digest mismatch.');
    if (synth_middleterm_has_unsaved_control($db,$event,intval($row['actor_identity_id']))) {
        throw new LogicException('Native narrative correction awaits a new snapshot.');
    }
    if ($regeneration) {
        $approved=synth_middleterm_approved_regeneration_plan($db,$event,intval($row['actor_identity_id']),intval($row['regeneration_approval_id']));
        if ($approved['status']!=='ready' || synth_protocol_canonical_json($input['regeneration']??[])!==synth_protocol_canonical_json([
            'approval_id'=>intval($approved['approval_id']),'target_narrative_id'=>intval($approved['plan']['target']['narrative_id']),
            'plan_sha256'=>$approved['plan']['plan_sha256']])) throw new LogicException('Approved narrative plan changed.');
        $prior=$approved['plan']['prior'];
        if (synth_protocol_canonical_json($input['sources'])!==synth_protocol_canonical_json($approved['plan']['sources'])) {
            throw new LogicException('Approved narrative sources changed.');
        }
    } else $prior=synth_middleterm_visible($db,$identity['display_name']);
    if (($prior===null)!==($input['prior']===null)
        || synth_protocol_canonical_json($prior ?? [])!==synth_protocol_canonical_json($input['prior'] ?? [])) {
        throw new LogicException('Native narrative prior changed.');
    }
    $rows=$db->fetchAll(synth_middleterm_history_cte($db,$event,$identity['display_name'])."
        SELECT d.position,d.summary_id,d.summary_revision,d.source_sha256,s.summary,b.last_gamets
        FROM synth_meta.middleterm_sources d JOIN native_memory_effective_summaries s ON s.id=d.summary_id
            AND s.summary_revision=d.summary_revision AND d.source_sha256=encode(sha256(convert_to(s.summary,'UTF8')),'hex')
        JOIN native_memory_batches b ON b.id=s.batch_id AND b.actor_identity_id=".intval($row['actor_identity_id'])."
        WHERE d.input_id=".intval($row['id']).' ORDER BY d.position');
    $sources=[];
    foreach ($rows as $position=>$source) {
        if (intval($source['position'])!==$position) throw new LogicException('Native narrative source order changed.');
        $sources[]=['id'=>intval($source['summary_id']),'revision'=>intval($source['summary_revision']),
            'gamets'=>intval($source['last_gamets']),'summary'=>$source['summary']];
    }
    // PostgreSQL JSONB reorders object keys; preserve list order while comparing canonical object contents.
    if (synth_protocol_canonical_json($sources)!==synth_protocol_canonical_json($input['sources'])) {
        throw new LogicException('Native narrative sources changed.');
    }
    return $input;
}

// Private attempt tokens bound every provider wait; renewal never resurrects an expired or replaced lease.
function synth_middleterm_assert_lease($db): void
{
    $lease=$GLOBALS['SYNTH_MIDDLETERM_JOB_LEASE'];$event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
    if (microtime(true)<($lease['renew_after'] ?? 0)) return;
    $row=$db->fetchOne("UPDATE synth_meta.worker_jobs SET lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '15 seconds')
        WHERE id=".intval($lease['id'])." AND job_type='middleterm' AND payload->>'schema'='synth.middleterm.job.v1'
            AND session_id=".$db->escapeLiteral($event['session_id']).' AND request_id='.$db->escapeLiteral($event['request_id'])
        .' AND generation='.intval($event['generation']).' AND turn_id='.$db->escapeLiteral($event['turn_id'])
        ." AND payload->>'context_sequence'=".$db->escapeLiteral(strval($event['context_sequence']))
        ." AND state='running' AND lease_token=".$db->escapeLiteral($lease['token'])
        ." AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id");
    if (!$row) throw new SYNTHProtocolCancelled('middleterm_lease_lost');
    $GLOBALS['SYNTH_MIDDLETERM_JOB_LEASE']['renew_after']=microtime(true)+1;
}

// Bind the exact database connector fingerprint; changed settings require fresh admission, never silent retry drift.
function synth_middleterm_generate($db, array $input): string
{
    if (!$db->isTransactionIdle()) throw new LogicException('Narrative provider requires an idle database connection.');
    require_once __DIR__.'/core/llm_connector.class.php';
    $manager=new LLMConnector();$connector=$manager->getById($input['connector_id']);
    if (!$connector || !hash_equals($input['connector_sha256'],hash('sha256',synth_protocol_canonical_json($connector)))) {
        throw new LogicException('Middle-term connector changed after admission.');
    }
    $manager->setOldGlobals($connector);$GLOBALS['SYNTH_CORE_CURRENT_CONNECTOR_DATA']=$connector;
    $GLOBALS['SYNTH_NAME']=$input['identity']['display_name'];$GLOBALS['CURRENT_CONNECTOR']=$connector['driver'];
    $context=synth_protocol_current_context($db,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
    $GLOBALS['PLAYER_NAME']=strval($context['player']['display_name'] ?? 'Player');
    $GLOBALS['gameRequest']=['middleterm'];
    $GLOBALS['FORCE_MAX_TOKENS']=2048;$GLOBALS['COMMAND_PROMPT']='';$GLOBALS['BASE_FUNCTIONS']=[];
    $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=[];$GLOBALS['DIRECT_NARRATOR_DIALOGUE']=false;
    $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS']=false;$GLOBALS['COMMAND_PROMPT_ENFORCE_ACTIONS']='';
    synth_protocol_assert_request_active();
    // Serialize the frozen evidence once at transport time instead of duplicating it inside the persisted input.
    $messages=$input['messages'];
    $messages[]=['role'=>'user','content'=>json_encode(['previous_narrative'=>$input['prior'],'new_summaries'=>$input['sources']],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    $text=$manager->getConnector($connector)->fast_request($messages,['MAX_TOKENS'=>2048],'middleterm');
    if (!is_string($text) || trim($text)==='') throw new RuntimeException('Narrative provider returned no text.');
    return trim($text);
}

// Only the same unexpired attempt can publish the journal and terminal job state together.
function synth_middleterm_run_job($db, int $jobId, ?callable $generate=null): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Narrative worker requires an idle database connection.');
    $job=$db->fetchOne("SELECT j.*,p.canonical_event,p.event_sha256,e.event_type,j.payload->>'context_sequence' AS context_sequence
        FROM synth_meta.worker_jobs j JOIN synth_meta.request_executions e USING(session_id,request_id,generation,turn_id)
        JOIN synth_meta.protocol_events p USING(session_id,request_id,generation,turn_id,event_type)
        JOIN synth_meta.middleterm_inputs i ON i.id=j.middleterm_input_id AND i.actor_identity_id=j.actor_identity_id
            AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
            AND i.context_sequence::text=j.payload->>'context_sequence'
        WHERE j.id=".intval($jobId)." AND j.job_type='middleterm' AND j.middleterm_input_id IS NOT NULL AND j.state IN ('pending','running')
            AND j.payload->>'schema'='synth.middleterm.job.v1' AND p.event_sha256=e.request_sha256 AND e.state='complete'");
    if (!$job) return null;
    return synth_worker_with_origin($db,$job,static function() use($db,$job,$jobId,$generate) {
        $token=bin2hex(random_bytes(16));
        $row=$db->withNativeRequestLock(static function($connection) use($db,$job,$jobId,$token) {
            $row=$db->fetchOne('SELECT * FROM synth_meta.middleterm_inputs WHERE id='.intval($job['middleterm_input_id']));
            if (!$row) throw new LogicException('Native narrative input missing.');
            if (!@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-middleterm:'||$1,0))",[$row['actor_identity_id']])) {
                throw new RuntimeException('Unable to lock narrative claim.');
            }
            if ($row['regeneration_approval_id']!==null && synth_middleterm_regeneration_budget($db,intval($row['regeneration_approval_id']))['state']!=='ready') {
                $db->execQuery("UPDATE synth_meta.worker_jobs SET state='failed',last_error='regeneration_budget_closed'
                    WHERE id=$jobId AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))");
                return null;
            }
            synth_middleterm_validate_input($db,$row);
            $claimed=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='running',attempts=attempts+1,
                lease_token=$2,lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '15 seconds'),updated_at=clock_timestamp()
                WHERE id=$1 AND attempts<3 AND available_at<=clock_timestamp() AND deadline_at>clock_timestamp()
                    AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) RETURNING id",[$jobId,$token]);
            if (!$claimed) throw new RuntimeException('Unable to claim narrative job.');
            return pg_fetch_assoc($claimed) ? $row : null;
        },2000);
        if (!$row) return null;
        $GLOBALS['SYNTH_MIDDLETERM_JOB_LEASE']=['id'=>$jobId,'token'=>$token];
        try {
            $input=synth_protocol_decode_json($row['input_payload']);
            $text=trim($generate ? $generate($input) : synth_middleterm_generate($db,$input));
            if ($text==='' || strlen($text)>65536 || !mb_check_encoding($text,'UTF-8')) throw new LogicException('Invalid narrative output.');
            return $db->withNativeRequestLock(static function($connection,array $event) use($db,$row,$jobId,$token,$text) {
                if (!@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-middleterm:'||$1,0))",[$row['actor_identity_id']])) {
                    throw new RuntimeException('Unable to lock narrative publication.');
                }
                synth_middleterm_validate_input($db,$row);
                $context=synth_protocol_current_context($db,$event);
                $result=@pg_query_params($connection,"WITH accepted AS (
                    UPDATE synth_meta.worker_jobs SET state='ready',lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp()
                    WHERE id=$1 AND state='running' AND lease_token=$2 AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id
                ), publication AS (
                    INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                    SELECT 'middleterm_result','Accepted native narrative memory',$3,$4,$5,$5,'','','','derived',$6,$7,$8,$9 FROM accepted RETURNING *
                ), history AS (
                    INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                    SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$3,rowid,to_jsonb(publication)
                        ||jsonb_build_object('middleterm_input_sha256',$10::text,'middleterm_narrative',$11::text,
                            'middleterm_result_sha256',encode(sha256(convert_to($11::text,'UTF8')),'hex')) FROM publication RETURNING id
                ), result AS (
                    INSERT INTO synth_meta.middleterm_results(history_event_id,input_id,narrative,result_sha256)
                    SELECT id,$12,$11,encode(sha256(convert_to($11::text,'UTF8')),'hex') FROM history RETURNING history_event_id
                ), replacement AS (
                    INSERT INTO synth_meta.middleterm_replacements(history_event_id,approval_id,target_history_event_id,session_id,generation)
                    SELECT result.history_event_id,approval.history_event_id,approval.narrative_id,$6,$7
                    FROM result JOIN synth_meta.middleterm_regeneration_approvals approval ON approval.history_event_id=$13
                    RETURNING history_event_id
                ) SELECT result.history_event_id FROM result WHERE $13::bigint IS NULL
                    OR EXISTS(SELECT 1 FROM replacement WHERE replacement.history_event_id=result.history_event_id)",
                    [$jobId,$token,$row['playthrough_id'],intval($context['payload']['world']['game_time_ticks'] ?? 0),time(),
                        $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],$row['input_sha256'],$text,$row['id'],$row['regeneration_approval_id']]);
                if (!$result) throw new RuntimeException('Unable to publish native narrative memory.');
                if (!($saved=pg_fetch_assoc($result))) throw new SYNTHProtocolCancelled('middleterm_publication_lost');
                return intval($saved['history_event_id']);
            },2000);
        } catch (Throwable $error) {
            $terminal=$error instanceof LogicException || $error instanceof SYNTHProtocolCancelled;
            $readmit=$row['regeneration_approval_id']!==null && $error instanceof SYNTHProtocolCancelled;
            $db->execQuery("UPDATE synth_meta.worker_jobs SET state=CASE WHEN ".($readmit?'TRUE':'FALSE')." AND deadline_at>clock_timestamp() THEN 'stale'
                WHEN ".($terminal?'TRUE':'FALSE')." OR attempts>=3
                OR deadline_at<=clock_timestamp() THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,
                available_at=clock_timestamp()+INTERVAL '10 seconds',last_error='middleterm_attempt_failed',updated_at=clock_timestamp()
                WHERE id=$jobId AND state='running' AND lease_token=".$db->escapeLiteral($token));
            throw $error;
        } finally {unset($GLOBALS['SYNTH_MIDDLETERM_JOB_LEASE']);}
    },'middleterm');
}

// Input and durable job become visible together; a repeated terminal input cannot trigger fresh model calls.
function synth_middleterm_enqueue($db,?int $approvalId=null): ?int
{
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$approvalId) {
        $inputId=synth_middleterm_capture($db,$approvalId);
        if ($inputId===null) return null;
        $payload=json_encode(['schema'=>'synth.middleterm.job.v1','context_sequence'=>$event['context_sequence']],JSON_THROW_ON_ERROR);
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs(job_type,session_id,request_id,turn_id,generation,
            actor_identity_id,middleterm_input_id,payload,deadline_at)
            SELECT 'middleterm',i.session_id,i.request_id,$2,i.generation,i.actor_identity_id,i.id,$3::jsonb,
                COALESCE((SELECT MIN(prior.deadline_at) FROM synth_meta.worker_jobs prior JOIN synth_meta.middleterm_inputs previous ON previous.id=prior.middleterm_input_id
                    WHERE previous.regeneration_approval_id=i.regeneration_approval_id),clock_timestamp()+INTERVAL '120 seconds')
            FROM synth_meta.middleterm_inputs i WHERE i.id=$1 ON CONFLICT(middleterm_input_id) WHERE middleterm_input_id IS NOT NULL
            DO UPDATE SET middleterm_input_id=EXCLUDED.middleterm_input_id RETURNING id",[$inputId,$event['turn_id'],$payload]);
        if (!$result || !($row=pg_fetch_assoc($result))) throw new RuntimeException('Unable to enqueue narrative memory.');
        return intval($row['id']);
    },2000);
}

// One bounded manager pass recovers a due attempt or discovers one live completed owner; it never uses a legacy name scan.
function synth_middleterm_dispatch($db, ?callable $generate=null): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Narrative dispatch requires an idle database connection.');
    if ($db->execQuery("UPDATE synth_meta.worker_jobs j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3
        THEN 'failed' ELSE 'stale' END,lease_token=NULL,lease_until=NULL,last_error='middleterm_owner_expired'
        WHERE job_type='middleterm' AND state IN ('pending','running') AND (deadline_at<=clock_timestamp()
            OR (attempts>=3 AND (state='pending' OR lease_until<=clock_timestamp())) OR NOT EXISTS(
                SELECT 1 FROM synth_meta.runtime_sessions r JOIN synth_meta.request_executions e USING(session_id)
                WHERE r.session_id=j.session_id AND r.state='active' AND r.active_generation=j.generation
                    AND r.active_turn_id=j.turn_id AND r.active_turn_sequence::text=j.payload->>'context_sequence'
                    AND e.request_id=j.request_id AND e.generation=j.generation AND e.state='complete'))")===false) {
        throw new RuntimeException('Unable to retire narrative jobs.');
    }
    $due=$db->fetchOne("SELECT id FROM synth_meta.worker_jobs WHERE job_type='middleterm' AND available_at<=clock_timestamp()
        AND deadline_at>clock_timestamp() AND attempts<3 AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
        ORDER BY available_at,id LIMIT 1");
    $jobId=$due ? intval($due['id']) : null;
    try {
        if ($jobId===null) {
            if ($db->execQuery('BEGIN')===false) throw new RuntimeException('Unable to begin narrative discovery.');
            try {
                if ($db->execQuery("SET LOCAL statement_timeout='2s'")===false) throw new RuntimeException('Unable to bound narrative discovery.');
                $result=$db->execQuery("WITH candidate AS (
                    SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,o.canonical_event,o.event_sha256,
                        r.active_turn_sequence AS context_sequence
                    FROM synth_meta.runtime_sessions r CROSS JOIN LATERAL (
                        SELECT e.*,p.canonical_event,p.event_sha256 FROM synth_meta.request_executions e
                        JOIN synth_meta.protocol_events p USING(session_id,request_id,generation,turn_id,event_type)
                        WHERE e.session_id=r.session_id AND e.generation=r.active_generation AND e.turn_id=r.active_turn_id
                            AND e.state='complete' AND p.event_sha256=e.request_sha256
                            AND p.canonical_event->>'protocol_version'='2'
                            AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                            AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                            AND p.canonical_event->'capabilities' ? 'dialogue.turn_ownership'
                            AND (e.event_type IN ('input_text','input_audio','trigger','action_result')
                                OR (e.event_type='activate' AND p.canonical_event#>>'{payload,source}'='manual'))
                        ORDER BY e.claimed_at DESC,e.request_id DESC LIMIT 1
                    ) o WHERE r.state='active' AND (r.middleterm_dispatch_after IS NULL OR r.middleterm_dispatch_after<=clock_timestamp())
                    ORDER BY r.middleterm_dispatch_after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
                ), reserved AS (
                    UPDATE synth_meta.runtime_sessions r SET middleterm_dispatch_after=clock_timestamp()+INTERVAL '30 seconds'
                    FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
                ) SELECT c.* FROM candidate c JOIN reserved USING(session_id)");
                if (!$result) throw new RuntimeException('Unable to discover narrative owner.');
                $origin=pg_fetch_assoc($result);
                if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to reserve narrative discovery.');
            } finally {if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');}
            if (!$origin) return null;
            $jobId=synth_worker_with_origin($db,$origin,static fn()=>synth_middleterm_enqueue($db),'middleterm');
        }
        return $jobId===null ? null : synth_middleterm_run_job($db,$jobId,$generate);
    } catch (LogicException|SYNTHProtocolCancelled $error) {
        // Invalid frozen inputs must not remain pending forever; do not overwrite another live attempt.
        if ($jobId!==null) $db->execQuery("UPDATE synth_meta.worker_jobs j SET state=CASE WHEN ".($error instanceof SYNTHProtocolCancelled?'TRUE':'FALSE')."
            AND EXISTS(SELECT 1 FROM synth_meta.middleterm_inputs i WHERE i.id=j.middleterm_input_id AND i.regeneration_approval_id IS NOT NULL)
            THEN 'stale' ELSE 'failed' END,last_error='middleterm_input_invalid'
            WHERE id=$jobId AND job_type='middleterm' AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))");
        return null;
    }
}
