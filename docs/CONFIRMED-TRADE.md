# Confirmed NPC trade (server)

An NPC reply can carry one validated sale offer: one unit of one item the NPC carries, for caps. The
client shows a Yes/Cancel box and only Yes moves the item and the caps. The client half is in the
SYNTH repository (`docs/CONFIRMED-TRADE.md`); the wire contract is in
[protocol/v2/README.md](../protocol/v2/README.md) ("Confirmed NPC trade"). Flat only. Neither half has
been exercised in the game; see [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md#confirmed-npc-trade).

Code: `lib/synth_trade_offer.php` (validation, receipt verification, the text the NPC is given) and
small hooks in `lib/response.php`, `lib/synth_protocol_state.php`, `lib/synth_action_inventory.php`,
`lib/synth_ingress.php`, `functions/functions.php`, `functions/json_response.php`,
`lib/synth_command_payload.php` and `lib/core/action_catalog.php`.

## Negotiation

An init that lists `action.barter` and `action.trade_confirmation` over the player-payment contract
gets `trade_confirmation: true` in its acknowledgement (`synth_trade_supported`). The ingress rejects
`action.trade_confirmation` on a v1, VR or incomplete init. The `Barter` tool is offered to the model
only for a typed or spoken turn of such a session (never rechat, narrator or action-result turns).

## The offer

The model's existing `Barter` action carries `target` (the player), `item` (exact name from its own
inventory) and `amount` (the price in caps); one unit is sold. No model-schema field was added.
`synth_trade_arguments` accepts it only when, in the admitted turn snapshot:

- the NPC's inventory observation is `complete` and holds exactly one unequipped stack of that name
  (a partial list cannot prove a name unique; caps are never sold);
- the price is a whole number from 1 to 100000 and at most ten times the stack's base value (never
  below 25 caps), so a model cannot invent a figure unrelated to the item;
- the captured player's observed caps cover it.

Anything else produces no action and no intent: the NPC's spoken line still plays, but no box is shown.
An accepted offer is an ordinary `barter` action line (`barter-action.schema.json`) with a 60 second
deadline, recorded as a normal action intent: `synth_trade_validate` rechecks everything against the
snapshot inside the owner transaction before the row is written.

## The answer and the receipt

Every outcome is the existing paired `synth.action.transfer-result.v2` (NPC inventory, then player
inventory after the answer), with `status` and `detail` from a closed vocabulary (see the protocol
README). `synth_protocol_persist_action_result` checks the same owner, generation, turn, context
sequence and capability rules as every other transfer, and additionally:

- refuses a `succeeded` result after the intent's `deadline_at` (`trade_offer_expired`);
- keeps the first terminal result: a late Yes after a Cancel or a stale answer is
  `action_result_conflict`.

`synth_protocol_bind_action_result` then verifies the sale against the original turn snapshot, not the
overlay that already contains this receipt: the NPC's item must be down by the count and its caps up by
the price, the player's item up and caps down, all four from complete observations of the right actors.
An unverified success is recorded as `failed` and told to the NPC as `unverified`. The NPC is given only
a known outcome (`completed`, `declined`, `lapsed`, `unverified`, `not_completed`); client text is never
copied into the follow-up. The Barter follow-up asks for one short reaction and no re-offer. The
accepted sale feeds the same action-inventory ancestry as every other paired receipt.

## Storage

None added. The offer and its result use the existing action-intent and terminal-result rows
(`synth_meta.action_intents`, `synth_meta.action_terminal_results`). The schema rebuild's replacement of
those rows must keep, for a `Barter` intent: the action and idempotency ids, session, request, turn and
generation; the actor (NPC) and target (player) identities; the `arguments` JSON (item, form, plugin,
count, price); a server-clock deadline comparable to now; and a terminal result with `status` and the
full JSON result. See the shared notes for the addendum text.

## Tests

`NativeTradeOfferTest` (validation, receipt verification, owner and deadline rules, NPC text) and
`NativeTradeLifecycleDatabaseTest` (offer, confirm, cancel, stale, expired, foreign generation,
unverified success and client text through the real intent, terminal-result and follow-up code on a
disposable PostgreSQL). Protocol fixtures: `response-barter.ndjson`, `barter-result-*.json`,
`init-trade.json`, `response-init-trade.ndjson` and the `*barter*` / `init-trade-*` negatives.

## Not proven

The model choosing `Barter` well, the client box and transfers, the NPC's spoken follow-up, VR (not
offered by design) and any live provider. The database test proves the server rules, not the game.
