<?php

// This file performs no work at include time. Model dialogue never authorizes a player debit.
function synth_player_item_same_actor(array $left, array $right): bool
{
    foreach (['form_id','origin_plugin','playthrough_id'] as $field) {
        if (!is_string($left[$field] ?? null) || !is_string($right[$field] ?? null)
            || ($field==='playthrough_id' ? $left[$field]!==$right[$field] : strcasecmp($left[$field],$right[$field])!==0)) return false;
    }
    return true;
}

function synth_player_item_offer(string $text): bool
{
    if (strlen($text)>8192 || preg_match('/\b(?:don\x{2019}?t|don\x27t|do not|never|not|stop|if|suppose|pretend|borrow|loan|trade|sell|buy)\b/iu',$text)) return false;
    // A direct follow-up may say "the gun", "that one" or "it" after choosing a gift.
    // This grants no actor/item fallback: emission still resolves the exact observed Player stack.
    if (preg_match('/\bfrom\s+(?!(?:me\b|(?:my|the)\s+(?:pack|backpack|inventory)\b))/iu',$text)
        || preg_match('/\b(?:take|grab|accept|keep)\s+(?:his|her|their|someone else\x27s)\b/iu',$text)) return false;
    if (function_exists('synth_player_gift_statement_kind') && synth_player_gift_statement_kind($text)==='named') return true;
    foreach (preg_split('/\.(?=\s|$)|[!?;\r\n]/u',$text) ?: [] as $clause) {
        // Past failure reports and quoted/reported instructions are not new permission.
        if (preg_match('/\b(?:did|didn[\x27\x{2019}]t|said|says|asked|told|say|repeat|quote|example)\b/iu',$clause)) continue;
        if (preg_match('/\b(?:take|grab|accept|keep|help yourself)\b.{0,160}\b(?:my|the|this|that|these|those|it|one|from me|as a gift|for you)\b/isu',$clause)===1) return true;
        if (preg_match('/\b(?:I give you|I am giving you|I\x27m giving you|you can have my)\b|\bI (?:want|would like) you to have\b|\bthis .{0,80}is for you\b/isu',$clause)===1) return true;
    }
    return false;
}

// Audio consent is frozen before optional respeech and bound to this exact input hash, never a global last line.
function synth_player_item_request_text(array $event, $db=null): ?string
{
    if (!in_array($event['type'] ?? '',['input_text','input_audio'],true)) return null;
    if ($event['type']==='input_text') return is_string($event['payload']['text'] ?? null) ? $event['payload']['text'] : null;
    $frozen=$GLOBALS['SYNTH_PLAYER_ITEM_INPUT'] ?? [];
    if (isset($frozen['owner_sha256'],$frozen['text']) && hash_equals($frozen['owner_sha256'],hash('sha256',synth_protocol_canonical_json($event)))) return $frozen['text'];
    return null;
}

function synth_player_item_request_allowed(array $event, $db=null): bool
{
    return synth_protocol_supports_player_items($event) && ($event['payload']['control']['mode'] ?? '')!=='NARRATOR'
        && ($text=synth_player_item_request_text($event,$db))!==null && synth_player_item_offer($text);
}

// Mirror the native selector: exact unique name, or exact BaseID with optional exact instance name.
function synth_player_item_select(array $view, string $selector): ?array
{
    if (!in_array($view['inventory_observation'] ?? '',['complete','partial'],true) || $selector==='' || strlen($selector)>255) return null;
    $id=null;$name=$selector;
    if (preg_match('/^(?:0x)?([0-9a-f]{8})(?::(.+))?$/Di',$selector,$m)) {
        $id='0x'.$m[1];$name=$m[2] ?? '';
    } elseif (strncasecmp($selector,'0x',2)===0) return null;
    if ($id===null && $view['inventory_observation']!=='complete') return null;
    $matches=[];
    foreach ($view['items'] ?? [] as $item) {
        if (($id===null || strcasecmp($item['form_id'] ?? '',$id)===0) && ($name==='' || ($item['display_name'] ?? '')===$name)) $matches[]=$item;
    }
    return count($matches)===1 ? $matches[0] : null;
}

