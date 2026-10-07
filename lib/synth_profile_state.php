<?php

require_once __DIR__.'/synth_profile_scope.php';

// Every revision carries a full, bounded dynamic field set; base identity, voices and configuration remain separate.
function synth_profile_fields(array $profile): array
{
    $fields=[];
    foreach (['personality','occupation','skills','speechstyle','goals'] as $key) {
        $value=$profile[$key] ?? '';
        if (!is_string($value) || strlen($value)>16384 || str_contains($value,"\0") || !preg_match('//u',$value)) throw new InvalidArgumentException('Invalid native profile field.');
        $fields[$key]=$value;
    }
    return $fields;
}

// The saved frontier must contain the accepted result and its complete prior-revision chain.
function synth_profile_history_cte($db, array $event, int $actorId, bool $narrator=false, bool $narratorJournals=false, bool $journalInspection=false): string
{
    if ($narratorJournals ? ($actorId!==0 || $narrator) : ($journalInspection ? $actorId<0 : $actorId<1)) throw new InvalidArgumentException('Invalid profile history actor.');
    $role=$narrator ? 'narrator' : 'npc';
    if ($journalInspection && ($narrator || $narratorJournals)) throw new InvalidArgumentException('Invalid journal profile inspection.');
    $cte=synth_protocol_history_cte($db,$event,'',$narrator || $narratorJournals || $journalInspection ? null : $actorId,
        $narrator,$narratorJournals,$journalInspection ? $actorId : null);
    return $cte.", profile_candidates AS (
        SELECT v.* FROM native_history_scopes scope JOIN synth_meta.profile_revisions v
            ON v.session_id=scope.session_id AND v.generation=scope.generation
                AND v.actor_identity_id=scope.reader_identity_id AND ($actorId=0 OR v.actor_identity_id=$actorId) AND v.profile_role='$role'
        JOIN synth_meta.history_events h ON h.id=v.history_event_id AND h.id<=scope.cutoff
            AND h.session_id=v.session_id AND h.generation=v.generation AND h.context_sequence=v.context_sequence
            AND h.request_id=v.request_id AND h.playthrough_id=scope.playthrough_id
            AND h.event_payload->>'type'='profile_result' AND h.event_payload->>'delivery_state'='derived'
            AND h.event_payload->'profile_result'=v.result_fields
            AND h.event_payload->>'profile_result_sha256'=v.result_sha256
            AND h.event_payload->>'profile_input_sha256'=v.input_sha256
        JOIN synth_meta.request_executions e ON e.session_id=v.session_id AND e.request_id=v.request_id
            AND e.generation=v.generation AND e.event_type='profile_refresh' AND e.state='complete'
        JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
            AND p.canonical_event=v.input_payload->'owner' AND p.canonical_event->>'protocol_version'='2'
            AND p.canonical_event->>'context_sequence'=v.context_sequence::text
        JOIN synth_meta.context_snapshots origin ON origin.session_id=h.session_id AND origin.generation=h.generation
            AND origin.snapshot_sequence=h.context_sequence AND origin.playthrough_id=h.playthrough_id
        JOIN synth.actor_identities a ON a.id=v.actor_identity_id AND a.playthrough_id=h.playthrough_id AND a.game='fo4'
        WHERE v.input_sha256=encode(sha256(convert_to(v.input_payload::text,'UTF8')),'hex')
            AND v.result_sha256=encode(sha256(convert_to(v.result_fields::text,'UTF8')),'hex')
            AND v.input_payload->>'actor_identity_id'=v.actor_identity_id::text
            AND v.input_payload->>'profile_role'=v.profile_role
            AND v.input_payload->'prior_revision'=COALESCE(to_jsonb(v.prior_history_event_id),'null'::jsonb)
            AND ((v.profile_role='narrator' AND origin.player_identity_id=a.id AND p.canonical_event#>>'{payload,include_narrator}'='true')
                OR (v.profile_role='npc' AND (origin.target_identity_id=a.id OR origin.audience_identity_ids @> jsonb_build_array(a.id))
                    AND EXISTS(SELECT 1 FROM jsonb_array_elements(p.canonical_event#>'{payload,actors}') identity
                        WHERE upper(identity->>'form_id')=upper(a.full_form_id) AND lower(identity->>'origin_plugin')=lower(a.origin_plugin)
                            AND identity->>'playthrough_id'=h.playthrough_id)))
    ), native_profiles AS (
        SELECT c.*,1 AS depth FROM profile_candidates c WHERE prior_history_event_id IS NULL
        UNION ALL SELECT c.*,prior.depth+1 FROM profile_candidates c JOIN native_profiles prior
            ON c.prior_history_event_id=prior.history_event_id AND c.actor_identity_id=prior.actor_identity_id
                AND c.profile_role=prior.profile_role AND c.history_event_id>prior.history_event_id
    ) ";
}

// Return the latest saved profile while allowing other derived journals to verify exact prior profile revisions.
function synth_profile_visible($db, array $event, int $actorId, bool $narrator=false): ?array
{
    if ($actorId<1) return null;
    $row=$db->fetchOne(synth_profile_history_cte($db,$event,$actorId,$narrator)
        .' SELECT history_event_id,result_fields FROM native_profiles ORDER BY history_event_id DESC LIMIT 1');
    if (!$row) return null;
    $fields=synth_protocol_decode_json($row['result_fields']);
    $keys=array_keys($fields);sort($keys);$expected=array_keys(synth_profile_fields([]));sort($expected);
    if ($keys!==$expected) throw new RuntimeException('Native profile result fields are incomplete.');
    return ['revision'=>intval($row['history_event_id']),'fields'=>synth_profile_fields($fields)];
}

// Resolve current prompt state without ever writing an overlaid row back into the base profile table.
function synth_profile_effective_data($db, array $profile, bool $narrator=false): array
{
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || ($event['protocol_version'] ?? 1)!==2) return $profile;
    $actorId=intval($profile['actor_identity_id'] ?? 0);
    if ($narrator) {
        $context=synth_protocol_current_context($db,$event);
        if (!$context) return $profile;
        $actorId=intval($db->fetchOne('SELECT player_identity_id FROM synth_meta.context_snapshots WHERE session_id='.$db->escapeLiteral($event['session_id'])
            .' AND generation='.intval($event['generation']).' AND snapshot_sequence='.intval($event['context_sequence']))['player_identity_id'] ?? 0);
    }
    $state=synth_profile_visible($db,$event,$actorId,$narrator);
    return array_replace($profile,$state['fields'] ?? synth_profile_fields($profile));
}

