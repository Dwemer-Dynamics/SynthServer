<?php

require_once __DIR__.'/synth_protocol_state.php';

// A proven move ends this continuation; it does not authorize a new physical scene.
final class SYNTHRechatSceneChanged extends LogicException {}

// Resolve only the explicitly named scene under its actual request owner.
// Parent response proof and responder admission remain separate, mandatory gates.
function synth_rechat_scene_context($db, array $event): ?array
{
    return $db->withNativeRequestLock(static function($connection,array $owner) use($event) {
        if ($owner!==$event) throw new LogicException('rechat_scene_owner_changed');
        return synth_rechat_response_scene_locked($connection,$event);
    },2000);
}

// Action replies inherit the scene of their proven emitted action, not a later capture or the root audience.
function synth_rechat_response_scene_locked($connection, array $event): ?array
{
    if (!in_array('dialogue.rechat.scene',$event['capabilities'] ?? [],true)) return null;
    if (($event['type'] ?? '')==='action_result') {
        require_once __DIR__.'/synth_rechat_admission.php';
        $event=synth_rechat_action_chain_origin($connection,$event,$event);
    }
    if (!synth_protocol_owns_rechat($event)) return null;
    return synth_rechat_scene_context_locked($connection,$event);
}

// Historical consumers already hold the live owner's lock; never impersonate an ancestor in request globals.
function synth_rechat_scene_context_locked($connection, array $event): array
{
    if (!synth_protocol_owns_rechat($event) || !in_array('dialogue.rechat.scene',$event['capabilities'] ?? [],true)
        || !is_int($event['payload']['scene_context_sequence'] ?? null)
        || $event['payload']['scene_context_sequence']<=($event['context_sequence'] ?? 0))
        throw new LogicException('rechat_scene_reference_invalid');
    $canonical=synth_protocol_canonical_json($event);
    $result=@pg_query_params($connection,"SELECT fresh.context_payload,previous.context_payload AS previous_context,
            p.canonical_event AS parent_event
        FROM synth_meta.context_snapshots fresh
        JOIN synth_meta.runtime_sessions s ON s.session_id=fresh.session_id
            AND s.active_generation=fresh.generation AND s.playthrough_id=fresh.playthrough_id AND s.state='active'
        JOIN synth_meta.request_executions child ON child.session_id=s.session_id
            AND child.request_id=$5 AND child.generation=fresh.generation AND child.turn_id=$4
        JOIN synth_meta.protocol_events cp ON cp.session_id=child.session_id AND cp.request_id=child.request_id
            AND cp.generation=child.generation AND cp.turn_id=child.turn_id AND cp.event_type=child.event_type
            AND cp.event_sha256=child.request_sha256 AND cp.canonical_event=$6::jsonb AND cp.event_sha256=$9
        JOIN synth_meta.protocol_events p ON p.session_id=s.session_id AND p.request_id=$7
            AND p.generation=child.generation AND p.turn_id=child.turn_id
            AND p.canonical_event->>'context_sequence'=$8::text
        JOIN synth_meta.request_executions parent ON parent.session_id=p.session_id AND parent.request_id=p.request_id
            AND parent.generation=p.generation AND parent.turn_id=p.turn_id AND parent.event_type=p.event_type
            AND parent.request_sha256=p.event_sha256 AND parent.state='complete'
        JOIN synth_meta.context_snapshots previous ON previous.session_id=fresh.session_id
            AND previous.generation=fresh.generation AND previous.playthrough_id=fresh.playthrough_id
            AND previous.player_identity_id=fresh.player_identity_id
            AND previous.snapshot_sequence=COALESCE((p.canonical_event#>>'{payload,scene_context_sequence}')::bigint,$8::bigint)
        WHERE fresh.session_id=$1 AND fresh.generation=$2 AND fresh.snapshot_sequence=$3
            AND fresh.snapshot_sequence>previous.snapshot_sequence AND s.runtime_variant=$10
            AND child.state IN ('claimed','streaming','complete')
            AND fresh.received_at BETWEEN child.claimed_at-interval '10 seconds' AND child.claimed_at",
        [$event['session_id'],$event['generation'],$event['payload']['scene_context_sequence'],$event['turn_id'],
         $event['request_id'],$canonical,$event['payload']['reply_to_request_id'],$event['context_sequence'],
         hash('sha256',$canonical),$event['runtime_variant']]);
    if (!$result) throw new RuntimeException('rechat_scene_query_failed');
    $row=pg_fetch_assoc($result);
    if (!$row) throw new LogicException('rechat_scene_unavailable');
    $payload=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
    $previous=json_decode($row['previous_context'],true,flags:JSON_THROW_ON_ERROR);
    $parent=json_decode($row['parent_event'],true,flags:JSON_THROW_ON_ERROR);
    if (($parent['type'] ?? '')==='action_result') {
        require_once __DIR__.'/synth_rechat_admission.php';
        $origin=synth_rechat_action_chain_origin($connection,$event,$parent);
        $sequence=$origin['payload']['scene_context_sequence'] ?? $event['context_sequence'];
        if (!is_int($sequence) || $sequence>=$event['payload']['scene_context_sequence'])
            throw new LogicException('rechat_scene_unavailable');
        $result=@pg_query_params($connection,"SELECT previous.context_payload
            FROM synth_meta.context_snapshots previous JOIN synth_meta.context_snapshots fresh
                ON fresh.session_id=previous.session_id AND fresh.generation=previous.generation
                AND fresh.playthrough_id=previous.playthrough_id AND fresh.player_identity_id=previous.player_identity_id
            WHERE previous.session_id=$1 AND previous.generation=$2 AND previous.snapshot_sequence=$3
                AND fresh.snapshot_sequence=$4",
            [$event['session_id'],$event['generation'],$sequence,$event['payload']['scene_context_sequence']]);
        if (!$result) throw new RuntimeException('rechat_scene_query_failed');
        $row=pg_fetch_assoc($result);
        if (!$row) throw new LogicException('rechat_scene_unavailable');
        $previous=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
    }
    // Normalize only reference spelling; labels and actor names do not establish physical identity.
    $sceneKey=static function(array $context): ?array {
        $world=$context['world'] ?? [];
        $scene=$world['scene'] ?? null;
        if (!is_array($scene) || !is_bool($world['interior'] ?? null)) return null;
        $key=[$world['interior']];
        foreach (['cell','worldspace'] as $kind) {
            $ref=$scene[$kind] ?? null;
            if ($kind==='worldspace' && $world['interior']) {
                if ($ref!==null) return null;
                $key[]=null; continue;
            }
            if (!is_array($ref) || !is_string($ref['form_id'] ?? null) || !is_string($ref['origin_plugin'] ?? null)
                || $ref['form_id']==='0x00000000' || $ref['origin_plugin']==='') return null;
            $key[]=[strtoupper($ref['form_id']),strtolower($ref['origin_plugin'])];
        }
        return $key;
    };
    $scene=$sceneKey($payload);
    $previousScene=$sceneKey($previous);
    $player=synth_rechat_actor_key($payload['player'] ?? []);
    $target=synth_rechat_actor_key($payload['target'] ?? []);
    if ($scene===null || $previousScene===null
        || $player===null || $player!==synth_rechat_actor_key($previous['player'] ?? [])
        || $target===null || $target!==synth_rechat_actor_key($event['payload']['actor'] ?? [])
        || !in_array($payload['audience_observation'] ?? '',['complete','partial'],true))
        throw new LogicException('rechat_scene_changed');
    if ($scene!==$previousScene) throw new SYNTHRechatSceneChanged('rechat_scene_changed');
    return ['payload'=>$payload,'player'=>$payload['player'],'target'=>$payload['target'],
        'scene_context_sequence'=>$event['payload']['scene_context_sequence']];
}

// Canonical keys preserve case-sensitive save identity; labels never grant actor ownership.
function synth_rechat_actor_key(array $actor): ?string
{
    foreach (['form_id','origin_plugin','playthrough_id'] as $field) {
        if (!is_string($actor[$field] ?? null) || $actor[$field]==='') return null;
    }
    return strtoupper($actor['form_id']).'|'.strtolower($actor['origin_plugin']).'|'.$actor['playthrough_id'];
}

// A route may use a unique captured label or a captured reference, never a latest/global NPC lookup.
function synth_rechat_resolve_listener(array $speaker, array $metadata, array $context): array
{
    $unknown=['availability'=>'unavailable','listener'=>null];
    $speakerKey=synth_rechat_actor_key($speaker);
    if ($speakerKey===null) return $unknown;
    $label=trim(strval($metadata['listener'] ?? $metadata['metadata']['listener'] ?? ''));
    $form=trim(strval($metadata['listener_formid'] ?? $metadata['metadata']['listener_formid'] ?? ''));
    $plugin=trim(strval($metadata['listener_plugin'] ?? $metadata['metadata']['listener_plugin'] ?? ''));
    if (($label==='' && $form==='') || strlen($label)>256 || !mb_check_encoding($label,'UTF-8')
        || in_array(strtolower($label),['__player_text_only','__player_tts'],true)
        || (strtolower($label)==='the narrator' && $form==='')
        || ($form!=='' && !preg_match('/^0x[0-9a-f]{8}$/iD',$form))
        || ($plugin!=='' && $form==='')) return $unknown;
    $playerAlias=in_array(strtolower($label),['player','sole survivor','the sole survivor'],true);
    $actors=$playerAlias && $form==='' ? [$context['player'] ?? null]
        : array_merge([$context['player'] ?? null,$context['target'] ?? null],$context['audience'] ?? []);
    $matches=[];
    foreach ($actors as $actor) {
        if (!is_array($actor) || ($actor['playthrough_id'] ?? null)!==$speaker['playthrough_id']) continue;
        $key=synth_rechat_actor_key($actor);
        if ($key===null || ($form!=='' && strcasecmp($form,$actor['form_id'])!==0)
            || ($plugin!=='' && strcasecmp($plugin,$actor['origin_plugin'])!==0)
            || ($label!=='' && !($playerAlias && $key===synth_rechat_actor_key($context['player'] ?? []))
                && strcasecmp($label,trim($actor['display_name'] ?? ''))!==0)) continue;
        $matches[$key]=$actor;
    }
    if (count($matches)>1) return ['availability'=>'ambiguous','listener'=>null];
    if (count($matches)!==1 || isset($matches[$speakerKey])) return $unknown;
    return ['availability'=>'addressed','listener'=>reset($matches)];
}

// Correlate text-only and voiced lines alike; conflicting IDs must never join history to another sentence.
function synth_rechat_route_utterance(array $line, array $metadata): ?string
{
    if (array_key_exists('speech',$line['payload'] ?? [])
        && (!is_string($line['payload']['speech']['utterance_id'] ?? null)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$line['payload']['speech']['utterance_id']))) {
        throw new LogicException('dialogue_route_utterance_invalid');
    }
    $ids=[];
    foreach ([$line['payload']['speech']['utterance_id'] ?? null,$metadata['utterance_id'] ?? null,
        $metadata['metadata']['utterance_id'] ?? null] as $id) {
        if ($id===null || $id==='') continue;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$id)) {
            throw new LogicException('dialogue_route_utterance_invalid');
        }
        $ids[$id]=true;
    }
    if (count($ids)>1) throw new LogicException('dialogue_route_utterance_mismatch');
    return $ids===[] ? null : strval(array_key_first($ids));
}

