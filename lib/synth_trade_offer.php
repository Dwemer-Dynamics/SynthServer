<?php
// Confirmed NPC sale: the NPC offers one stack of one item it carries for caps, the player answers in a
// client confirmation box, and nothing moves without that Yes. Contract: protocol/v2/README.md,
// "Confirmed NPC trade". The offer is an ordinary `barter` action intent (synth_meta.action_intents), and
// every outcome is the existing paired transfer receipt (synth.action.transfer-result.v2); no table is added.

const SYNTH_TRADE_CAPS_FORM_ID = '0x0000000F';
const SYNTH_TRADE_PLAYER_FORM_ID = '0x00000014';
const SYNTH_TRADE_MAX_COUNT = 99;
const SYNTH_TRADE_MAX_PRICE = 100000;
const SYNTH_TRADE_DEADLINE_MS = 60000;
// Reasons the client may report on a cancelled/timed-out receipt. Anything else is "not completed".
const SYNTH_TRADE_REASONS = [
    'player_declined' => 'the player declined the offer',
    'stale_cell_changed' => 'the offer lapsed because the scene changed',
    'stale_inventory_changed' => 'the offer lapsed because an inventory changed before the player answered',
    'stale_caps_changed' => 'the offer lapsed because the player no longer had the caps',
    'stale_actor_unavailable' => 'the offer lapsed because the seller was no longer available',
    'expired' => 'the offer expired unanswered',
];

// Offer, confirm and receipt contract: flat only, and only an init that negotiated every prerequisite.
function synth_trade_supported(array $event): bool
{
    return ($event['protocol_version'] ?? 1) === 2 && ($event['runtime_variant'] ?? '') === 'flat'
        && array_diff(['action.barter', 'action.trade_confirmation', 'action.inventory_observation',
            'dialogue.turn_ownership'], $event['capabilities'] ?? []) === []
        && synth_protocol_supports_transfer($event) && synth_protocol_supports_player_caps($event);
}

// A sale needs the live scene's own conversation; rechat, narrator and action-result continuations cannot start one.
function synth_trade_request_allowed(array $event): bool
{
    return synth_trade_supported($event) && !synth_protocol_owns_rechat($event)
        && in_array($event['type'] ?? '', ['input_text', 'input_audio'], true);
}

function synth_trade_actor_key(array $actor): string
{
    return strtolower(strval($actor['form_id'] ?? '')).'|'.strtolower(strval($actor['origin_plugin'] ?? ''))
        .'|'.strval($actor['playthrough_id'] ?? '');
}

// Maps a (possibly partial) context snapshot to one actor's inventory view; null when the actor has none.
function synth_trade_inventory(array $context, array $actor): ?array
{
    $view = synth_protocol_inventory_action_context($context, $actor, '');
    return $view['inventory_observation'] === 'unavailable' ? null : $view;
}

function synth_trade_stack_count(array $view, string $formId, string $plugin): int
{
    $count = 0;
    foreach ($view['items'] as $item)
        if (strcasecmp($item['form_id'] ?? '', $formId) === 0 && strcasecmp($item['origin_plugin'] ?? '', $plugin) === 0)
            $count += intval($item['count']);
    return $count;
}

// Exactly one named, unequipped, tradeable stack in a complete observation. A partial list cannot prove the
// name is unique, so it never produces an offer; a refused item is never replaced by a different one.
function synth_trade_find_item(array $context, array $seller, string $name): ?array
{
    $view = synth_trade_inventory($context, $seller);
    if ($view === null || $view['inventory_observation'] !== 'complete') return null;
    $matches = [];
    foreach ($view['items'] as $item) {
        if (strcasecmp(trim($item['display_name'] ?? ''), trim($name)) === 0) $matches[] = $item;
    }
    if (count($matches) !== 1) return null;
    $item = $matches[0];
    if (strcasecmp($item['form_id'], SYNTH_TRADE_CAPS_FORM_ID) === 0 || ($item['equipped'] ?? true) !== false) return null;
    return $item;
}