// Freeze the base/prior profile used by every field request in this one worker attempt.
function synth_profile_capture_input($db, string $name, array $profile): array
{
    if (!isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) return $profile;
    synth_profile_assert_write_target($db,$name);
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];$narrator=$name==='The Narrator';
    $context=synth_protocol_current_context($db,$event);
    $actorId=$narrator ? intval($db->fetchOne('SELECT player_identity_id FROM synth_meta.context_snapshots WHERE session_id='.$db->escapeLiteral($event['session_id'])
        .' AND generation='.intval($event['generation']).' AND snapshot_sequence='.intval($event['context_sequence']))['player_identity_id'] ?? 0)
        : $GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'][$name]['actor_identity_id'];
    if (!$context || $actorId<1) throw new RuntimeException('Native profile input owner unavailable.');
    $state=synth_profile_visible($db,$event,$actorId,$narrator);
    $effective=array_replace($profile,$state['fields'] ?? synth_profile_fields($profile));
    $text=[];
    foreach (['core','npc_static_bio','background','appearance','prompt_head',...array_keys(synth_profile_fields([]))] as $key) {
        $value=$effective[$key] ?? '';
        if (!is_string($value) || strlen($value)>16384 || str_contains($value,"\0") || !preg_match('//u',$value)) throw new InvalidArgumentException('Invalid native profile context.');
        $text[$key]=$value;
    }
    $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$name]=['schema'=>'synth.profile.input.v1','owner'=>$event,
        'actor_identity_id'=>$actorId,'profile_role'=>$narrator ? 'narrator' : 'npc','baseline_fields'=>synth_profile_fields($profile),
        'prior_revision'=>$state['revision'] ?? null,'profile'=>$text,'history'=>null,'prompts'=>[],
        'connector_id'=>intval($GLOBALS['CORE_CONNECTOR_PROFILES'] ?? 0)];
    return $effective;
}