// Direct player replies have an authoritative initiator even when the model omits its listener.
// Keep rechat, ambiguous names, and explicit reference failures fail-closed.
function synth_response_listener_route(array $speaker, array $metadata, array $context, array $event): array
{
    $route=synth_rechat_resolve_listener($speaker,$metadata,$context);
    if ($route['availability']!=='unavailable'
        || !in_array($event['type'] ?? '',['input_text','input_audio'],true)
        || synth_protocol_owns_rechat($event)) return $route;
    $label=strtolower(trim(strval($metadata['listener'] ?? $metadata['metadata']['listener'] ?? '')));
    if (strlen($label)>256 || !mb_check_encoding($label,'UTF-8')) return $route;
    foreach (['listener_formid','listener_plugin'] as $field) {
        if (trim(strval($metadata[$field] ?? $metadata['metadata'][$field] ?? ''))!=='') return $route;
    }
    if (str_starts_with($label,'__') || $label==='the narrator') return $route;
    $player=$context['player'] ?? [];
    $speakerKey=synth_rechat_actor_key($speaker);
    if ($speakerKey===null || $speakerKey!==synth_rechat_actor_key($context['target'] ?? [])
        || synth_rechat_actor_key($player)===null || $speakerKey===synth_rechat_actor_key($player)
        || ($player['form_id'] ?? '')!=='0x00000014'
        || ($player['playthrough_id'] ?? null)!==($speaker['playthrough_id'] ?? null)) return $route;
    return ['availability'=>'addressed','listener'=>$player];
}

