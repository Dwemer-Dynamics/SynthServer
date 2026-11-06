<?php

// A general offer opens a user-controlled exchange; naming an item keeps the direct-transfer route.
function synth_player_gift_statement_kind(string $text): string
{
    if (strlen($text)>8192 || preg_match('/\b(?:don[\x27\x{2019}]t|do not|never|not|stop|if|suppose|pretend|borrow|loan|trade|sell|buy|said|says|asked|told|say|repeat|quote|example)\b/iu',$text)) return 'none';
    if (!preg_match('/(?:^|[,;.!:]\s*)I (?:have|have got|brought|got) (?:a |this )?gift for you\b(.*)$/isu',$text,$match)) return 'none';
    return trim($match[1]," \t\r\n.,!?:;-")==='' ? 'general' : 'named';
}

function synth_protocol_supports_gift_menu(array $event): bool
{
    return synth_protocol_supports_transfer($event)
        && array_diff(['action.open_inventory','action.gift_menu_inventory'],$event['capabilities'] ?? [])===[];
}

function synth_gift_menu_request_allowed(array $event): bool
{
    return synth_protocol_supports_gift_menu($event) && ($event['payload']['control']['mode'] ?? '')!=='NARRATOR'
        && ($text=synth_player_item_request_text($event))!==null && synth_player_gift_statement_kind($text)==='general';
}

// A general gift is a request to choose items in the game UI. The NPC reacts after receipt.
function synth_gift_menu_try_handoff($db, array $event): bool
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || empty($GLOBALS['FUNCTIONS_ARE_ENABLED'])
        || !synth_json_response_enabled() || !synth_gift_menu_request_allowed($event)
        || !in_array('OpenInventory', $GLOBALS['ENABLED_FUNCTIONS'] ?? [], true)
        || !synthActionCodeIsNegotiated('OpenInventory')) return false;

    $context = synth_protocol_current_context($db, $event);
    if (!isset($context['target']['display_name'])) return false;
    synth_protocol_assert_request_active();
    synth_buffer_command_response_line($context['target']['display_name'], synthEncodeCommandAction('OpenInventory'));
    synth_emit_buffered_json_response();
    return true;
}

function synth_gift_menu_validate(array $event,array $action,array $context,bool $acceptedAudio=false): void
{
    $arguments=(array)($action['arguments'] ?? []);
    if (!synth_protocol_supports_gift_menu($event)
        || !(synth_gift_menu_request_allowed($event) || ($acceptedAudio && ($event['type'] ?? '')==='input_audio' && ($event['payload']['control']['mode'] ?? '')!=='NARRATOR'))
        || ($action['name'] ?? '')!=='open_inventory' || ($action['capability'] ?? '')!=='action.open_inventory'
        || (isset($action['server_code']) && $action['server_code']!=='OpenInventory') || $arguments!==[]
        || !synth_player_item_same_actor($action['actor'] ?? [],$context['target'] ?? [])
        || !synth_player_item_same_actor($action['target'] ?? [],$context['player'] ?? [])
        || strcasecmp($action['actor']['form_id'] ?? '','0x00000014')===0
        || strcasecmp($action['target']['form_id'] ?? '','0x00000014')!==0
        || ($action['target']['origin_plugin'] ?? '')!=='Fallout4.esm') throw new DomainException('gift_menu_explicit_request_required');
    foreach ([$context['player'],$context['target']] as $actor) {
        $view=synth_protocol_inventory_action_context($context,$actor,'');
        if (($view['inventory_observation'] ?? '')!=='complete') throw new DomainException('gift_menu_inventory_unavailable');
    }
}

// Collapse only exact base/plugin/type/name rows; equipment changes in the user-controlled menu are permitted.
function synth_gift_menu_counts(array $view): ?array
{
    if (($view['inventory_observation'] ?? $view['observation'] ?? '')!=='complete' || count($view['items'] ?? [])>512) return null;
    $counts=[];
    foreach ($view['items'] ?? [] as $item) {
        if (!is_string($item['form_id'] ?? null) || !is_string($item['origin_plugin'] ?? null) || !is_string($item['display_name'] ?? null)
            || !is_int($item['form_type'] ?? null) || !is_int($item['count'] ?? null) || $item['count']<1 || $item['count']>2147483647) return null;
        $key=json_encode([strtoupper($item['form_id']),strtolower($item['origin_plugin']),$item['form_type'],$item['display_name']],JSON_THROW_ON_ERROR);
        $counts[$key] ??= ['item'=>array_intersect_key($item,array_flip(['form_id','origin_plugin','display_name','form_type','value','weight'])),'count'=>0];
        $counts[$key]['count']+=$item['count'];
    }
    ksort($counts,SORT_STRING);return $counts;
}

function synth_gift_menu_proof(array $parent,array $action,array $context,array $result,bool $acceptedAudio=false): ?array
{
    if (($result['schema'] ?? '')!=='synth.action.transfer-result.v2' || ($result['status'] ?? '')!=='succeeded') return null;
    try { synth_gift_menu_validate($parent,$action,$context,$acceptedAudio); }
    catch (DomainException $error) {return null;}
    if (!synth_player_item_same_actor($result['inventory']['actor'] ?? [],$action['target'])
        || !synth_player_item_same_actor($result['recipient_inventory']['actor'] ?? [],$action['actor'])) return null;
    $beforePlayer=synth_gift_menu_counts(synth_protocol_inventory_action_context($context,$action['target'],''));
    $beforeNpc=synth_gift_menu_counts(synth_protocol_inventory_action_context($context,$action['actor'],''));
    $afterPlayer=synth_gift_menu_counts($result['inventory']);$afterNpc=synth_gift_menu_counts($result['recipient_inventory']);
    if ($beforePlayer===null || $beforeNpc===null || $afterPlayer===null || $afterNpc===null) return null;
    $keys=array_unique([...array_keys($beforePlayer),...array_keys($beforeNpc),...array_keys($afterPlayer),...array_keys($afterNpc)]);
    sort($keys,SORT_STRING);$gifts=[];$outgoing=[];
    foreach ($keys as $key) {
        $playerLoss=($beforePlayer[$key]['count'] ?? 0)-($afterPlayer[$key]['count'] ?? 0);
        $npcGain=($afterNpc[$key]['count'] ?? 0)-($beforeNpc[$key]['count'] ?? 0);
        if ($playerLoss!==$npcGain) return null;
        if ($playerLoss>0) $gifts[]=['item'=>$beforePlayer[$key]['item'],'amount'=>$playerLoss];
        if ($npcGain<0) $outgoing[]=['item'=>$beforeNpc[$key]['item'],'amount'=>-$npcGain];
    }
    if (!$gifts || count($gifts)>32 || count($outgoing)>32) return null;
    return ['schema'=>'synth.player-gift-batch.v1','action_id'=>$result['action_id'],'donor'=>$action['target'],'recipient'=>$action['actor'],
        'items'=>$gifts,'received_from_npc'=>$outgoing,'meaning'=>'gift','valuation'=>'recipient_decides'];
}
