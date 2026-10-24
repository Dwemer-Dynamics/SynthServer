<?php

require_once __DIR__.'/synth_relationship_interaction.php';

// Capture/retry boundaries share the exact pair, routed lines, saved priors and sorted actor-control locks.
function synth_relationship_interaction_inputs($connection, $db, array $event, int $responseId, int $entryIndex): ?array
{
    $interaction=synth_relationship_interaction_context($db,$responseId,$entryIndex);
    if (!$interaction) return null;
    $canonical=synth_protocol_canonical_json($event);
    $result=@pg_query_params($connection,"SELECT p.id AS owner_event_id,origin.id AS response_event_id,i.response_payload
        FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e USING(session_id,request_id)
        CROSS JOIN synth_meta.relationship_response_inputs i
        JOIN synth_meta.protocol_events origin ON origin.session_id=i.session_id AND origin.request_id=i.request_id
        WHERE p.session_id=$1 AND p.request_id=$2 AND p.canonical_event=$3::jsonb AND p.event_sha256=$4
            AND e.request_sha256=p.event_sha256 AND e.state='complete' AND i.history_event_id=$5
            AND i.session_id=p.session_id AND i.request_id=p.request_id",
        [$event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical),$responseId]);
    if (!$result) throw new RuntimeException('Unable to verify relationship interaction owner');
    $row=pg_fetch_assoc($result);if (!$row) return null;
    $context=synth_protocol_current_context($db,$event);if (!$context) return null;
    $responder=synth_relationship_responder($db,$event,$context);if (!$responder) return null;
    $response=json_decode($row['response_payload'],true,flags:JSON_THROW_ON_ERROR);$lines=[];
    if (($response['evaluation_mode'] ?? null)!=='dedicated') return null;
    foreach ($response['entries'] as $index=>$entry) {
        $participants=synth_relationship_interaction_participants($context['payload'],$entry,$responder['identity']);
        if ($participants==$interaction['participants']) $lines[]=['entry_index'=>$index,'entry'=>$entry];
    }
    if (!$lines) return null;
    $ids=array_values($interaction['actor_ids']);sort($ids,SORT_NUMERIC);$controls=[];$writable=[];
    foreach ($ids as $id) $controls[$id]=synth_relationship_control_lock($connection,$id);
    foreach ($interaction['actor_ids'] as $role=>$id) {
        $control=$controls[$id];
        $writable[$role]=$control['locked']!=='t' && intval($row['owner_event_id'])>intval($control['barrier_event_id'])
            && intval($row['response_event_id'])>intval($control['barrier_event_id']);
    }
    if (!in_array(true,$writable,true)) return null;
    return ['context'=>$context['payload'],'interaction'=>$interaction,'response'=>$response,'lines'=>$lines,
        'controls'=>['speaker'=>$controls[$interaction['actor_ids']['speaker']],'listener'=>$controls[$interaction['actor_ids']['listener']]],
        'writable'=>$writable];
}

