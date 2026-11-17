# Companion orders (server)

`FollowPlayer`, `StopFollowing` and `TakeASeat` become typed `follow_player`, `stop_following` and `take_a_seat`
actions. The server names the order; the game applies it. Paired client note: SYNTH `docs/COMPANION-ORDERS.md`.
Source and disposable-test checks only; nothing here was run in the game.

- **Negotiation.** The catalogue offers an order only for flat protocol-v2 owned player text/audio input whose
  client advertised `dialogue.turn_ownership`, `action.order_verified` and the order's own capability
  (`lib/synth_order_actions.php`). VR, autonomous turns, narration and older clients never see them.
- **No arguments.** The model leaves every parameter blank. A target, argument, seat or place is refused when the
  command is encoded and again when the intent is emitted. The order addresses only the captured NPC.
- **Bound to the reply.** The action carries `reply_line_id`, the first dialogue line of the same NPC in the same
  response. An order that precedes its reply, belongs to another NPC, or follows a second order in the request is
  not emitted.
- **Receipt.** The client's bounded `synth.action.order.result.v1` must match the action, actor and flags (an owned
  package for Follow and Sit, none for Stop). Anything else is reported to the NPC as unconfirmed, never as success.
  Stop does not dismiss a native follower.
- **Protocol.** `protocol/v2/schemas/order-action.schema.json`, an optional `reply_line_id` in the v1 action schema,
  three valid and six invalid fixtures; the tree is byte-identical to the client's.

Tests: `CompanionOrderActionTest` (database-free). Not covered here: the database-backed emission and result path
(`synth_protocol_action_from_legacy_line`, `synth_protocol_record_action_intent`); it follows the Wait pattern but was
not run against a disposable database. There is no explicit-phrase or Jev hand-off shortcut as Wait has; orders use
the ordinary Jev-narrowed catalogue.
