<?php

require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_diary_state.php';

// Admission and its replayable reply commit together; queued is never a generation-complete claim.
function synth_diary_admit_request($db): string
{
    require_once dirname(__DIR__, 3).'/lib/response.php';
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
        if (!\Synth\Core\DbManager\Dao\RequestExecutionDao::current($db)->completeDiaryAdmission(strval($event['session_id']),strval($event['request_id']),$body)) {
            throw new LogicException('Diary admission was not claimed.');
        }
        return $body;
    },2000);
}

// The caller holds the active-generation lock: reserve one automatic role and consult only save-visible successful diaries.
function synth_diary_automation_available($db,array $event,array $binding,string $role,array $policy): bool
{
    if (!$policy['enabled']) return false;
    if ($event['payload']['reason']==='manual') return true;
    if (\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->diaryAdmissionPending(strval($event['session_id']),intval($event['generation']),
        intval($event['context_sequence']),intval($binding['actor_identity_id']),$role)) return false;
    $last=synth_diary_visible_role($db,$event,$binding['actor_identity_id'],$role,true);
    if ($last===null) return true;
    if ($last['published_at']<1) throw new LogicException('Diary cooldown timestamp unavailable.');
    return time()-$last['published_at'] >= $policy['cooldown_seconds'];
}

// Manual NPC diaries use their profile's diary connector; auto-diary toggles do not disable explicit requests.
function synth_diary_prepare_npc($db, array $identity): ?int
{
    require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_diary_config.php';
    require_once dirname(__DIR__, 3).'/lib/llm_randomizer.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$identity) {
        synth_diary_assert_owner($connection,$event);
        $binding=synth_diary_npc_binding($db,$event,$identity);
        // An admitted input owns its original settings even if configuration changes before a replay.
        $existing=\Synth\Core\DbManager\Dao\DiaryInputDao::current($db)->idForRequest(strval($event['session_id']),strval($event['request_id']),intval($binding['actor_identity_id']),'npc');
        if ($existing!==null) return $existing;
        $npc=synth_diary_lock_binding($db,$binding);
        $profile=\Synth\Core\DbManager\Dao\ProfileDao::current($db)->rowById(intval($npc['profile_id'] ?? 0));
        if (!$profile) throw new LogicException('Diary profile unavailable.');
        $automation=synth_diary_resolve_automation('npc',$event['payload']['reason'],$npc,$profile,$GLOBALS);
        if (!synth_diary_automation_available($db,$event,$binding,'npc',$automation)) return null;
        $settings=synth_diary_resolve_settings('npc',$npc,$profile,$GLOBALS,
            static fn(int $id)=>\Synth\Core\DbManager\Dao\LlmConnectorDao::current($db)->driverAndMaxTokens($id),
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
    require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_diary_config.php';
    require_once dirname(__DIR__, 3).'/lib/llm_randomizer.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db) {
        synth_diary_assert_owner($connection,$event);
        $binding=synth_diary_narrator_binding($db,$event);
        $existing=\Synth\Core\DbManager\Dao\DiaryInputDao::current($db)->idForRequest(strval($event['session_id']),strval($event['request_id']),intval($binding['actor_identity_id']),'narrator');
        if ($existing!==null) return $existing;
        $narrator=synth_diary_lock_binding($db,$binding);
        $automation=synth_diary_resolve_automation('narrator',$event['payload']['reason'],$narrator,[],$GLOBALS);
        if (!synth_diary_automation_available($db,$event,$binding,'narrator',$automation)) return null;
        $profile=\Synth\Core\DbManager\Dao\ProfileDao::current($db)->rowById(intval($narrator['profile_id'] ?? 0));
        if (!$profile) throw new LogicException('Narrator diary profile unavailable.');
        $settings=synth_diary_resolve_settings('narrator',$narrator,$profile,$GLOBALS,
            static fn(int $id)=>\Synth\Core\DbManager\Dao\LlmConnectorDao::current($db)->driverAndMaxTokens($id),
            static fn()=>null,intval(LLMRandomizer::getConnectorIdForField($profile,'diary_connector_id')));
        if ($settings===null) return null;
        return synth_diary_capture_narrator($db,$settings['prompt'],$settings['connector_id'],
            $settings['max_tokens'],$settings['history_limit'],$settings['prompt_head'],$automation);
    },2000);
}

