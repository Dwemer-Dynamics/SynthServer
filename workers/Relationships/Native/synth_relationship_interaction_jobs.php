<?php

require_once dirname(__DIR__, 3).'/managers/Relationships/Native/synth_relationship_interaction.php';

// Capture/retry boundaries share the exact pair, routed lines, saved priors and sorted actor-control locks.
function synth_relationship_interaction_inputs($connection, $db, array $event, int $responseId, int $entryIndex): ?array
{
    $interaction=synth_relationship_interaction_context($db,$responseId,$entryIndex);
    if (!$interaction) return null;
    $canonical=synth_protocol_canonical_json($event);
    $row=\Synth\Core\DbManager\Dao\RelationshipResponseInputDao::current($db)->interactionOwner($event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical),$responseId);
    if (!$row) return null;
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
        $existing=\Synth\Core\DbManager\Dao\RelationshipInteractionEvaluationDao::current($db)->replayId($event['session_id'],$event['request_id'],$responseId,$pair['listener']);
        if ($existing!==null) return $existing;
        $input=['schema'=>'synth.relationship.interaction.input.v1','evaluation_policy'=>'synth.relationship.policy.v2',
            'owner'=>$event,'connector_id'=>intval(\Synth\Bootstrap::config('RELLLM_CONNECTOR')),'system_prompt'=>$systemPrompt]+$snapshot;
        $json=json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>262144) throw new LengthException('Interaction evaluation input too large');
        $id=\Synth\Core\DbManager\Dao\RelationshipInteractionEvaluationDao::current($db)->freeze($pair['speaker'],$pair['listener'],$responseId,$event['session_id'],$event['request_id'],
            $event['generation'],$event['context_sequence'],$json);
        foreach ($snapshot['interaction']['relationships'] as $role=>$state) {
            if ($state!==null) \Synth\Core\DbManager\Dao\RelationshipInteractionPriorDao::current($db)->add($id,$pair[$role],$state['revision']);
        }
        return $id;
    },2000);
}

// Select the established NPC-to-NPC custom/default prompt, never the player evaluation prompt or a later retry setting.
function synth_relationship_capture_configured_interaction($db, int $responseId, int $entryIndex): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    $row=\Synth\Core\DbManager\Dao\PromptDao::current($db)->texts('rel_llm_npc_to_npc');
    $prompt=!empty($row['custom_prompt']) ? $row['custom_prompt'] : ($row['default_prompt'] ?? '');
    if (!is_string($prompt) || trim($prompt)==='') throw new RuntimeException('NPC-to-NPC relationship prompt unavailable');
    return synth_relationship_capture_interaction_evaluation($db,$responseId,$entryIndex,$prompt);
}

// Validate frozen inputs for pending work; accepted result visibility must not use current administrative policy.
function synth_relationship_interaction_input($db, int $interactionId): ?array
{
    if ($interactionId<1) throw new InvalidArgumentException('Invalid interaction evaluation key');
    if (empty(\Synth\Bootstrap::claimedRequest())) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$interactionId) {
        $row=\Synth\Core\DbManager\Dao\RelationshipInteractionEvaluationDao::current($db)->verifiedInput($interactionId,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence']);
        if (!$row) return null;
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
        $expected=[];
        foreach ($snapshot['interaction']['relationships'] as $role=>$state) {
            if ($state!==null) $expected[$snapshot['interaction']['actor_ids'][$role]]=strval($state['revision']);
        }
        $actual=[];foreach (\Synth\Core\DbManager\Dao\RelationshipInteractionPriorDao::current($db)->forInteraction($interactionId) as $prior) $actual[intval($prior['actor_identity_id'])]=$prior['prior_history_event_id'];
        return $actual==$expected ? $input : null;
    },2000);
}