// Sane price: whole caps in 1..100000, at most ten times the stack's base value (never below 25) so a
// model cannot invent a figure unrelated to the item.
function synth_trade_price_sane(int $price, array $item, int $count): bool
{
    if ($price < 1 || $price > SYNTH_TRADE_MAX_PRICE) return false;
    $ceiling = max(25, 10 * max(0, intval($item['value'] ?? 0)) * $count);
    return $price <= min(SYNTH_TRADE_MAX_PRICE, $ceiling);
}

// Validate a model `Barter` line against the admitted turn snapshot. The model's fields are
// target=player, item=exact item name, amount=price in caps; one unit is sold. Returns the action
// arguments or null; the caller has already bound the NPC speaker and the captured player.
function synth_trade_arguments(array $context, array $seller, array $player, array $line): ?array
{
    $metadata = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
    foreach (['item', 'amount', 'target'] as $key)
        if (array_key_exists($key, $line) && array_key_exists($key, $metadata) && $line[$key] !== $metadata[$key]) return null;
    $name = $line['item'] ?? $metadata['item'] ?? null;
    $price = $line['amount'] ?? $metadata['amount'] ?? null;
    if (is_string($price) && preg_match('/^[1-9][0-9]{0,5}$/D', $price) === 1) $price = intval($price);
    if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 160 || preg_match('//u', $name) !== 1
        || !is_int($price)) return null;
    $count = 1;
    $item = synth_trade_find_item($context, $seller, $name);
    if ($item === null || intval($item['count']) < $count || !synth_trade_price_sane($price, $item, $count)) return null;
    if (strcasecmp($player['form_id'] ?? '', SYNTH_TRADE_PLAYER_FORM_ID) !== 0
        || !synth_protocol_caps_inventory_allows($context, $player, $price)) return null;
    return ['item' => $item['display_name'], 'item_form_id' => $item['form_id'],
        'item_origin_plugin' => $item['origin_plugin'], 'count' => $count, 'price' => $price];
}

// Whole-action check used when the intent is recorded (and again on any replay path).
function synth_trade_validate(array $event, array $action, array $context): void
{
    $arguments = $action['arguments'] instanceof stdClass ? get_object_vars($action['arguments']) : $action['arguments'];
    if (!synth_trade_request_allowed($event)) throw new DomainException('trade_contract_required');
    if (($action['name'] ?? '') !== 'barter' || ($action['capability'] ?? '') !== 'action.barter'
        || ($action['server_code'] ?? 'Barter') !== 'Barter' || !is_array($arguments)
        || array_diff(array_keys($arguments), ['item', 'item_form_id', 'item_origin_plugin', 'count', 'price']) !== []
        || !is_array($action['actor'] ?? null) || !is_array($action['target'] ?? null)
        || strcasecmp($action['target']['form_id'] ?? '', SYNTH_TRADE_PLAYER_FORM_ID) !== 0
        || strcasecmp($action['actor']['form_id'] ?? '', SYNTH_TRADE_PLAYER_FORM_ID) === 0
        || synth_trade_actor_key($action['actor']) === synth_trade_actor_key($action['target'])
        || ($action['deadline_ms'] ?? 0) !== SYNTH_TRADE_DEADLINE_MS)
        throw new DomainException('invalid_trade_offer');
    $seller = $action['actor'];
    $item = synth_trade_find_item($context, $seller, strval($arguments['item'] ?? ''));
    if ($item === null || strcasecmp($item['form_id'], strval($arguments['item_form_id'] ?? '')) !== 0
        || strcasecmp($item['origin_plugin'], strval($arguments['item_origin_plugin'] ?? '')) !== 0
        || !is_int($arguments['count'] ?? null) || $arguments['count'] < 1 || $arguments['count'] > SYNTH_TRADE_MAX_COUNT
        || $arguments['count'] > intval($item['count']) || !is_int($arguments['price'] ?? null)
        || !synth_trade_price_sane($arguments['price'], $item, $arguments['count'])
        || !synth_protocol_caps_inventory_allows($context, $action['target'], $arguments['price']))
        throw new DomainException('trade_offer_unavailable');
}

