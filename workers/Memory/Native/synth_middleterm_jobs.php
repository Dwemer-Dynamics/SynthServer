<?php

require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_middleterm.php';
require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_middleterm_approval.php';
require_once dirname(__DIR__, 3).'/lib/synth_worker_origin.php';

// Revalidate the exact frozen source versions and prior chain while the caller owns a short request transaction.
function synth_middleterm_validate_input($db, array $row): array
{
    $input=synth_protocol_decode_json($row['input_payload']);$event=\Synth\Bootstrap::requestEvent()['json'];
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
    $digest=\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->digestMatches(intval($row['id']));
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
    $rows=\Synth\Core\DbManager\Dao\MiddletermSourceDao::current($db)->currentSources(synth_middleterm_history_cte($db,$event,$identity['display_name']),intval($row['id']),intval($row['actor_identity_id']));
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
    $lease=\Synth\Bootstrap::config('SYNTH_MIDDLETERM_JOB_LEASE');$event=\Synth\Bootstrap::requestEvent()['json'];
    if (microtime(true)<($lease['renew_after'] ?? 0)) return;
    $row=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->renewMiddletermLease(intval($lease['id']),strval($lease['token']),$event['session_id'],$event['request_id'],intval($event['generation']),strval($event['turn_id']),intval($event['context_sequence']));
    if (!$row) throw new SYNTHProtocolCancelled('middleterm_lease_lost');
    \Synth\Bootstrap::setAt('SYNTH_MIDDLETERM_JOB_LEASE', 'renew_after', microtime(true)+1);
}

// Bind the exact database connector fingerprint; changed settings require fresh admission, never silent retry drift.
function synth_middleterm_generate($db, array $input): string
{
    if (!$db->isTransactionIdle()) throw new LogicException('Narrative provider requires an idle database connection.');
    require_once dirname(__DIR__, 3).'/lib/core/llm_connector.class.php';
    $manager=new LLMConnector();$connector=$manager->getById($input['connector_id']);
    if (!$connector || !hash_equals($input['connector_sha256'],hash('sha256',synth_protocol_canonical_json($connector)))) {
        throw new LogicException('Middle-term connector changed after admission.');
    }
    $manager->setOldGlobals($connector);\Synth\Bootstrap::set('SYNTH_CORE_CURRENT_CONNECTOR_DATA', $connector);
    \Synth\Bootstrap::set('SYNTH_NAME', $input['identity']['display_name']);\Synth\Bootstrap::set('CURRENT_CONNECTOR', $connector['driver']);
    $context=synth_protocol_current_context($db,\Synth\Bootstrap::requestEvent()['json']);
    \Synth\Bootstrap::set('PLAYER_NAME', strval($context['player']['display_name'] ?? 'Player'));
    \Synth\Bootstrap::set('gameRequest', ['middleterm']);
    \Synth\Bootstrap::set('FORCE_MAX_TOKENS', 2048);\Synth\Bootstrap::set('COMMAND_PROMPT', '');\Synth\Bootstrap::set('BASE_FUNCTIONS', []);
    \Synth\Bootstrap::set('SYNTH_CLIENT_CAPABILITIES', []);\Synth\Bootstrap::set('DIRECT_NARRATOR_DIALOGUE', false);
    \Synth\Bootstrap::set('PATCH_PROMPT_ENFORCE_ACTIONS', false);\Synth\Bootstrap::set('COMMAND_PROMPT_ENFORCE_ACTIONS', '');
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
    $job=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->middletermRunnable($jobId);
    if (!$job) return null;
    return synth_worker_with_origin($db,$job,static function() use($db,$job,$jobId,$generate) {
        $token=bin2hex(random_bytes(16));
        $row=$db->withNativeRequestLock(static function($connection) use($db,$job,$jobId,$token) {
            $row=\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->findRow(intval($job['middleterm_input_id']));
            if (!$row) throw new LogicException('Native narrative input missing.');
            if (!\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->lockActor($row['actor_identity_id'])) {
                throw new RuntimeException('Unable to lock narrative claim.');
            }
            if ($row['regeneration_approval_id']!==null && synth_middleterm_regeneration_budget($db,intval($row['regeneration_approval_id']))['state']!=='ready') {
                \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->failMiddletermBudgetClosed($jobId);
                return null;
            }
            synth_middleterm_validate_input($db,$row);
            $claimed=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->claimMiddleterm($jobId,$token),'Unable to claim narrative job.');
            return $claimed ? $row : null;
        },2000);
        if (!$row) return null;
        \Synth\Bootstrap::set('SYNTH_MIDDLETERM_JOB_LEASE', ['id'=>$jobId,'token'=>$token]);
        try {
            $input=synth_protocol_decode_json($row['input_payload']);
            $text=trim($generate ? $generate($input) : synth_middleterm_generate($db,$input));
            if ($text==='' || strlen($text)>65536 || !mb_check_encoding($text,'UTF-8')) throw new LogicException('Invalid narrative output.');
            return $db->withNativeRequestLock(static function($connection,array $event) use($db,$row,$jobId,$token,$text) {
                if (!\Synth\Core\DbManager\Dao\MiddletermInputDao::current($db)->lockActor($row['actor_identity_id'])) {
                    throw new RuntimeException('Unable to lock narrative publication.');
                }
                synth_middleterm_validate_input($db,$row);
                $context=synth_protocol_current_context($db,$event);
                $published=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\MiddletermResultDao::current($db)->publish($jobId,$token,$row['playthrough_id'],
                    intval($context['payload']['world']['game_time_ticks'] ?? 0),time(),$event['session_id'],intval($event['generation']),
                    intval($event['context_sequence']),$event['request_id'],$row['input_sha256'],$text,intval($row['id']),
                    $row['regeneration_approval_id']===null ? null : intval($row['regeneration_approval_id'])),'Unable to publish native narrative memory.');
                if ($published===null) throw new SYNTHProtocolCancelled('middleterm_publication_lost');
                return $published;
            },2000);
        } catch (Throwable $error) {
            $terminal=$error instanceof LogicException || $error instanceof SYNTHProtocolCancelled;
            $readmit=$row['regeneration_approval_id']!==null && $error instanceof SYNTHProtocolCancelled;
            \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->settleMiddletermAttempt($jobId,$token,$readmit,$terminal);
            throw $error;
        } finally {\Synth\Bootstrap::forget('SYNTH_MIDDLETERM_JOB_LEASE');}
    },'middleterm');
}