// Only negotiated clients receive the listener resolved from the request's immutable scene.
function synth_rechat_listener_wire(array $event, array $line, array $route, string $wire): string
{
    if (array_key_exists('listener',$line['payload'])) throw new LogicException('Dialogue listener must come from canonical routing');
    if (($event['protocol_version'] ?? null)!==2
        || !in_array('dialogue.listener_identity',$event['capabilities'] ?? [],true)
        || !in_array('dialogue.turn_ownership',$event['capabilities'] ?? [],true)
        || ($route['availability'] ?? '')!=='addressed') return $wire;
    $line['payload']['listener']=array_intersect_key($route['listener'],
        array_flip(['form_id','origin_plugin','playthrough_id','display_name']));
    return json_encode($line,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}

// Persist the final wire-line hash before emission; pending rows alone never prove delivered dialogue.
function synth_rechat_record_emitted_route($db, array $event, string $wire, array $metadata): string
{
    $line=json_decode($wire,true,flags:JSON_THROW_ON_ERROR);
    if (($line['type'] ?? '')!=='dialogue' || ($line['protocol_version'] ?? null)!==2
        || !is_array($line['payload']['speaker'] ?? null)) throw new LogicException('Invalid dialogue route line');
    foreach (['request_id','turn_id','generation','context_sequence','runtime_variant'] as $field) {
        if (($line[$field] ?? null)!==($event[$field] ?? null)) throw new LogicException('Dialogue route owner mismatch');
    }
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event,$line,$wire,$metadata) {
        if ($owner!==$event) throw new LogicException('Dialogue route owner changed');
        $canonical=synth_protocol_canonical_json($event);
        $result=@pg_query_params($connection,"SELECT c.context_payload
            FROM synth_meta.request_executions e
            JOIN synth_meta.protocol_events p USING(session_id,request_id)
            JOIN synth_meta.context_snapshots c ON c.session_id=e.session_id AND c.generation=e.generation
                AND c.snapshot_sequence=$3
            JOIN synth_meta.runtime_sessions s ON s.session_id=e.session_id AND s.playthrough_id=c.playthrough_id
            WHERE e.session_id=$1 AND e.request_id=$2 AND e.state IN ('claimed','streaming')
                AND p.canonical_event=$4::jsonb AND p.event_sha256=$5 AND e.request_sha256=p.event_sha256
                AND e.generation=$6 AND p.generation=e.generation AND e.turn_id=$7 AND p.turn_id=e.turn_id
                AND p.event_type=e.event_type AND s.runtime_variant=$8",
            [$event['session_id'],$event['request_id'],$event['context_sequence'],$canonical,hash('sha256',$canonical),
             $event['generation'],$event['turn_id'],$event['runtime_variant']]);
        if (!$result) throw new RuntimeException('Unable to verify dialogue route owner');
        $row=pg_fetch_assoc($result);
        if (!$row) throw new LogicException('Dialogue route canonical owner unavailable');
        $context=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        $scene=synth_rechat_response_scene_locked($connection,$event);
        if ($scene!==null) $context=$scene['payload'];
        $speaker=$line['payload']['speaker'];
        $speakerKey=synth_rechat_actor_key($speaker);
        if (synth_protocol_owns_rechat($event)) {
            require_once __DIR__.'/synth_rechat_prepare.php';
            $selection=synth_rechat_prepared_selection($db,$event);
            $allowed=$selection['role']==='npc'
                ? synth_protocol_canonical_json($speaker)===synth_protocol_canonical_json($selection['identity'])
                : ($selection['role']==='narrator' && ($speaker['kind'] ?? '')==='narrator' && ($speaker['id'] ?? '')==='narrator'
                    && !array_intersect(['form_id','origin_plugin','playthrough_id'],array_keys($speaker)));
            if (!$allowed) throw new LogicException('rechat_emission_speaker_not_selected');
        }
        if ($speakerKey===null) {
            if (($speaker['kind'] ?? '')!=='narrator' || ($speaker['id'] ?? '')!=='narrator') {
                throw new LogicException('Dialogue route speaker unavailable');
            }
        } else {
            $found=false;
            foreach (array_merge([$context['player'] ?? null,$context['target'] ?? null],$context['audience'] ?? []) as $actor) {
                if (is_array($actor) && synth_rechat_actor_key($actor)===$speakerKey) $found=true;
            }
            if (!$found) throw new LogicException('Dialogue route speaker outside captured context');
        }
        if (($event['payload']['kind'] ?? '')==='bored' && in_array('dialogue.ambient_npc',$event['capabilities'] ?? [],true)) {
            require_once __DIR__.'/synth_ambient_npc.php';
            if (synth_ambient_npc_projection($event)===null) throw new LogicException('ambient_origin_not_prepared');
            $ambient=synth_ambient_npc_prepare($db,$event);
            if ($ambient===null || synth_protocol_canonical_json($speaker)!==synth_protocol_canonical_json($ambient['speaker']))
                throw new LogicException('ambient_emission_speaker_not_selected');
            $route=['availability'=>'addressed','listener'=>$ambient['listener']];
        } else $route=synth_response_listener_route($speaker,$metadata,$context,$event);
        $wire=synth_rechat_listener_wire($event,$line,$route,$wire);
        $utterance=synth_rechat_route_utterance($line,$metadata);
        if ($utterance!==null) {
            $existing=@pg_query_params($connection,'SELECT line_id FROM synth_meta.response_routes
                WHERE session_id=$1 AND request_id=$2 AND utterance_id=$3',[$event['session_id'],$event['request_id'],$utterance]);
            if (!$existing) throw new RuntimeException('Unable to verify dialogue utterance uniqueness');
            $existing=pg_fetch_assoc($existing);
            if ($existing && $existing['line_id']!==$line['line_id']) throw new LogicException('dialogue_route_utterance_reused');
        }
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.response_routes
            (session_id,request_id,line_id,line_sha256,generation,context_sequence,speaker,listener_state,listener,utterance_id)
            VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8,$9::jsonb,$10)
            ON CONFLICT(session_id,request_id,line_id) DO UPDATE SET line_sha256=EXCLUDED.line_sha256
            WHERE response_routes.line_sha256=EXCLUDED.line_sha256
                AND response_routes.generation=EXCLUDED.generation AND response_routes.context_sequence=EXCLUDED.context_sequence
                AND response_routes.speaker=EXCLUDED.speaker AND response_routes.listener_state=EXCLUDED.listener_state
                AND response_routes.listener IS NOT DISTINCT FROM EXCLUDED.listener
                AND response_routes.utterance_id IS NOT DISTINCT FROM EXCLUDED.utterance_id
            RETURNING line_id",
            [$event['session_id'],$event['request_id'],$line['line_id'],hash('sha256',$wire),$event['generation'],
             $event['context_sequence'],json_encode($speaker,JSON_THROW_ON_ERROR),$route['availability'],
             $route['listener']===null ? null : json_encode($route['listener'],JSON_THROW_ON_ERROR),$utterance]);
        if (!$result) throw new RuntimeException('Unable to persist dialogue route');
        if (!pg_fetch_assoc($result)) throw new LogicException('Conflicting dialogue route replay');
        return $wire;
    },2000);
}