// Receipt check: item and caps each moved exactly once, in the right direction, between the two named
// actors, from the snapshot the offer was validated against. Anything else is reported as unverified.
function synth_trade_verify_receipt(array $arguments, array $context, array $seller, array $player, array $result): array
{
    $count = intval($arguments['count'] ?? 0);
    $price = intval($arguments['price'] ?? 0);
    $views = ['seller' => synth_trade_inventory($context, $seller), 'player' => synth_trade_inventory($context, $player)];
    if ($views['seller'] === null || $views['player'] === null || $count < 1 || $price < 1)
        return ['verified' => false, 'reason' => 'before_inventory_unavailable'];
    $after = ['seller' => $result['inventory'] ?? null, 'player' => $result['recipient_inventory'] ?? null];
    foreach (['seller' => $seller, 'player' => $player] as $role => $identity) {
        $inventory = $after[$role];
        if (!is_array($inventory) || ($inventory['observation'] ?? '') !== 'complete'
            || synth_trade_actor_key($inventory['actor'] ?? []) !== synth_trade_actor_key($identity))
            return ['verified' => false, 'reason' => 'after_inventory_unobserved'];
        if ($views[$role]['inventory_observation'] !== 'complete')
            return ['verified' => false, 'reason' => 'before_inventory_incomplete'];
    }
    $itemForm = strval($arguments['item_form_id']);
    $itemPlugin = strval($arguments['item_origin_plugin']);
    $stack = static function (array $inventory, string $form, string $plugin): int {
        $total = 0;
        foreach ($inventory['items'] as $item)
            if (strcasecmp($item['form_id'], $form) === 0 && strcasecmp($item['origin_plugin'], $plugin) === 0) $total += $item['count'];
        return $total;
    };
    $expected = [
        ['seller', $itemForm, $itemPlugin, -$count], ['player', $itemForm, $itemPlugin, $count],
        ['seller', SYNTH_TRADE_CAPS_FORM_ID, 'Fallout4.esm', $price], ['player', SYNTH_TRADE_CAPS_FORM_ID, 'Fallout4.esm', -$price],
    ];
    foreach ($expected as [$role, $form, $plugin, $delta]) {
        $before = synth_trade_stack_count($views[$role], $form, $plugin);
        if ($stack($after[$role], $form, $plugin) - $before !== $delta)
            return ['verified' => false, 'reason' => 'not_conserved'];
    }
    return ['verified' => true, 'reason' => 'conserved'];
}

// Text the seller is given for any terminal outcome. Client text is never copied; only a known reason code is named.
function synth_trade_outcome_detail(array $arguments, array $result, array $verification): array
{
    $offer = ['item' => strval($arguments['item'] ?? ''), 'count' => intval($arguments['count'] ?? 0),
        'price_caps' => intval($arguments['price'] ?? 0)];
    $detail = strval($result['detail'] ?? '');
    $status = strval($result['status'] ?? '');
    if ($status === 'succeeded') {
        return $verification['verified']
            ? ['outcome' => 'completed', 'offer' => $offer,
                'guidance' => 'The player accepted. The item and caps moved and both inventories were verified. Say a brief in-character closing line.']
            : ['outcome' => 'unverified', 'offer' => $offer,
                'guidance' => 'The client reported a sale but the inventories could not be verified. Do not claim the exchange happened; say nothing about the item or caps changing hands.'];
    }
    $reason = array_key_exists($detail, SYNTH_TRADE_REASONS) ? $detail : null;
    if ($reason === 'player_declined')
        return ['outcome' => 'declined', 'offer' => $offer, 'reason' => SYNTH_TRADE_REASONS[$reason],
            'guidance' => 'The player declined. Nothing changed hands. Accept it gracefully in one short line; do not pressure or re-offer.'];
    if ($reason !== null)
        return ['outcome' => 'lapsed', 'offer' => $offer, 'reason' => SYNTH_TRADE_REASONS[$reason],
            'guidance' => 'The offer is void and nothing changed hands. Mention it only if natural, and do not claim a sale.'];
    return ['outcome' => 'not_completed', 'offer' => $offer,
        'guidance' => 'The sale did not complete and nothing is known to have changed hands. Do not claim a sale.'];
}