// Each response/listener pair is immutable and separate from the existing single-speaker evaluation contract.
function synth_relationship_capture_interaction_evaluation($db, int $responseId, int $entryIndex, string $systemPrompt): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    if ($responseId<1 || $entryIndex<0 || $entryIndex>255 || trim($systemPrompt)==='' || strlen($systemPrompt)>16384
        || str_contains($systemPrompt,"\0") || !mb_check_encoding($systemPrompt,'UTF-8')) throw new InvalidArgumentException('Invalid interaction evaluation input');
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$responseId,$entryIndex,$systemPrompt) {
        $snapshot=synth_relationship_interaction_inputs($connection,$db,$event,$responseId,$entryIndex);
        if (!$snapshot) return null;
        $pair=$snapshot['interaction']['actor_ids'];
        $result=@pg_query_params($connection,'SELECT id FROM synth_meta.relationship_interaction_evaluations
            WHERE session_id=$1 AND request_id=$2 AND response_history_event_id=$3 AND listener_identity_id=$4',
            [$event['session_id'],$event['request_id'],$responseId,$pair['listener']]);
        if (!$result) throw new RuntimeException('Unable to check interaction evaluation replay');
        if ($existing=pg_fetch_assoc($result)) return intval($existing['id']);
        $input=['schema'=>'synth.relationship.interaction.input.v1','evaluation_policy'=>'synth.relationship.policy.v2',
            'owner'=>$event,'connector_id'=>intval($GLOBALS['RELLLM_CONNECTOR']),'system_prompt'=>$systemPrompt]+$snapshot;
        $json=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>262144) throw new LengthException('Interaction evaluation input too large');
        $result=@pg_query_params($connection,'INSERT INTO synth_meta.relationship_interaction_evaluations
            (speaker_identity_id,listener_identity_id,response_history_event_id,session_id,request_id,generation,context_sequence,input_payload,input_sha256)
            VALUES($1,$2,$3,$4,$5,$6,$7,$8::jsonb,encode(sha256(convert_to(($8::jsonb)::text,\'UTF8\')),\'hex\')) RETURNING id',
            [$pair['speaker'],$pair['listener'],$responseId,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence'],$json]);
        if (!$result) throw new RuntimeException('Unable to freeze interaction evaluation');
        $id=intval(pg_fetch_assoc($result)['id']);
        foreach ($snapshot['interaction']['relationships'] as $role=>$state) {
            if ($state!==null && @pg_query_params($connection,'INSERT INTO synth_meta.relationship_interaction_priors
                (interaction_id,actor_identity_id,prior_history_event_id) VALUES($1,$2,$3)',[$id,$pair[$role],$state['revision']])===false) {
                throw new RuntimeException('Unable to freeze interaction prior');
            }
        }
        return $id;
    },2000);
}

// Select the established NPC-to-NPC custom/default prompt, never the player evaluation prompt or a later retry setting.
function synth_relationship_capture_configured_interaction($db, int $responseId, int $entryIndex): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    $row=$db->fetchOne("SELECT custom_prompt,default_prompt FROM prompts WHERE prompt_key='rel_llm_npc_to_npc'");
    $prompt=!empty($row['custom_prompt']) ? $row['custom_prompt'] : ($row['default_prompt'] ?? '');
    if (!is_string($prompt) || trim($prompt)==='') throw new RuntimeException('NPC-to-NPC relationship prompt unavailable');
    return synth_relationship_capture_interaction_evaluation($db,$responseId,$entryIndex,$prompt);
}

// Validate frozen inputs for pending work; accepted result visibility must not use current administrative policy.
function synth_relationship_interaction_input($db, int $interactionId): ?array
{
    if ($interactionId<1) throw new InvalidArgumentException('Invalid interaction evaluation key');
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$interactionId) {
        $result=@pg_query_params($connection,'SELECT * FROM synth_meta.relationship_interaction_evaluations
            WHERE id=$1 AND session_id=$2 AND request_id=$3 AND generation=$4 AND context_sequence=$5
                AND input_sha256=encode(sha256(convert_to(input_payload::text,\'UTF8\')),\'hex\')',
            [$interactionId,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to validate interaction evaluation');
        $row=pg_fetch_assoc($result);if (!$row) return null;
        $input=json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        if (($input['schema'] ?? null)!=='synth.relationship.interaction.input.v1'
            || ($input['evaluation_policy'] ?? null)!=='synth.relationship.policy.v2'
            || ($input['owner'] ?? null)!=$event || !is_int($input['interaction']['entry_index'] ?? null)
            || ($input['interaction']['response_id'] ?? null)!==intval($row['response_history_event_id'])) return null;
        if (array_diff(array_keys($input),['schema','evaluation_policy','owner','connector_id','system_prompt',
                'context','interaction','response','lines','controls','writable'])
            || !is_int($input['connector_id'] ?? null) || $input['connector_id']<1
            || !is_string($input['system_prompt'] ?? null) || trim($input['system_prompt'])==='' || strlen($input['system_prompt'])>16384
            || str_contains($input['system_prompt'],"\0") || !mb_check_encoding($input['system_prompt'],'UTF-8')) return null;
        $snapshot=synth_relationship_interaction_inputs($connection,$db,$event,intval($row['response_history_event_id']),$input['interaction']['entry_index']);
        if (!$snapshot || array_intersect_key($input,$snapshot)!=$snapshot
            || $snapshot['interaction']['actor_ids']!==['speaker'=>intval($row['speaker_identity_id']),'listener'=>intval($row['listener_identity_id'])]) return null;
        $result=@pg_query_params($connection,'SELECT actor_identity_id,prior_history_event_id FROM synth_meta.relationship_interaction_priors
            WHERE interaction_id=$1 ORDER BY actor_identity_id',[$interactionId]);
        if (!$result) throw new RuntimeException('Unable to validate interaction prior edges');
        $expected=[];
        foreach ($snapshot['interaction']['relationships'] as $role=>$state) {
            if ($state!==null) $expected[$snapshot['interaction']['actor_ids'][$role]]=strval($state['revision']);
        }
        $actual=[];foreach (pg_fetch_all($result) as $prior) $actual[intval($prior['actor_identity_id'])]=$prior['prior_history_event_id'];
        return $actual==$expected ? $input : null;
    },2000);
}
