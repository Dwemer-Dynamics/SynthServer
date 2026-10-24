<?php

require_once __DIR__.'/relationship_runtime.php';
require_once __DIR__.'/request.php';
require_once __DIR__.'/synth_relationship_responder.php';

// Relationship directives are metadata, never partial words for subtitles or speech synthesis.
function synth_relationship_strip_commands(string $text): string
{
    return preg_replace('/#(?:REL|TYPE):(?:#PLAYER_NAME#|[^#])*(?:#|$)/i', '', $text);
}

// Sentence boundaries inside a directive must not expose its continuation as speech.
function synth_relationship_has_open_command(string $text): bool
{
    return preg_match('/#(?:REL|TYPE):(?:#PLAYER_NAME#|[^#])*$/i', $text) === 1;
}

// Bind in-process evidence to one claimed native request and its immutable context.
function synth_relationship_response_owner(): ?array
{
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null;
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || !is_array($event)
        || ($event['protocol_version'] ?? 1)!==2 || intval($event['context_sequence'] ?? 0)<1) return null;
    return array_intersect_key($event,array_flip(['session_id','request_id','turn_id','generation','context_sequence','runtime_variant']));
}

// Every provider attempt starts afresh, so a failed prefix cannot contaminate its successful fallback.
function synth_relationship_begin_response(): void
{
    unset($GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE']);
    $owner=synth_relationship_response_owner();
    if ($owner===null || !synthRelationshipSettingEnabled()) return;
    $GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE']=['owner'=>$owner,'attempt'=>bin2hex(random_bytes(16)),
        'entries'=>[],'bytes'=>0,'invalid'=>false,'accepted'=>false,
        'evaluation_mode'=>synthRelationshipUsesDedicatedConnector() ? 'dedicated' : 'inline'];
}