function synth_player_item_validate($db, array $event, array $action, array $context, bool $acceptedAudio=false): array
{
    if (!(synth_player_item_request_allowed($event,$db) || ($acceptedAudio && ($event['type'] ?? '')==='input_audio' && ($event['payload']['control']['mode'] ?? '')!=='NARRATOR' && synth_protocol_supports_player_items($event)))
        || !synth_player_item_same_actor($action['actor'] ?? [],$context['target'] ?? [])
        || !synth_player_item_same_actor($action['target'] ?? [],$context['player'] ?? [])) throw new DomainException('player_item_explicit_request_required');
    $args=(array)($action['arguments'] ?? []);
    $item=synth_player_item_select(synth_protocol_inventory_action_context($context,$context['player'],''),$args['item'] ?? '');
    $quantity=$args['amount'] ?? 1;
    if (!$item || ($item['equipped'] ?? null)!==false || !is_int($quantity) || $quantity<1 || $quantity>1000000
        || !is_int($item['count'] ?? null) || $item['count']<$quantity
        || (strcasecmp($item['form_id'] ?? '','0x0000000F')===0 && strcasecmp($item['origin_plugin'] ?? '','Fallout4.esm')===0))
        throw new DomainException('player_item_inventory_unavailable');
    return $item;
}

function synth_player_gift_count(array $view, array $selected): ?int
{
    if (($view['inventory_observation'] ?? $view['observation'] ?? '')!=='complete') return null;
    $count=0;
    foreach ($view['items'] ?? [] as $item) {
        if (strcasecmp($item['form_id'] ?? '',$selected['form_id'])!==0 || strcasecmp($item['origin_plugin'] ?? '',$selected['origin_plugin'])!==0
            || ($item['display_name'] ?? '')!==$selected['display_name'] || ($item['form_type'] ?? null)!==$selected['form_type']) continue;
        if (!is_int($item['count'] ?? null) || $item['count']<1 || $item['count']>2147483647) return null;
        $count+=$item['count'];
    }
    return $count;
}

// A gift exists only for an accepted terminal result and a conserved, paired inventory change.
function synth_player_gift_proof(array $parent, array $action, array $context, array $result, $db=null, bool $acceptedAudio=false): ?array
{
    if (($result['schema'] ?? '')!=='synth.action.transfer-result.v2' || ($result['status'] ?? '')!=='succeeded') return null;
    try { $item=synth_player_item_validate($db,$parent,$action,$context,$acceptedAudio); }
    catch (DomainException $e) { return null; }
    $donor=$result['inventory'] ?? [];$recipient=$result['recipient_inventory'] ?? [];
    if (!synth_player_item_same_actor($donor['actor'] ?? [],$action['target']) || !synth_player_item_same_actor($recipient['actor'] ?? [],$action['actor'])) return null;
    $donorBefore=synth_player_gift_count(synth_protocol_inventory_action_context($context,$action['target'],''),$item);
    $recipientBefore=synth_player_gift_count(synth_protocol_inventory_action_context($context,$action['actor'],''),$item);
    $donorAfter=synth_player_gift_count($donor,$item);$recipientAfter=synth_player_gift_count($recipient,$item);
    $quantity=((array)$action['arguments'])['amount'] ?? 1;
    if ($donorBefore===null || $recipientBefore===null || $donorAfter===null || $recipientAfter===null
        || $donorBefore-$donorAfter!==$quantity || $recipientAfter-$recipientBefore!==$quantity) return null;
    return ['schema'=>'synth.player-gift.v1','action_id'=>$result['action_id'],'donor'=>$action['target'],'recipient'=>$action['actor'],
        'item'=>array_intersect_key($item,array_flip(['form_id','origin_plugin','display_name','form_type','value','weight'])),
        'amount'=>$quantity,'meaning'=>'gift','valuation'=>'recipient_decides'];
}

