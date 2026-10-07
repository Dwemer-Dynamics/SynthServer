<?php

require_once __DIR__.'/synth_rechat_prepare.php';

// Both intent creation and final emission recheck immutable chain controls and canonical action ownership.
function synth_rechat_assert_action_emission($db, array $event, array $action, bool $requireIntent = false): void
{
    $code=($action['name'] ?? '')==='inspect_actor' ? 'Inspect' : str_replace(' ','',ucwords(str_replace('_',' ',$action['name'] ?? '')));
    if (!synth_rechat_action_code_allowed($code)) throw new LogicException('rechat_action_not_allowed');
    $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event,$action,$requireIntent) {
        if ($owner!==$event) throw new LogicException('rechat_action_owner_changed');
        $selection=synth_rechat_prepared_selection($db,$event);
        if ($selection['role']!=='npc' || !($selection['policy']['allow_actions'] ?? false)) throw new LogicException('rechat_actions_not_admitted');
        if (synth_protocol_canonical_json($action['actor'] ?? [])!==synth_protocol_canonical_json($selection['identity'])) {
            throw new LogicException('rechat_action_actor_not_selected');
        }
        static $schema=null;
        $schema ??= json_decode(file_get_contents(dirname(__DIR__).'/protocol/v1/schemas/action.schema.json'),true,flags:JSON_THROW_ON_ERROR);
        $capability=$action['capability'] ?? null;
        if (($action['schema'] ?? '')!=='synth.action.v1' || !in_array($action['name'] ?? null,$schema['properties']['name']['enum'],true)
            || $capability!=='action.'.$action['name'] || !in_array($capability,$event['capabilities'] ?? [],true)) {
            throw new LogicException('rechat_action_capability_not_admitted');
        }
        foreach (['action_id','idempotency_key'] as $key) {
            if (!is_string($action[$key] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$action[$key])) {
                throw new LogicException('rechat_action_id_invalid');
            }
        }
        if (!is_int($action['deadline_ms'] ?? null) || $action['deadline_ms']<100 || $action['deadline_ms']>120000
            || array_diff(array_keys($action),array_merge(array_keys($schema['properties']),$requireIntent ? [] : ['server_code']))) {
            throw new LogicException('rechat_action_shape_invalid');
        }
        $serverCode=$action['name']==='inspect_actor' ? 'Inspect' : str_replace(' ','',ucwords(str_replace('_',' ',$action['name'])));
        if (isset($action['server_code']) && $action['server_code']!==$serverCode) throw new LogicException('rechat_action_code_mismatch');
        $arguments=$action['arguments'] ?? null;
        if ((!($arguments instanceof stdClass) && !is_array($arguments))
            || ($requireIntent && is_array($arguments) && array_is_list($arguments))) throw new LogicException('rechat_action_arguments_invalid');
        $arguments=(array)$arguments; $rules=$schema['properties']['arguments'];
        if (count($arguments)>$rules['maxProperties']) throw new LogicException('rechat_action_arguments_invalid');
        foreach ($arguments as $key=>$value) {
            $rule=$rules['properties'][$key] ?? null;
            if (!$rule || (isset($rule['enum']) && !in_array($value,$rule['enum'],true))
                || (($rule['type'] ?? '')==='integer' && (!is_int($value) || $value<$rule['minimum'] || $value>$rule['maximum']))
                || (($rule['type'] ?? '')==='string' && (!is_string($value) || !mb_check_encoding($value,'UTF-8')
                    || str_contains($value,"\0") || mb_strlen($value,'UTF-8')<$rule['minLength'] || mb_strlen($value,'UTF-8')>$rule['maxLength']))) {
                throw new LogicException('rechat_action_arguments_invalid');
            }
        }
        $context=synth_protocol_current_context($db,$event);
        if (!$context) throw new LogicException('rechat_action_context_unavailable');
        if (array_key_exists('target',$action)) {
            $found=false;
            foreach (array_merge([$context['player'] ?? null,$context['target'] ?? null],$context['payload']['audience'] ?? []) as $candidate) {
                if (is_array($candidate) && synth_protocol_canonical_json($candidate)===synth_protocol_canonical_json($action['target'])) $found=true;
            }
            if (!$found) throw new LogicException('rechat_action_target_not_captured');
        } elseif ($capability==='action.inspect_actor') throw new LogicException('rechat_action_target_required');
        if (!$requireIntent) return;
        $result=@pg_query_params($connection,"SELECT i.*,a.full_form_id,a.origin_plugin,a.playthrough_id,
                target.full_form_id AS target_form_id,target.origin_plugin AS target_plugin,target.playthrough_id AS target_playthrough
            FROM synth_meta.action_intents i JOIN synth.actor_identities a ON a.id=i.actor_identity_id
            LEFT JOIN synth.actor_identities target ON target.id=i.target_identity_id
            WHERE i.action_id=$1 AND i.idempotency_key=$2 AND i.session_id=$3 AND i.request_id=$4 AND i.generation=$5 AND i.turn_id=$6
                AND i.state IN ('pending','dispatched') AND i.deadline_at>clock_timestamp()
                AND EXTRACT(EPOCH FROM (i.deadline_at-i.created_at))*1000 >= $7",
            [$action['action_id'],$action['idempotency_key'],$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],$action['deadline_ms']]);
        if (!$result || !($intent=pg_fetch_assoc($result))) throw new LogicException('rechat_action_intent_unavailable');
        $actor=['form_id'=>$intent['full_form_id'],'origin_plugin'=>$intent['origin_plugin'],'playthrough_id'=>$intent['playthrough_id']];
        $target=$intent['target_form_id']===null ? null : ['form_id'=>$intent['target_form_id'],'origin_plugin'=>$intent['target_plugin'],'playthrough_id'=>$intent['target_playthrough']];
        if (!in_array($intent['action_name'],[$action['name'],$serverCode],true) || $intent['capability']!==$capability || synth_rechat_actor_key($actor)!==synth_rechat_actor_key($action['actor'])
            || ($target===null ? isset($action['target']) : synth_rechat_actor_key($target)!==synth_rechat_actor_key($action['target'] ?? []))
            || synth_protocol_canonical_json(json_decode($intent['arguments'],true,flags:JSON_THROW_ON_ERROR))!==synth_protocol_canonical_json($arguments)) {
            throw new LogicException('rechat_action_intent_mismatch');
        }
    },2000);
}