// Player settings are global character configuration; the journal identity still comes only from the saved player.
function synth_diary_prepare_player($db): ?int
{
    require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_diary_config.php';
    require_once dirname(__DIR__, 3).'/lib/data_functions.php';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db) {
        synth_diary_assert_owner($connection,$event);
        $binding=synth_diary_player_owned_binding($db,$event,'player');
        $existing=\Synth\Core\DbManager\Dao\DiaryInputDao::current($db)->idForRequest(strval($event['session_id']),strval($event['request_id']),intval($binding['actor_identity_id']),'player');
        if ($existing!==null) return $existing;
        $player=synth_diary_lock_binding($db,$binding);
        $automation=synth_diary_resolve_automation('player',$event['payload']['reason'],$player,[],$GLOBALS);
        if (!synth_diary_automation_available($db,$event,$binding,'player',$automation)) return null;
        $settings=synth_diary_resolve_settings('player',$player,[],$GLOBALS,
            static fn(int $id)=>\Synth\Core\DbManager\Dao\LlmConnectorDao::current($db)->driverAndMaxTokens($id),
            static fn()=>synthResolveDiaryConnectorName(\Synth\Bootstrap::config('CONNECTORS_DIARY') ?? null,false));
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
        $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
        synth_diary_dao(static fn()=>$jobs->admitDiary($inputId,strval($event['turn_id'])),'Unable to admit diary job.');
        return intval($jobs->idForDiaryInput($inputId));
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
        foreach (['SYNTH_DIARY_JOB_LEASE','SYNTH_MEMORY_JOB_LEASE','SYNTH_PROFILE_JOB_LEASE','SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_INTERACTION_JOB_LEASE'] as $key) \Synth\Bootstrap::forget($key);
        \Synth\Bootstrap::set('db', $db);\Synth\Bootstrap::set('SYNTH_REQUEST_EVENT', ['json'=>$owner]);\Synth\Bootstrap::set('SYNTH_PROTOCOL_REQUEST_CLAIMED', true);
        synth_protocol_assert_request_active();
        return $work();
    } finally {
        foreach ($keys as $key) { if (array_key_exists($key,$previous)) \Synth\Bootstrap::set($key,$previous[$key]);else \Synth\Bootstrap::forget($key); }
    }
}