function synth_player_gift_for_event($db, array $event): ?array
{
    if (($event['type'] ?? '')!=='action_result' || (!synth_protocol_supports_player_items($event) && !synth_protocol_supports_gift_menu($event))) return null;
    $result=$event['payload']['result'] ?? [];
    if (($result['status'] ?? '')!=='succeeded' || empty($result['action_id']) || empty($result['idempotency_key'])) return null;
    $actionId=$db->escapeLiteral($result['action_id']);$key=$db->escapeLiteral($result['idempotency_key']);
    $session=$db->escapeLiteral($event['session_id']);$turn=$db->escapeLiteral($event['turn_id']);$generation=intval($event['generation']);
    $canonical=$db->escapeLiteral(synth_protocol_canonical_json($event));
    $row=$db->fetchOne("SELECT i.action_name,i.arguments,p.canonical_event,a.full_form_id,a.origin_plugin,a.playthrough_id,
            b.full_form_id AS player_form_id,b.origin_plugin AS player_plugin,b.playthrough_id AS player_playthrough
        FROM synth_meta.action_intents i
        JOIN synth_meta.action_terminal_results t ON t.action_id=i.action_id AND t.idempotency_key=i.idempotency_key
            AND t.status='succeeded' AND t.result=".$db->escapeLiteral(synth_protocol_canonical_json($result))."::jsonb
        JOIN synth_meta.protocol_events p ON p.session_id=i.session_id AND p.request_id=i.request_id AND p.generation=i.generation AND p.turn_id=i.turn_id
        JOIN synth_meta.request_executions origin ON origin.session_id=p.session_id AND origin.request_id=p.request_id
            AND origin.request_sha256=p.event_sha256 AND origin.state='complete' AND origin.error_code IS NULL
        JOIN synth_meta.protocol_events receipt ON receipt.session_id=i.session_id AND receipt.canonical_event=$canonical::jsonb
        JOIN synth_meta.request_executions accepted ON accepted.session_id=receipt.session_id AND accepted.request_id=receipt.request_id
            AND accepted.request_sha256=receipt.event_sha256 AND accepted.state IN ('claimed','streaming','complete') AND accepted.error_code IS NULL
        JOIN synth.actor_identities a ON a.id=i.actor_identity_id AND a.game='fo4'
        JOIN synth.actor_identities b ON b.id=i.target_identity_id AND b.game='fo4'
        WHERE i.action_id=$actionId AND i.idempotency_key=$key AND i.session_id=$session AND i.turn_id=$turn AND i.generation=$generation
            AND ((i.action_name='TakeItemFromPlayer' AND i.capability='action.take_item_from_player')
                OR (i.action_name='OpenInventory' AND i.capability='action.open_inventory')) AND i.state='complete'");
    if (!$row) return null;
    $parent=synth_protocol_decode_json($row['canonical_event']);
    foreach (['session_id','turn_id','generation','context_sequence','runtime_variant'] as $field) if (($parent[$field] ?? null)!==($event[$field] ?? null)) return null;
    $context=synth_protocol_current_context($db,$parent);
    if (!$context) return null;
    $action=['actor'=>['form_id'=>$row['full_form_id'],'origin_plugin'=>$row['origin_plugin'],'playthrough_id'=>$row['playthrough_id']],
        'target'=>['form_id'=>$row['player_form_id'],'origin_plugin'=>$row['player_plugin'],'playthrough_id'=>$row['player_playthrough']],
        'arguments'=>synth_protocol_decode_json($row['arguments'])];
    if ($row['action_name']==='OpenInventory') return synth_gift_menu_proof($parent,
        $action+['name'=>'open_inventory','capability'=>'action.open_inventory','server_code'=>'OpenInventory'],$context,$result,true);
    return synth_player_gift_proof($parent,$action,$context,$result,$db,true);
}

// Capture the recipient's actual save-scoped personality once; providers never read a mutable global NPC.
function synth_player_gift_perspective($db, array $event, int $actorId): ?array
{
    require_once __DIR__.'/synth_profile_state.php';
    $rows=$db->fetchAll('SELECT id,npc_name,npc_static_bio,personality,occupation,skills,speechstyle,goals FROM synth.core_npc_master WHERE actor_identity_id='.intval($actorId).' LIMIT 2');
    if (count($rows ?: [])!==1) return null;
    $profile=$rows[0];$saved=synth_profile_visible($db,$event,$actorId);
    $fields=$saved['fields'] ?? synth_profile_fields($profile);
    $bio=$profile['npc_static_bio'] ?? '';
    if (!is_string($bio) || strlen($bio)>32768 || !preg_match('//u',$bio)) return null;
    return ['actor_identity_id'=>$actorId,'profile_id'=>intval($profile['id']),'name'=>$profile['npc_name'],
        'biography'=>$bio,'profile_revision'=>$saved['revision'] ?? null,'fields'=>$fields];
}