// Publish one accepted native result atomically; base NPC/Narrator configuration is never modified.
function synth_profile_record_result($db, string $name, array $updates): bool
{
    synth_profile_assert_write_target($db,$name);
    $input=$GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$name] ?? null;
    if (!is_array($input) || !is_string($input['history'] ?? null) || !$updates
        || array_diff(array_keys($updates),array_keys(synth_profile_fields([])))) throw new LogicException('Native profile result has no frozen input.');
    foreach ($updates as $field=>$value) {
        if (!is_string($value) || trim($value)==='' || strlen($value)>16384 || str_contains($value,"\0") || !preg_match('//u',$value)) throw new InvalidArgumentException('Invalid native profile output.');
        if (!is_string($input['prompts'][$field] ?? null)) throw new LogicException('Native profile field has no recorded prompt.');
    }
    $fields=synth_profile_fields(array_replace($input['profile'],$updates));
    $inputJson=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $fieldsJson=json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (strlen($inputJson)>1048576) throw new LengthException('Native profile input exceeds its bound.');
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$name,$input,$inputJson,$fieldsJson) {
        synth_profile_assert_write_target($db,$name);
        if (synth_protocol_canonical_json($event)!==synth_protocol_canonical_json($input['owner'])) throw new RuntimeException('Native profile result owner changed.');
        $actor=$input['actor_identity_id'];$role=$input['profile_role'];$narrator=$role==='narrator';
        $result=@pg_query_params($connection,'SELECT input_sha256=encode(sha256(convert_to(($4::jsonb)::text,\'UTF8\')),\'hex\')
                AND result_fields=$5::jsonb AS identical FROM synth_meta.profile_revisions
            WHERE session_id=$1 AND request_id=$2 AND actor_identity_id=$3 AND profile_role=$6',
            [$event['session_id'],$event['request_id'],$actor,$inputJson,$fieldsJson,$role]);
        if (!$result) throw new RuntimeException('Unable to check native profile replay.');
        if ($existing=pg_fetch_assoc($result)) {
            if ($existing['identical']!=='t') throw new LogicException('Native profile replay conflicts with accepted result.');
            return true;
        }
        // Narrator settings can be absent rows; a short table lock also excludes concurrent insertions.
        if ($narrator && !@pg_query($connection,'LOCK TABLE synth.core_narrator IN SHARE MODE')) throw new RuntimeException('Unable to lock narrator baseline.');
        $current=$narrator ? (new Narrator())->getAll() : $db->fetchOne('SELECT * FROM synth.core_npc_master WHERE id='.intval($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'][$name]['npc_id']));
        if (synth_profile_fields($current)!==synth_profile_fields($input['baseline_fields'])) throw new LogicException('Base profile changed during generation.');
        $visible=synth_profile_visible($db,$event,$actor,$narrator);
        if (($visible['revision'] ?? null)!==$input['prior_revision']) throw new LogicException('Saved profile input changed.');
        $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.profile_revisions v JOIN synth_meta.history_events h ON h.id=v.history_event_id
            JOIN synth_meta.context_snapshots c ON c.session_id=$1 AND c.generation=$2 AND c.snapshot_sequence=$3
            WHERE v.session_id=c.session_id AND v.generation=c.generation AND v.actor_identity_id=$4 AND v.profile_role=$5 AND h.id>c.history_event_id',
            [$event['session_id'],$event['generation'],$event['context_sequence'],$actor,$role]);
        if (!$result) throw new RuntimeException('Unable to verify native profile frontier.');
        if (pg_fetch_assoc($result)) throw new LogicException('A newer profile result awaits an acknowledged snapshot.');
        $context=synth_protocol_current_context($db,$event);$playthrough=$context['player']['playthrough_id'];
        $result=@pg_query_params($connection,"WITH value AS (SELECT $1::jsonb AS input,$2::jsonb AS fields), publication AS (
            INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('profile_result','Accepted native profile development',$3,$4,$5,$5,'','','','derived',$6,$7,$8,$9) RETURNING *
        ), history AS (
            INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$3,rowid,to_jsonb(publication)
                ||jsonb_build_object('profile_input_sha256',encode(sha256(convert_to(value.input::text,'UTF8')),'hex'),
                    'profile_result',value.fields,'profile_result_sha256',encode(sha256(convert_to(value.fields::text,'UTF8')),'hex'))
            FROM publication CROSS JOIN value RETURNING id
        ) INSERT INTO synth_meta.profile_revisions(history_event_id,session_id,request_id,generation,context_sequence,actor_identity_id,profile_role,
            prior_history_event_id,input_payload,input_sha256,result_fields,result_sha256)
            SELECT history.id,$6,$9,$7,$8,$10,$11,$12,value.input,encode(sha256(convert_to(value.input::text,'UTF8')),'hex'),value.fields,
                encode(sha256(convert_to(value.fields::text,'UTF8')),'hex') FROM history CROSS JOIN value",
            [$inputJson,$fieldsJson,$playthrough,intval($context['payload']['world']['game_time_ticks'] ?? 0),time(),$event['session_id'],
                $event['generation'],$event['context_sequence'],$event['request_id'],$actor,$role,$input['prior_revision']]);
        if (!$result) throw new RuntimeException('Unable to publish native profile result.');
        return true;
    },2000);
}
