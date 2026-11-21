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
    if (!isset(\Synth\Bootstrap::config('SYNTH_NATIVE_PROFILE_INPUTS')[$name]) || !$fields
        || array_diff($fields,array_keys(synth_profile_fields([])))) throw new LogicException('Invalid native profile field selection.');
    require_once dirname(__DIR__, 3).'/lib'.'/synth_biography.php';
    // An NPC selection left empty by the expertise policy has no native field to generate.
    if ($name!=='The Narrator' && !($fields=synth_biography_dynamic_npc_fields($fields)))
        throw new LogicException('Invalid native profile field selection.');
    $fields=array_values(array_unique($fields));
    foreach ($fields as $field) if (updateDynamicProfileField($name,$field,$history,true)!==true) {
        throw new RuntimeException('Native profile prompt preparation failed.');
    }
    \Synth\Bootstrap::setPath('SYNTH_NATIVE_PROFILE_INPUTS', [$name, 'fields'], $fields);
    return \Synth\Bootstrap::config('SYNTH_NATIVE_PROFILE_INPUTS')[$name];
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
                $actor=$narrator ? \Synth\Core\DbManager\Dao\RuntimeContextDao::current($db)->playerIdentityId(strval($event['session_id']),intval($event['generation']),intval($event['context_sequence']))
                    : $queue['native_bindings'][$name]['actor_identity_id'];
                if ($actor<1) throw new LogicException('Native profile job has no actor.');
                // Each durable job has one actor: its frozen label can safely address the legacy prompt facade.
                $label=$narrator ? 'The Narrator' : $binding['identity']['display_name'];
                $payload=['schema'=>'synth.profile.job.v1','context_sequence'=>$event['context_sequence'],
                    'profile_role'=>$narrator ? 'narrator' : 'npc','native_owner'=>$event,'npcs'=>[$label],
                    'native_bindings'=>[$label=>$binding],'gameRequest'=>$queue['gameRequest']];
                \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->insertProfileJob(strval($event['session_id']),strval($event['request_id']),strval($event['turn_id']),intval($event['generation']),
                    $actor,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            }
            if (!\Synth\Managers\ConfigManager\ConfigManager::shared()->delete('conf',$queueId)) throw new RuntimeException('Unable to finish profile queue transfer.');
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
    $connector=new LLMConnector();$settings=$connector->getById($input['connector_id']);
    if (!$settings) throw new RuntimeException('Native profile connector unavailable.');
    \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_CONNECTOR_DATA', $settings);$connector->setOldGlobals($settings);
    \Synth\Bootstrap::set('CURRENT_CONNECTOR', $settings['driver']);
    \Synth\Bootstrap::set('FORCE_MAX_TOKENS', $input['options'][$field]['max_tokens']);
    \Synth\Bootstrap::set('COMMAND_PROMPT', '');\Synth\Bootstrap::set('BASE_FUNCTIONS', []);\Synth\Bootstrap::set('SYNTH_CLIENT_CAPABILITIES', []);
    \Synth\Bootstrap::set('DIRECT_NARRATOR_DIALOGUE', false);
    $handler=$connector->getConnector($settings);
    $text=$handler->fast_request(json_decode($input['prompts'][$field],true,flags:JSON_THROW_ON_ERROR),$input['options'][$field],'profile');
    if (!is_string($text)) throw new RuntimeException('Native profile provider returned no text.');
    return $text;
}