// Only frozen bounded messages can reach a provider; connector secrets remain live configuration.
function synth_diary_generate($db, array $input): array
{
    if (!$db->isTransactionIdle()) throw new LogicException('Diary provider requires an idle database connection.');
    synth_protocol_assert_request_active();
    require_once dirname(__DIR__, 3).'/lib/core/llm_connector.class.php';
    $selection=synth_diary_input_connector($input);
    if ($selection['kind']==='core') {
        $manager=new LLMConnector();$settings=$manager->getById($selection['id']);
        if (!$settings) throw new RuntimeException('Diary connector unavailable.');
        \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_CONNECTOR_DATA', $settings);$manager->setOldGlobals($settings);
        if ($input['diary_role']==='player') \Synth\Bootstrap::unsetPath('SYNTH_CORE_CURRENT_CONNECTOR_DATA', ['stop']);
        $driver=$settings['driver'];
    } else {
        // Never look up an arbitrary core record for a frozen legacy selection or inherit a previous worker's core metadata.
        \Synth\Bootstrap::forget('SYNTH_CORE_CURRENT_CONNECTOR_DATA');
        $driver=$selection['driver'];
        require_once dirname(__DIR__, 3).'/drivers/llm/LlmWrapper.php';
    }
    \Synth\Bootstrap::set('CURRENT_CONNECTOR', $driver);\Synth\Bootstrap::set('FORCE_MAX_TOKENS', $input['max_tokens']);
    \Synth\Bootstrap::set('COMMAND_PROMPT', '');\Synth\Bootstrap::set('BASE_FUNCTIONS', []);\Synth\Bootstrap::set('SYNTH_CLIENT_CAPABILITIES', []);\Synth\Bootstrap::set('DIRECT_NARRATOR_DIALOGUE', false);
    \Synth\Bootstrap::set('PATCH_PROMPT_ENFORCE_ACTIONS', false);\Synth\Bootstrap::set('COMMAND_PROMPT_ENFORCE_ACTIONS', '');
    \Synth\Bootstrap::set('SYNTH_NAME', $input['binding']['name']);\Synth\Bootstrap::set('PLAYER_NAME', $input['diary_role']==='player'
        ? ($input['character_name'] ?? $input['context']['player']['display_name']) : $input['context']['player']['display_name']);
    $reason=$input['owner']['payload']['reason'] ?? 'manual';
    \Synth\Bootstrap::set('gameRequest', [$input['diary_role']==='player' ? 'diary_player' : 'diary',time(),intval($input['context']['world']['game_time_ticks'] ?? 0),'Native '.$reason.' diary']);
    $handler=$selection['kind']==='core' ? $manager->getConnector($settings) : \Synth\Drivers\Llm\LlmWrapper::legacyConnector($driver);
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
    $job=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->diaryJobWithInput($jobId);
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
            $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
            if (!$jobs->lockAdvisoryKey('synth.diary.actor:'.$job['actor_identity_id'])) throw new RuntimeException('Unable to lock diary actor.');
            return synth_diary_dao(static fn()=>$jobs->claimDiary($jobId,bin2hex(random_bytes(16))),'Unable to claim diary job.');
        },2000);
        if (!$token) return false;
        \Synth\Bootstrap::set('SYNTH_DIARY_JOB_LEASE', ['id'=>$jobId,'token'=>$token]);
        try {
            $checkpoints=\Synth\Core\DbManager\Dao\DiaryJobResultDao::current($db);
            $saved=$checkpoints->checkpoint($jobId,strval($job['input_sha256']));
            if ($saved && $saved['valid']!=='t') throw new LogicException('Diary checkpoint hash mismatch.');
            $value=$saved ? json_decode($saved['result_payload'],true,flags:JSON_THROW_ON_ERROR)
                : ($generate ? $generate($input) : synth_diary_generate($db,$input));
            synth_diary_validate_result($value);
            if (!$saved) $db->withNativeRequestLock(static function($connection) use($db,$jobId,$job,$value) {
                if (!\Synth\Core\DbManager\Dao\DiaryJobResultDao::current($db)->insertCheckpoint($jobId,strval($job['input_sha256']),
                    json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))) throw new RuntimeException('Unable to checkpoint diary output.');
            },2000);
            require_once dirname(__DIR__, 3).'/managers/MemoryManager/Native/synth_memory_recall.php';
            $vector=$checkpoints->embeddingState($jobId);
            if (!$vector || $vector['valid']!=='t') throw new LogicException('Diary embedding checkpoint invalid.');
            if ($vector['embedding_attempted']!=='t') {
                $embedding=synth_memory_optional_embedding($value['content'],
                    $embed ?? ($generate===null ? 'synth_memory_fetch_embedding' : null));
                if ($embedding!==null) $embedding=synth_memory_vector_json($embedding);
                $embeddingTextHash=$embedding===null ? null : hash('sha256',$value['content']);
                $db->withNativeRequestLock(static function($connection) use($db,$jobId,$embedding,$embeddingTextHash) {
                    if (!\Synth\Core\DbManager\Dao\DiaryJobResultDao::current($db)->recordEmbedding($jobId,$embedding,$embeddingTextHash)) throw new RuntimeException('Unable to checkpoint diary embedding.');
                },2000);
            }
            $db->withNativeRequestLock(static function($connection) use($db,$job,$jobId,$value,$input) {
                $revision=synth_diary_publish_character($db,intval($job['diary_input_id']),$value,$job['input_sha256'],$input['diary_role']);
                // Preserve old checkpoints without inventing provenance; publish their accepted text without an unbound vector.
                if (!\Synth\Core\DbManager\Dao\DiaryResultDao::current($db)->attachCheckpointEmbedding($revision,$jobId,intval($job['diary_input_id']))) throw new RuntimeException('Unable to publish diary embedding.');
                if (!\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->markDiaryReady($jobId)) throw new RuntimeException('Unable to complete diary job.');
            },2000);
            return true;
        } catch (Throwable $error) {
            \Synth\Bootstrap::forget('SYNTH_DIARY_JOB_LEASE');
            $db->withNativeRequestLock(static function($connection) use($db,$jobId,$token,$error) {
                $terminal=$error instanceof LogicException || $error instanceof LengthException;
                if (!\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->failDiaryAttempt($jobId,$token,$terminal,
                    $terminal ? 'input_or_result_invalid' : 'attempt_failed')) throw new RuntimeException('Unable to retain diary failure.');
            },2000);
            Logger::warn('Native diary attempt did not complete; durable state retained.');
            return false;
        } finally { \Synth\Bootstrap::forget('SYNTH_DIARY_JOB_LEASE'); }
    });
}

// Unfinished admission waits; obsolete generations, cancelled owners and exhausted attempts retire without provider work.
function synth_diary_dispatch_jobs($db, ?callable $generate=null): int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Diary dispatch requires an idle database connection.');
    if (!\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->retireDiaryWork()) throw new RuntimeException('Unable to retire diary work.');
    $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->dispatchableDiaryJobs();
    $done=0;
    foreach ($jobs as $job) try { if (synth_diary_run_job($db,intval($job['id']),$generate)) ++$done; }
    catch (Throwable $error) {
        if ($error instanceof LogicException) \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->failInvalidDiary(intval($job['id']));
        Logger::warn('Native diary job unavailable; durable diagnostic retained.');
    }
    return $done;
}