// Copy processed model speech before presentation cleanup, with a bounded all-or-nothing capture.
function synth_relationship_capture_line(string $speaker, string $text): ?string
{
    $capture=&$GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE'];
    if (!is_array($capture) || $capture['invalid'] || $capture['accepted']) return null;
    if ($capture['owner']!==synth_relationship_response_owner()) { unset($GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE']); return null; }
    $text=trim($text); $speaker=trim($speaker);
    if ($text==='') return null;
    if ($speaker==='' || strlen($speaker)>256 || !mb_check_encoding($speaker.$text,'UTF-8')
        || count($capture['entries'])>=256 || $capture['bytes']+strlen($text)>65536) {
        $capture['invalid']=true; $capture['entries']=[]; return null;
    }
    $capture['entries'][]=['speaker'=>$speaker,'text'=>$text];
    $capture['bytes']+=strlen($text);
    return $capture['attempt'].':'.(count($capture['entries'])-1);
}

// Attach only the final buffered speech route to its own line/attempt, never the next line or fallback.
function synth_relationship_capture_route(?string $lineToken, string $listener, string $utteranceId): void
{
    $capture=&$GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE'];
    if ($lineToken===null || !is_array($capture) || $capture['invalid'] || $capture['accepted']
        || $capture['owner']!==synth_relationship_response_owner()
        || !preg_match('/^([a-f0-9]{32}):([0-9]{1,3})$/D',$lineToken,$parts)
        || $parts[1]!==$capture['attempt'] || !isset($capture['entries'][intval($parts[2])])) return;
    $entry=&$capture['entries'][intval($parts[2])];
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (($event['payload']['kind'] ?? '')==='bored' && in_array('dialogue.ambient_npc',$event['capabilities'] ?? [],true)) {
        require_once __DIR__.'/synth_ambient_npc.php';
        $ambient=synth_ambient_npc_projection($event);
        if ($ambient!==null) $listener=$ambient['listener']['display_name'];
    }
    $route=['listener'=>trim($listener),'utterance_id'=>$utteranceId];
    if ($route['listener']==='' || strlen($listener)>256 || !mb_check_encoding($listener,'UTF-8')
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$utteranceId)
        || (array_key_exists('route',$entry) && $entry['route']!==$route)) {
        // Optional routing failure preserves speech evidence but cannot grant listener authority.
        $entry['route']=null; return;
    }
    $entry['route']=$route;
}

// Addressing is not hearing: only a unique canonical actor in this response's snapshot is an addressee.
function synth_relationship_resolve_route(array $speaker, ?array $route, array $context): array
{
    $unknown=['availability'=>'unavailable','listener'=>null,'utterance_id'=>null];
    if ($route===null || !isset($speaker['form_id'],$speaker['origin_plugin'],$speaker['playthrough_id'])) return $unknown;
    $label=strtolower(trim($route['listener'])); $matches=[];
    if (in_array($label,['the narrator','__player_text_only','__player_tts'],true)) return $unknown;
    $actors=in_array($label,['player','sole survivor','the sole survivor'],true) ? [$context['player']]
        : array_merge([$context['player'],$context['target'] ?? null],$context['audience'] ?? []);
    foreach ($actors as $actor) {
        if (!is_array($actor) || ($actor['playthrough_id'] ?? null)!==$speaker['playthrough_id']) continue;
        if (!in_array($label,['player','sole survivor','the sole survivor'],true)
            && strcasecmp(trim($actor['display_name'] ?? ''),$label)!==0) continue;
        $key=strtolower($actor['form_id'].'|'.$actor['origin_plugin'].'|'.$actor['playthrough_id']);
        $matches[$key]=$actor;
    }
    $speakerKey=strtolower($speaker['form_id'].'|'.$speaker['origin_plugin'].'|'.$speaker['playthrough_id']);
    if (count($matches)!==1 || isset($matches[$speakerKey])) return $unknown;
    return ['availability'=>'addressed','listener'=>reset($matches),'utterance_id'=>$route['utterance_id']];
}

// A provider return alone is not delivery proof; final protocol completion is required before storage.
function synth_relationship_finish_response(bool $accepted): void
{
    $capture=&$GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE'];
    if (!is_array($capture)) return;
    $capture['accepted']=$accepted && !$capture['invalid'] && $capture['entries']!==[]
        && $capture['owner']===synth_relationship_response_owner();
    if (!$capture['accepted']) $capture['entries']=[];
}

// Optional post-completion evidence: failures never change an already completed dialogue response.
function synth_relationship_store_response($db, array $event, string $body): void
{
    $capture=$GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE'] ?? null;
    if (empty($capture['accepted']) || $capture['owner']!==synth_relationship_response_owner()
        || !synthRelationshipSettingEnabled() || strlen($body)>2*1024*1024) return;
    try {
        // Optional metadata must neither abort nor commit a caller-owned transaction.
        if (!$db->isTransactionIdle()) return;
        $db->withNativeRequestLock(static function($connection,array $owner) use($db,$capture,$event,$body) {
            if (array_intersect_key($owner,$capture['owner'])!==$capture['owner'] || $owner!==$event) return;
            $result=@pg_query_params($connection,"SELECT c.target_identity_id,c.playthrough_id,p.canonical_event,p.event_sha256,
                    COALESCE((c.context_payload->'world'->>'game_time_ticks')::bigint,0) AS gamets
                FROM synth_meta.request_executions r JOIN synth_meta.context_snapshots c ON c.session_id=r.session_id
                    AND c.generation=r.generation AND c.snapshot_sequence=$3
                JOIN synth_meta.runtime_sessions s ON s.session_id=c.session_id AND s.playthrough_id=c.playthrough_id
                JOIN synth_meta.protocol_events p ON p.session_id=r.session_id AND p.request_id=r.request_id
                    AND p.generation=r.generation AND p.turn_id=r.turn_id AND p.event_type=r.event_type
                    AND p.event_sha256=r.request_sha256
                JOIN synth.actor_identities a ON a.id=c.target_identity_id AND a.playthrough_id=c.playthrough_id
                    AND a.game='fo4' AND upper(a.full_form_id)=upper(c.context_payload->'target'->>'form_id')
                    AND lower(a.origin_plugin)=lower(c.context_payload->'target'->>'origin_plugin')
                WHERE r.session_id=$1 AND r.request_id=$2 AND r.state='complete'
                    AND r.response_body=decode($4,'hex') AND c.history_event_id IS NOT NULL
                    AND r.turn_id=$5 AND r.generation=$6 AND s.runtime_variant=$7 AND s.game='fo4'",
                [$owner['session_id'],$owner['request_id'],$owner['context_sequence'],bin2hex($body),
                 $owner['turn_id'],$owner['generation'],$owner['runtime_variant']]);
            if (!$result) throw new RuntimeException('Unable to verify relationship response completion');
            $context=pg_fetch_assoc($result); if (!$context) return;
            $request=synth_protocol_decode_json($context['canonical_event']);
            $requestJson=synth_protocol_canonical_json($event);
            if (!is_array($request) || synth_protocol_canonical_json($request)!==$requestJson
                || !hash_equals($context['event_sha256'],hash('sha256',$requestJson))) return;
            // Text and STT use the actual processed input insertion, which legitimately has no delivery state.
            $inputs=[];
            if (in_array($event['type'],['input_text','input_audio'],true)) {
                $result=@pg_query_params($connection,"SELECT id,event_payload FROM synth_meta.history_events
                    WHERE session_id=$1 AND generation=$2 AND request_id=$3 AND context_sequence=$4
                        AND playthrough_id=$5 AND event_payload->>'type' IN ('inputtext','inputtext_s')
                    ORDER BY id LIMIT 2",[$owner['session_id'],$owner['generation'],$owner['request_id'],
                        $owner['context_sequence'],$context['playthrough_id']]);
                if (!$result) throw new RuntimeException('Unable to freeze processed relationship input');
                $rows=pg_fetch_all($result);
                if (count($rows)!==1) return;
                $input=json_decode($rows[0]['event_payload'],true,flags:JSON_THROW_ON_ERROR);
                if (!in_array($input['delivery_state'] ?? null,[null,'received'],true)) return;
                $inputs[]=['id'=>intval($rows[0]['id']),'event'=>$input];
            }
            $entries=[]; $speakers=[];
            $snapshot=synth_protocol_current_context($db,$event);
            if (!$snapshot) return;
            $responder=synth_relationship_responder($db,$event,$snapshot);
            if (!$responder) return;
            foreach ($capture['entries'] as $entry) {
                $name=$entry['speaker'];
                if (!array_key_exists($name,$speakers)) $speakers[$name]=synth_protocol_resolve_speaker_identity($db,$name);
                if ($speakers[$name]===null
                    || strcasecmp($speakers[$name]['form_id'] ?? '',$responder['identity']['form_id'])!==0
                    || strcasecmp($speakers[$name]['origin_plugin'] ?? '',$responder['identity']['origin_plugin'])!==0
                    || ($speakers[$name]['playthrough_id'] ?? null)!==$responder['identity']['playthrough_id']) return;
                $routing=synth_relationship_resolve_route($speakers[$name],$entry['route'] ?? null,$snapshot['payload']);
                if (($event['payload']['kind'] ?? '')==='bored' && in_array('dialogue.ambient_npc',$event['capabilities'] ?? [],true)) {
                    require_once __DIR__.'/synth_ambient_npc.php';
                    $routing=synth_ambient_npc_frozen_route($connection,$event,$speakers[$name],$entry['route'] ?? null,$body);
                }
                $entries[]=['speaker'=>$speakers[$name],'text'=>$entry['text'],'routing'=>$routing];
            }
            $payload=json_encode(['schema'=>'synth.relationship.response.v3','request'=>$request,
                'request_sha256'=>$context['event_sha256'],'input_events'=>$inputs,'entries'=>$entries,'evaluation_mode'=>$capture['evaluation_mode']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($payload)>131072) return;
            $hash=hash('sha256',$body);
            $existing=@pg_query_params($connection,'SELECT history_event_id FROM synth_meta.relationship_response_inputs
                WHERE session_id=$1 AND request_id=$2',[$owner['session_id'],$owner['request_id']]);
            if (!$existing) throw new RuntimeException('Unable to check relationship response replay');
            if (pg_fetch_assoc($existing)) return;
            $result=@pg_query_params($connection,"WITH publication AS (
                INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                    synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                VALUES('relationship_response','Accepted native model speech',$1,$2,$3,$3,'','','','derived',$4,$5,$6,$7) RETURNING *
            ), history AS (
                INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$1,rowid,to_jsonb(publication)
                    ||jsonb_build_object('response_sha256',$9::text,'response_payload_sha256',
                        encode(sha256(convert_to(($10::jsonb)::text,'UTF8')),'hex'))
                    FROM publication RETURNING id
            ) INSERT INTO synth_meta.relationship_response_inputs
                (history_event_id,session_id,request_id,actor_identity_id,response_sha256,response_payload)
                SELECT id,$4,$7,$8,$9,$10::jsonb FROM history RETURNING history_event_id",
                [$context['playthrough_id'],$context['gamets'],time(),$owner['session_id'],$owner['generation'],
                 $owner['context_sequence'],$owner['request_id'],$responder['actor_identity_id'],$hash,$payload]);
            if (!$result) throw new RuntimeException('Unable to freeze relationship response');
            $responseId=intval(pg_fetch_assoc($result)['history_event_id']);
            foreach ($inputs as $input) {
                if (@pg_query_params($connection,'INSERT INTO synth_meta.relationship_response_sources VALUES($1,$2)',
                    [$responseId,$input['id']])===false) throw new RuntimeException('Unable to freeze relationship input dependency');
            }
        },2000);
    } catch (SYNTHProtocolCancelled $error) {
        // Supersession after response completion does not authorize a late relationship capture.
    } catch (Throwable $error) {
        Logger::warn('[REL] Native response evidence unavailable');
    }
}

// Response emitters call this only after durable completion; optional bounded SQL never changes an emitted reply.
function synth_relationship_complete_inline_response($db): void
{
    $capture=$GLOBALS['SYNTH_RELATIONSHIP_RESPONSE_CAPTURE'] ?? null;
    if (empty($capture['accepted']) || ($capture['evaluation_mode'] ?? null)!=='inline'
        || $capture['owner']!==synth_relationship_response_owner() || !synthRelationshipSettingEnabled()
        || synthRelationshipUsesDedicatedConnector()) return;
    try {
        if (!$db->isTransactionIdle()) return;
        require_once __DIR__.'/synth_relationship.php';
        synth_relationship_apply_inline_response($db);
    } catch (SYNTHProtocolCancelled $error) {
        // Supersession cancels optional work without replacing the already emitted dialogue response.
    } catch (Throwable $error) {
        Logger::warn('[REL] Native inline relationship update unavailable');
    }
}

// The current-turn exception is for proposal admission only; saved readers retain their insertion cutoff.
// Normal cancellation clears delivery replay bytes, not an already accepted historical speech receipt.
// Only retained completed player inputs may use that receipt, and never beyond the saved cutoff.
function synth_relationship_response_evidence_cte($db, array $event, bool $admitCurrentResponse=false, bool $relationshipGraph=false): string
{
    $scopes=$relationshipGraph ? 'relationship_graph_scopes' : 'native_history_scopes';
    $evidence=$relationshipGraph ? 'relationship_graph_responses' : 'relationship_response_evidence';
    $current=$admitCurrentResponse ? '(h.session_id='.$db->escapeLiteral($event['session_id'])
        .' AND h.generation='.intval($event['generation']).' AND h.request_id='.$db->escapeLiteral($event['request_id'])
        .' AND h.context_sequence='.intval($event['context_sequence']).')' : 'FALSE';
    // Resolve retained response history once before expanding saved reader scopes.
    return ", {$evidence}_history AS MATERIALIZED (
        SELECT h.* FROM synth_meta.history_events h
        JOIN synth_meta.relationship_response_inputs input ON input.history_event_id=h.id
        WHERE EXISTS (SELECT 1 FROM $scopes scope
            WHERE scope.session_id=h.session_id AND scope.generation=h.generation
                AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=input.actor_identity_id
                AND (h.id<=scope.cutoff OR $current))
    ), $evidence AS MATERIALIZED (
        SELECT i.*,h.generation,h.context_sequence,h.playthrough_id FROM synth_meta.relationship_response_inputs i
        JOIN {$evidence}_history h ON h.id=i.history_event_id AND h.session_id=i.session_id AND h.request_id=i.request_id
        JOIN $scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=i.actor_identity_id
            AND (h.id<=scope.cutoff OR $current)
        JOIN synth_meta.context_snapshots c ON c.session_id=h.session_id AND c.generation=h.generation
            AND c.snapshot_sequence=h.context_sequence AND c.playthrough_id=h.playthrough_id
        JOIN synth_meta.request_executions e ON e.session_id=h.session_id AND e.request_id=h.request_id AND e.generation=h.generation
            AND e.state='complete' AND (encode(sha256(e.response_body),'hex')=i.response_sha256
                OR (e.event_type IN ('input_text','input_audio') AND e.completed_at IS NOT NULL AND e.response_body IS NULL
                    AND e.error_code IN ('turn_cancelled','request_cancelled') AND h.id<=scope.cutoff))
        JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
        WHERE h.event_payload->>'type'='relationship_response' AND h.event_payload->>'delivery_state'='derived'
            AND ".synth_relationship_responder_sql('p.canonical_event','i.actor_identity_id',synth_relationship_responder_context_sql('p.canonical_event'))."
            AND i.response_sha256=h.event_payload->>'response_sha256'
            AND encode(sha256(convert_to(i.response_payload::text,'UTF8')),'hex')=h.event_payload->>'response_payload_sha256'
            AND i.response_payload->>'schema' IN ('synth.relationship.response.v2','synth.relationship.response.v3')
            AND i.response_payload->'request'=p.canonical_event AND i.response_payload->>'request_sha256'=p.event_sha256
            AND p.canonical_event->>'session_id'=h.session_id AND p.canonical_event->>'request_id'=h.request_id
            AND p.canonical_event->>'generation'=h.generation::text AND p.canonical_event->>'turn_id'=e.turn_id
            AND p.canonical_event->>'type'=e.event_type
            AND p.canonical_event->>'context_sequence'=h.context_sequence::text
            AND p.canonical_event->>'runtime_variant'=".$db->escapeLiteral($event['runtime_variant'])."
            AND jsonb_typeof(i.response_payload->'input_events')='array'
            AND CASE WHEN jsonb_typeof(i.response_payload->'input_events')='array' THEN
                jsonb_array_length(i.response_payload->'input_events')=CASE WHEN e.event_type IN ('input_text','input_audio') THEN 1 ELSE 0 END
                AND jsonb_array_length(i.response_payload->'input_events')=(SELECT count(*) FROM synth_meta.relationship_response_sources d
                    WHERE d.response_history_event_id=i.history_event_id)
                AND NOT EXISTS (
                    SELECT 1 FROM jsonb_array_elements(i.response_payload->'input_events') item
                    WHERE NOT EXISTS (
                        SELECT 1 FROM synth_meta.relationship_response_sources d JOIN synth_meta.history_events raw ON raw.id=d.source_history_event_id
                        WHERE d.response_history_event_id=i.history_event_id AND to_jsonb(raw.id)=item->'id' AND raw.id<h.id
                            AND raw.session_id=h.session_id AND raw.generation=h.generation AND raw.request_id=h.request_id
                            AND raw.context_sequence=h.context_sequence AND raw.playthrough_id=h.playthrough_id
                            AND raw.event_payload=item->'event' AND raw.event_payload->>'type' IN ('inputtext','inputtext_s')
                            AND (raw.event_payload->>'delivery_state' IS NULL OR raw.event_payload->>'delivery_state'='received')
                    )
                ) ELSE FALSE END
    ) ";
}