// Input and durable job become visible together; a repeated terminal input cannot trigger fresh model calls.
function synth_middleterm_enqueue($db,?int $approvalId=null): ?int
{
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$approvalId) {
        $inputId=synth_middleterm_capture($db,$approvalId);
        if ($inputId===null) return null;
        $payload=json_encode(['schema'=>'synth.middleterm.job.v1','context_sequence'=>$event['context_sequence']],JSON_THROW_ON_ERROR);
        $jobId=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->enqueueMiddleterm($inputId,strval($event['turn_id']),$payload),'Unable to enqueue narrative memory.');
        if ($jobId===null) throw new RuntimeException('Unable to enqueue narrative memory.');
        return $jobId;
    },2000);
}

// One bounded manager pass recovers a due attempt or discovers one live completed owner; it never uses a legacy name scan.
function synth_middleterm_dispatch($db, ?callable $generate=null): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Narrative dispatch requires an idle database connection.');
    if (!\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->retireMiddletermJobs()) {
        throw new RuntimeException('Unable to retire narrative jobs.');
    }
    $jobId=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->nextDueMiddleterm();
    try {
        if ($jobId===null) {
            if ($db->execQuery('BEGIN')===false) throw new RuntimeException('Unable to begin narrative discovery.');
            try {
                if ($db->execQuery("SET LOCAL statement_timeout='2s'")===false) throw new RuntimeException('Unable to bound narrative discovery.');
                $origin=synth_middleterm_dao(static fn()=>\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->discoverMiddletermOwner(),'Unable to discover narrative owner.');
                if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to reserve narrative discovery.');
            } finally {if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');}
            if (!$origin) return null;
            $jobId=synth_worker_with_origin($db,$origin,static fn()=>synth_middleterm_enqueue($db),'middleterm');
        }
        return $jobId===null ? null : synth_middleterm_run_job($db,$jobId,$generate);
    } catch (LogicException|SYNTHProtocolCancelled $error) {
        // Invalid frozen inputs must not remain pending forever; do not overwrite another live attempt.
        if ($jobId!==null) \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->failInvalidMiddletermInput($jobId,$error instanceof SYNTHProtocolCancelled);
        return null;
    }
}