// Shared completion framing for dialogue parents and the requests that actually emitted their actions.
function synth_rechat_completed_lines(string $body, array $event): ?array
{
    if ($body==='' || strlen($body)>2*1024*1024 || !str_ends_with($body,"\n")) return null;
    $wireLines=explode("\n",substr($body,0,-1)); $seen=[]; $result=[];
    if (count($wireLines)<3 || count($wireLines)>1024) return null;
    foreach ($wireLines as $index=>$wireLine) {
        $line=json_decode($wireLine,true);
        if (!is_array($line) || ($line['protocol_version'] ?? null)!==2
            || ($line['game'] ?? '')!=='fo4' || ($line['schema'] ?? '')!=='synth.response.line.v2') return null;
        foreach (['request_id','turn_id','generation','context_sequence','runtime_variant'] as $field) {
            if (($line[$field] ?? null)!==($event[$field] ?? null)) return null;
        }
        $id=$line['line_id'] ?? null; $type=$line['type'] ?? '';
        if (!is_string($id) || isset($seen[$id])) return null;
        $seen[$id]=true;
        if ($index===0) { if ($type!=='response_start') return null; }
        elseif ($index===count($wireLines)-1) {
            if ($type!=='response_end' || ($line['payload']['status'] ?? '')!=='complete') return null;
        } elseif (!in_array($type,['dialogue','action','status'],true)) return null;
        $result[]=['line'=>$line,'wire'=>$wireLine."\n"];
    }
    return $result;
}