// Claim/recover a fenced actor attempt, checkpoint each field, then publish the complete selected set atomically.
function synth_profile_run_job($db, int $jobId, ?callable $prepare=null, ?callable $generate=null): bool
{
    if (!$db->isTransactionIdle()) throw new LogicException('Profile worker requires an idle database connection.');
    $job=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->activeProfileJob($jobId);
    if (!$job) return false;
    $queue=json_decode($job['payload'],true,flags:JSON_THROW_ON_ERROR);$done=false;
    synth_profile_with_origin($db,$queue,static function() use($db,$job,$jobId,$queue,$prepare,$generate,&$done) {
        $token=$db->withNativeRequestLock(static function($connection,array $event) use($db,$job,$jobId,$queue) {
            if ($job['session_id']!==$event['session_id'] || $job['request_id']!==$event['request_id']
                || intval($job['generation'])!==$event['generation'] || $job['turn_id']!==$event['turn_id']
                || $queue['context_sequence']!==$event['context_sequence']) throw new LogicException('Profile job owner mismatch.');
            if (count($queue['npcs'])!==1) throw new LogicException('Profile job requires exactly one actor.');
            $name=$queue['npcs'][0];$narrator=$name==='The Narrator';
            $actor=$narrator ? \Synth\Core\DbManager\Dao\RuntimeContextDao::current($db)->playerIdentityId(strval($event['session_id']),intval($event['generation']),intval($event['context_sequence']))
                : \Synth\Bootstrap::config('SYNTH_NATIVE_PROFILE_BINDINGS')[$name]['actor_identity_id'];
            if (intval($job['actor_identity_id'])!==$actor || $queue['profile_role']!==($narrator ? 'narrator' : 'npc')) throw new LogicException('Profile job actor mismatch.');
            \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->lockProfileActor(intval($job['actor_identity_id']));
            return \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->claimProfileAttempt($jobId,bin2hex(random_bytes(16)));
        },2000);
        if (!$token) return;
        \Synth\Bootstrap::set('SYNTH_PROFILE_JOB_LEASE', ['id'=>$jobId,'token'=>$token]);$name=$queue['npcs'][0];
        try {
            synth_profile_assert_write_target($db,$name);
            \Synth\Bootstrap::set('SYNTH_NAME', $name);\Synth\Bootstrap::set('gameRequest', $queue['gameRequest']);
            $row=\Synth\Core\DbManager\Dao\CharacterGenerationDao::current($db)->input($jobId);
            if ($row && !synthPostgresBoolean($row['valid'])) throw new LogicException('Frozen profile input hash mismatch.');
            $input=$row ? json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR)
                : ($prepare ? $prepare($name,$queue['gameRequest']) : processSingleDynamicProfile($name,$queue['gameRequest'],true));
            if ($input===false) {
                $db->withNativeRequestLock(static function($connection) use($db,$jobId) {
                    \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->finishDisabledProfile($jobId);
                },2000);
                return;
            }
            synth_profile_validate_input($input,$queue['native_owner'],intval($job['actor_identity_id']),$queue['profile_role']);
            if (!$row) $db->withNativeRequestLock(static function($connection) use($db,$jobId,$input) {
                $json=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                \Synth\Core\DbManager\Dao\CharacterGenerationDao::current($db)->freezeInput($jobId,$json);
            },2000);
            \Synth\Bootstrap::setAt('SYNTH_NATIVE_PROFILE_INPUTS', $name, $input);$updates=[];
            $db->withNativeRequestLock(static function($connection,array $event) use($db,$name,$input) {
                synth_profile_assert_write_target($db,$name);
                if ($name==='The Narrator') {
                    $base=(new Narrator())->getAll();
                } else $base=\Synth\Core\DbManager\Dao\NpcDao::current($db)->row(intval(\Synth\Bootstrap::config('SYNTH_NATIVE_PROFILE_BINDINGS')[$name]['npc_id']));
                if (synth_profile_fields($base)!==synth_profile_fields($input['baseline_fields'])
                    || (synth_profile_visible($db,$event,$input['actor_identity_id'],$name==='The Narrator')['revision'] ?? null)!==$input['prior_revision']) {
                    throw new LogicException('Frozen profile baseline no longer valid.');
                }
            },2000);
            foreach ($input['fields'] as $field) {
                $checkpoint=\Synth\Core\DbManager\Dao\CharacterGenerationFieldDao::current($db)->checkpoint($jobId,$field);
                if ($checkpoint) {
                    if (!synthPostgresBoolean($checkpoint['valid'])) throw new LogicException('Profile field checkpoint hash mismatch.');
                    $updates[$field]=$checkpoint['result_text'];continue;
                }
                $db->withNativeRequestLock(static function($connection) use($db,$jobId) {
                    \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->renewProfileLease($jobId);
                },2000);
                $text=$generate ? $generate($input,$field) : synth_profile_generate_field($db,$input,$field);
                if (!is_string($text) || trim($text)==='') throw new RuntimeException('Empty profile field output.');
                $text=trim($text);synth_profile_fields([$field=>$text]);
                $db->withNativeRequestLock(static function($connection) use($db,$name,$jobId,$field,$text) {
                    synth_profile_assert_write_target($db,$name);
                    \Synth\Core\DbManager\Dao\CharacterGenerationFieldDao::current($db)->record($jobId,$field,$text);
                },2000);
                $updates[$field]=$text;
            }
            $db->withNativeRequestLock(static function($connection) use($db,$name,$updates,$jobId) {
                if (!synth_profile_record_result($db,$name,$updates)) throw new RuntimeException('Unable to publish complete profile.');
                \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->finishProfile($jobId);
            },2000);
            $done=true;
        } catch (Throwable $error) {
            \Synth\Bootstrap::forget('SYNTH_PROFILE_JOB_LEASE');
            // A late attempt cannot change a replacement token. Persist only fixed, non-sensitive error codes.
            $db->withNativeRequestLock(static function($connection) use($db,$jobId,$token,$error) {
                $terminal=$error instanceof LogicException || $error instanceof InvalidArgumentException || $error instanceof LengthException;
                \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->recordProfileFailure($jobId,strval($token),$terminal,$terminal ? 'input_or_result_invalid' : 'attempt_failed');
            },2000);
            Logger::warn('Native profile attempt did not complete; durable state retained.');
        } finally { \Synth\Bootstrap::forget('SYNTH_PROFILE_JOB_LEASE'); }
    });
    return $done;
}

// The scheduled service recovers expired attempts, retires old lifecycles, and bounds work per tick.
function synth_profile_dispatch_jobs($db, ?callable $prepare=null, ?callable $generate=null): int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Profile dispatch requires an idle database connection.');
    $workerJobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
    $workerJobs->retireProfileWork();
    $jobs=$workerJobs->dueProfileJobIds(5);
    $done=0;
    foreach ($jobs as $jobId) try { if (synth_profile_run_job($db,$jobId,$prepare,$generate)) ++$done; }
    catch (Throwable $error) {
        if (synth_profile_origin_rejected($error) || $error instanceof LogicException) {
            $workerJobs->markProfileStale($jobId);
        }
        Logger::warn('Native profile job unavailable; durable diagnostic retained.');
    }
    return $done;
}
