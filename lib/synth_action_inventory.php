<?php
// Historical edges use evidence under the actual current owner, never a substituted request global.
function synth_protocol_inventory_rechat_parent($db, array $root, array $event): array
{
    require_once __DIR__.'/synth_rechat_history.php';
    return $db->withNativeRequestLock(static function($connection,array $owner) use($root,$event) {
        if ($owner!==$root) throw new DomainException('action_inventory_owner_changed');
        foreach (['session_id','generation','turn_id','context_sequence','runtime_variant'] as $field) {
            if (($event[$field] ?? null)!==($root[$field] ?? null)) throw new DomainException('action_inventory_owner_changed');
        }
        $proof=synth_rechat_completed_parent_evidence($connection,$event);
        if ($proof===null) throw new DomainException('action_inventory_rechat_parent_unavailable');
        // Candidate selection reads the root before reservation. Older rechat nodes must already be prepared.
        if ($event['request_id']!==$root['request_id']) {
            $result=@pg_query_params($connection,"SELECT d.chain_id,d.parent_request_id,d.parent_line_id,d.parent_response_sha256
                FROM synth_meta.rechat_continuations d JOIN synth_meta.rechat_selections s USING(session_id,request_id)
                JOIN synth_meta.rechat_chains c ON c.id=d.chain_id AND c.session_id=d.session_id
                WHERE d.session_id=$1 AND d.request_id=$2 AND d.status='reserved'
                    AND s.prepared_at IS NOT NULL AND s.role IN ('npc','narrator')
                    AND c.generation=$3 AND c.context_sequence=$4 AND c.turn_id=$5 AND c.runtime_variant=$6",
                [$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence'],$event['turn_id'],$event['runtime_variant']]);
            if (!$result) throw new RuntimeException('action_inventory_rechat_selection_query_failed');
            $edge=pg_fetch_assoc($result);
            if (!$edge || $edge['parent_request_id']!==$event['payload']['reply_to_request_id']
                || $edge['parent_line_id']!==$event['payload']['reply_to_line_id']
                || !hash_equals($edge['parent_response_sha256'],$proof['response_sha256']))
                throw new DomainException('action_inventory_rechat_selection_unavailable');
            // Validate the historical response's selected speaker without consulting mutable live profile bindings.
            $response=synth_rechat_history_response($connection,$root,$event['request_id'],intval($edge['chain_id']));
            if (synth_protocol_canonical_json($response['event'])!==synth_protocol_canonical_json($event))
                throw new DomainException('action_inventory_rechat_event_mismatch');
            $proof['bytes']+=$response['bytes'];
        }
        return $proof;
    },2000);
}

// Overlay accepted observations along this request's ancestry, never latest/global inventory.
function synth_protocol_action_inventory_view($db, array $event, array $payload): array
{
    $rechat=($event['type'] ?? '')==='trigger' && ($event['payload']['kind'] ?? '')==='rechat';
    if (($event['protocol_version'] ?? 1) !== 2
        || (($event['type'] ?? '') !== 'action_result' && !$rechat)
        || !in_array('action.inventory_observation', $event['capabilities'] ?? [], true)) return $payload;
    if ($rechat && !synth_protocol_owns_rechat($event)) throw new DomainException('action_inventory_ancestry_capability');
    $session=$db->escapeLiteral($event['session_id']);$turn=$db->escapeLiteral($event['turn_id']);
    $generation=intval($event['generation']);$sequence=intval($event['context_sequence']);
    $variant=$db->escapeLiteral($event['runtime_variant']);
    $current=$event;$visited=[];$requests=[];$observed=[];$bytes=0;
    for ($depth=0;$depth<64;++$depth) {
        if (isset($requests[$current['request_id']])) throw new DomainException('action_inventory_ancestry_invalid');
        $requests[$current['request_id']]=true;
        if (synth_protocol_owns_rechat($current)) {
            $proof=synth_protocol_inventory_rechat_parent($db,$event,$current);
            $parent=$proof['request'];
            $bytes+=$proof['bytes']+strlen(json_encode($parent,JSON_THROW_ON_ERROR));
        } else {
            $result=$current['payload']['result'] ?? [];
            $actionId=$result['action_id'] ?? '';$key=$result['idempotency_key'] ?? '';
            if (!synth_protocol_valid_id($actionId) || !synth_protocol_valid_id($key) || isset($visited[$actionId]))
                throw new DomainException('action_inventory_ancestry_invalid');
            $visited[$actionId]=true;
            $action=$db->escapeLiteral($actionId);$idempotency=$db->escapeLiteral($key);
            $request=$db->escapeLiteral($current['request_id']);
            $canonical=$db->escapeLiteral(synth_protocol_canonical_json($current));
            $row=$db->fetchOne("SELECT i.action_name,i.capability,i.request_id AS origin_request_id,a.full_form_id,a.origin_plugin,a.playthrough_id,
                    e.canonical_event,t.result,b.full_form_id AS recipient_form_id,
                    b.origin_plugin AS recipient_plugin,b.playthrough_id AS recipient_playthrough_id
                FROM synth_meta.action_intents i
                JOIN synth.actor_identities a ON a.id=i.actor_identity_id AND a.game='fo4'
                LEFT JOIN synth.actor_identities b ON b.id=i.target_identity_id AND b.game='fo4'
                JOIN synth_meta.action_terminal_results t ON t.action_id=i.action_id AND t.idempotency_key=i.idempotency_key
                    AND t.status=t.result->>'status'
                JOIN synth_meta.protocol_events e ON e.session_id=i.session_id AND e.request_id=i.request_id
                    AND e.generation=i.generation AND e.turn_id=i.turn_id
                JOIN synth_meta.request_executions parent ON parent.session_id=e.session_id AND parent.request_id=e.request_id
                    AND parent.state IN ('streaming','complete')
                JOIN synth_meta.protocol_events receipt ON receipt.session_id=i.session_id AND receipt.request_id=$request
                    AND receipt.generation=i.generation AND receipt.turn_id=i.turn_id AND receipt.event_type='action_result'
                    AND receipt.canonical_event=$canonical::jsonb AND receipt.canonical_event->'payload'->'result'=t.result
                JOIN synth_meta.request_executions accepted ON accepted.session_id=receipt.session_id
                    AND accepted.request_id=receipt.request_id AND accepted.state IN ('claimed','streaming','complete')
                JOIN synth_meta.runtime_sessions r ON r.session_id=i.session_id AND r.playthrough_id=a.playthrough_id
                WHERE i.action_id=$action AND i.idempotency_key=$idempotency AND i.session_id=$session
                    AND i.turn_id=$turn AND i.generation=$generation AND i.state='complete'
                    AND r.state='active' AND r.active_generation=$generation AND r.active_turn_id=$turn
                    AND r.active_turn_sequence=$sequence AND r.runtime_variant=$variant");
            if (!$row) throw new DomainException('action_inventory_ancestry_unavailable');
            $row=synth_protocol_transfer_inventory_owner($row);
            $parent=is_array($row['canonical_event'] ?? null) ? $row['canonical_event'] : json_decode(strval($row['canonical_event'] ?? ''),true);
            $terminal=is_array($row['result'] ?? null) ? $row['result'] : json_decode(strval($row['result'] ?? ''),true);
            $bytes+=strlen(json_encode([$parent,$terminal],JSON_THROW_ON_ERROR));
            if ($bytes>8*1024*1024 || !is_array($parent) || !is_array($terminal)
                || synth_protocol_sort_keys($terminal)!==synth_protocol_sort_keys($result)
                || ($parent['request_id'] ?? '')!==($row['origin_request_id'] ?? '')
                || ($parent['protocol_version'] ?? 1)!==2 || ($parent['session_id'] ?? '')!==$event['session_id']
                || ($parent['turn_id'] ?? '')!==$event['turn_id'] || intval($parent['generation'] ?? 0)!==$generation
                || intval($parent['context_sequence'] ?? 0)!==$sequence || ($parent['runtime_variant'] ?? '')!==$event['runtime_variant']
                || ($parent['request_id'] ?? '')===$current['request_id']) throw new DomainException('action_inventory_ancestry_invalid');
            foreach (['action.inventory_observation','dialogue.turn_ownership'] as $capability) {
                if (!in_array($capability,$parent['capabilities'] ?? [],true)
                    || !in_array($capability,$current['capabilities'] ?? [],true)) throw new DomainException('action_inventory_ancestry_capability');
            }
            $mutation=match($row['action_name']) {
                'EquipItem','equip_item'=>'action.equip_item', 'UnequipItem','unequip_item'=>'action.unequip_item',
                'Consume','consume'=>'action.consume', 'GiveItemTo','give_item_to'=>'action.give_item_to',
                'GiveCapsTo','give_caps_to'=>'action.give_caps_to',
                'TakeCapsFromPlayer','take_caps_from_player'=>'action.take_caps_from_player', default=>null,
            };
            $transfer=in_array($mutation,['action.give_item_to','action.give_caps_to','action.take_caps_from_player'],true);
            $paired=($terminal['schema'] ?? '')==='synth.action.transfer-result.v2';
            if (($paired && !$transfer) || (($terminal['schema'] ?? '')==='synth.action.result.v2' && ($mutation===null || $transfer)))
                throw new DomainException('action_inventory_ancestry_invalid');
            if ($mutation!==null) {
                if ($row['capability']!==$mutation || !in_array($mutation,$parent['capabilities'] ?? [],true))
                    throw new DomainException('action_inventory_ancestry_capability');
                if ($mutation==='action.consume' && (!synth_protocol_supports_consumption($parent)
                    || !synth_protocol_supports_consumption($current))) throw new DomainException('action_inventory_ancestry_capability');
                if ($transfer && (!synth_protocol_supports_transfer($parent) || !synth_protocol_supports_transfer($current)))
                    throw new DomainException('action_inventory_ancestry_capability');
                if ($mutation==='action.give_caps_to' && (!synth_protocol_supports_caps_transfer($parent)
                    || !synth_protocol_supports_caps_transfer($current))) throw new DomainException('action_inventory_ancestry_capability');
                if ($mutation==='action.take_caps_from_player' && (!synth_protocol_supports_player_caps($parent)
                    || !synth_protocol_supports_player_caps($current))) throw new DomainException('action_inventory_ancestry_capability');
                $owners=['inventory'=>[$row['full_form_id'],$row['origin_plugin'],$row['playthrough_id']]];
                if ($transfer) {
                    if (empty($row['recipient_form_id']) || empty($row['recipient_plugin'])
                        || ($row['recipient_playthrough_id'] ?? '')!==$row['playthrough_id']
                        || strcasecmp($row['recipient_form_id'],$row['full_form_id'])===0)
                        throw new DomainException('action_inventory_ancestry_actor');
                    $owners['recipient_inventory']=[$row['recipient_form_id'],$row['recipient_plugin'],$row['recipient_playthrough_id']];
                }
                foreach ($owners as $inventoryKey=>[$formId,$plugin,$playthrough]) {
                    // An old/missing mutation observation invalidates earlier rows instead of reviving the snapshot.
                    if ($paired && !isset($terminal[$inventoryKey])) throw new DomainException('action_inventory_ancestry_invalid');
                    $inventory=$terminal[$inventoryKey] ?? ['items'=>[],'observation'=>'unavailable'];
                    if (isset($terminal[$inventoryKey])) {
                        $actor=$inventory['actor'] ?? [];
                        if (strcasecmp($actor['form_id'] ?? '',$formId)!==0
                            || strcasecmp($actor['origin_plugin'] ?? '',$plugin)!==0
                            || ($actor['playthrough_id'] ?? '')!==$playthrough) throw new DomainException('action_inventory_ancestry_actor');
                    }
                    if (!is_array($inventory['items'] ?? null) || !array_is_list($inventory['items']) || count($inventory['items'])>512
                        || !in_array($inventory['observation'] ?? '',['complete','partial','unavailable'],true)
                        || ($inventory['observation']==='unavailable' && $inventory['items']!==[]))
                        throw new DomainException('action_inventory_ancestry_invalid');
                    $matches=[];
                    foreach ($payload['actor_states'] ?? [] as $index=>$state) {
                        $actor=$state['actor'] ?? [];
                        if (strcasecmp($actor['form_id'] ?? '',$formId)===0
                            && strcasecmp($actor['origin_plugin'] ?? '',$plugin)===0
                            && ($actor['playthrough_id'] ?? '')===$playthrough) $matches[]=$index;
                    }
                    if (count($matches)!==1) throw new DomainException('action_inventory_ancestry_actor');
                    $index=$matches[0];
                    if (!isset($observed[$index])) {
                        $observed[$index]=true;
                        $payload['actor_states'][$index]['inventory']=$inventory['items'];
                        $payload['actor_states'][$index]['inventory_observation']=$inventory['observation'];
                    }
                }
            }
        }
        if ($bytes>8*1024*1024) throw new DomainException('action_inventory_ancestry_limit');
        foreach (['session_id','generation','turn_id','context_sequence','runtime_variant'] as $field) {
            if (($parent[$field] ?? null)!==$event[$field]) throw new DomainException('action_inventory_ancestry_invalid');
        }
        foreach (['action.inventory_observation','dialogue.turn_ownership'] as $capability) {
            if (!in_array($capability,$parent['capabilities'] ?? [],true)
                || !in_array($capability,$current['capabilities'] ?? [],true)) throw new DomainException('action_inventory_ancestry_capability');
        }
        $rechat=($parent['type'] ?? '')==='trigger' && ($parent['payload']['kind'] ?? '')==='rechat';
        // The supplied view already contains this fresh capture; only later action receipts may overlay it.
        if ($rechat && in_array('dialogue.rechat.scene',$parent['capabilities'] ?? [],true)
            && ($parent['payload']['scene_context_sequence'] ?? null)===($payload['snapshot_sequence'] ?? null)) return $payload;
        if (in_array($parent['type'] ?? '',['input_text','input_audio'],true)
            || (($parent['type'] ?? '')==='trigger' && !$rechat)
            || (($parent['type'] ?? '')==='activate' && ($parent['payload']['source'] ?? '')==='manual')) return $payload;
        if (($parent['type'] ?? '')!=='action_result' && (!$rechat || !synth_protocol_owns_rechat($parent)))
            throw new DomainException('action_inventory_ancestry_invalid');
        $current=$parent;
    }
    throw new DomainException('action_inventory_ancestry_limit');
}