// Resolve completed evidence only. Admission must separately reserve one child and its durable chain decision.
function synth_rechat_completed_parent($db, array $event): ?array
{
    if (!synth_protocol_owns_rechat($event)
        || !in_array('dialogue.turn_ownership',$event['capabilities'] ?? [],true)) return null;
    $parent=$event['payload']['reply_to_request_id'] ?? '';
    $lineId=$event['payload']['reply_to_line_id'] ?? '';
    foreach ([$parent,$lineId] as $id) if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$id)) return null;
    if ($parent===$event['request_id']) return null;
    return $db->withNativeRequestLock(static function($connection,array $owner) use($event) {
        if ($owner!==$event) return null;
        return synth_rechat_completed_parent_evidence($connection,$event);
    },2000);
}

// Read-only evidence under a caller-held native owner lock; this does not admit a continuation.
function synth_rechat_completed_parent_evidence($connection, array $event): ?array
{
    if (($event['protocol_version'] ?? null)!==2 || ($event['type'] ?? '')!=='trigger'
        || ($event['payload']['kind'] ?? '')!=='rechat'
        || !in_array('dialogue.rechat.ownership',$event['capabilities'] ?? [],true)
        || !in_array('dialogue.turn_ownership',$event['capabilities'] ?? [],true)) return null;
    $parent=$event['payload']['reply_to_request_id'] ?? '';
    $lineId=$event['payload']['reply_to_line_id'] ?? '';
    foreach ([$parent,$lineId] as $id) if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$id)) return null;
    if ($parent===$event['request_id']) return null;
    $canonical=synth_protocol_canonical_json($event);
    $result=@pg_query_params($connection,"SELECT p.canonical_event,p.event_sha256,p.event_type,e.response_body,c.context_payload
        FROM synth_meta.request_executions e JOIN synth_meta.protocol_events p USING(session_id,request_id)
        JOIN synth_meta.context_snapshots c ON c.session_id=e.session_id AND c.generation=e.generation AND c.snapshot_sequence=$3
        JOIN synth_meta.runtime_sessions s ON s.session_id=c.session_id AND s.playthrough_id=c.playthrough_id
        JOIN synth_meta.request_executions child ON child.session_id=e.session_id AND child.request_id=$8
        JOIN synth_meta.protocol_events cp ON cp.session_id=child.session_id AND cp.request_id=child.request_id
        WHERE e.session_id=$1 AND e.request_id=$2 AND e.state='complete'
            AND e.generation=$4 AND e.turn_id=$5 AND p.generation=e.generation AND p.turn_id=e.turn_id
            AND octet_length(e.response_body)<=2097152
            AND p.event_sha256=e.request_sha256 AND p.event_type=e.event_type
            AND p.canonical_event->>'protocol_version'='2' AND p.canonical_event->>'context_sequence'=$3::text
            AND p.canonical_event->>'runtime_variant'=$6 AND s.runtime_variant=$6
            AND child.state IN ('claimed','streaming','complete') AND child.generation=$4 AND child.turn_id=$5
            AND cp.canonical_event=$7::jsonb AND cp.event_sha256=$9 AND child.request_sha256=cp.event_sha256
            AND cp.generation=child.generation AND cp.turn_id=child.turn_id AND cp.event_type=child.event_type",
        [$event['session_id'],$parent,strval($event['context_sequence']),$event['generation'],$event['turn_id'],
         $event['runtime_variant'],$canonical,$event['request_id'],hash('sha256',$canonical)]);
    if (!$result) throw new RuntimeException('Unable to verify completed rechat parent');
    $row=pg_fetch_assoc($result);
    if (!$row) return null;
    $origin=json_decode($row['canonical_event'],true,flags:JSON_THROW_ON_ERROR);
    if (!hash_equals($row['event_sha256'],hash('sha256',synth_protocol_canonical_json($origin)))
        || ($origin['type'] ?? null)!==$row['event_type']) return null;
    foreach (['session_id','turn_id','generation','context_sequence','runtime_variant'] as $field) {
        if (($origin[$field] ?? null)!==($event[$field] ?? null)) return null;
    }
    if (($origin['request_id'] ?? null)!==$parent || !synth_protocol_owns_dialogue_turn($origin)) return null;
    $body=synth_protocol_decode_bytea($row['response_body']);
    $lines=synth_rechat_completed_lines($body,$origin);
    if ($lines===null) return null;
    $context=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
    $result=@pg_query_params($connection,'SELECT * FROM synth_meta.response_routes
        WHERE session_id=$1 AND request_id=$2 AND generation=$3 AND context_sequence=$4',
        [$event['session_id'],$parent,$event['generation'],$event['context_sequence']]);
    if (!$result) throw new RuntimeException('Unable to read rechat parent routes');
    $routes=[];
    foreach (pg_fetch_all($result) as $route) $routes[$route['line_id']]=$route;
    $lastNpc=null; $selected=null;
    foreach ($lines as $entry) {
        $line=$entry['line']; $id=$line['line_id'];
        if ($line['type']!=='dialogue') continue;
        $route=$routes[$id] ?? null;
        if (!$route || !hash_equals($route['line_sha256'],hash('sha256',$entry['wire']))) return null;
        $speaker=json_decode($route['speaker'],true,flags:JSON_THROW_ON_ERROR);
        if (synth_protocol_canonical_json($speaker)!==synth_protocol_canonical_json($line['payload']['speaker'] ?? [])) return null;
        $key=synth_rechat_actor_key($speaker);
        if ($key!==null && $key!==synth_rechat_actor_key($context['player'])) $lastNpc=$id;
        if ($id===$lineId) $selected=['line'=>$line,'speaker'=>$speaker,'listener_state'=>$route['listener_state'],
            'listener'=>$route['listener']===null ? null : json_decode($route['listener'],true,flags:JSON_THROW_ON_ERROR)];
    }
    if ($selected===null || $lastNpc!==$lineId
        || synth_rechat_actor_key($selected['speaker'])!==synth_rechat_actor_key($event['payload']['actor'] ?? [])) return null;
    return $selected+['request'=>$origin,'response_sha256'=>hash('sha256',$body),'bytes'=>strlen($body)];
}
