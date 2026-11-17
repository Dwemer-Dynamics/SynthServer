# ActionsManager

Model-named actions that the game applies. Today it holds `CompanionOrders`; the two-step resolver joins it
(SynthServer #68).

## CompanionOrders

`FollowPlayer`, `StopFollowing` and `TakeASeat` become typed `follow_player`, `stop_following` and
`take_a_seat` actions. The server names the order and binds it to the NPC's own first reply line; the game
applies it. See `docs/COMPANION-ORDERS.md` for the contract and `tests/ActionsManager/CompanionOrdersTest.php`.

Legacy hooks (thin call-throughs): `functions/functions.php`, `lib/response.php`,
`lib/synth_command_payload.php`, `lib/synth_protocol_state.php`.

## TwoStepActions

`Synth\Managers\ActionsManager\TwoStepActions` is the optional (off by default) second call that picks one
negotiated action for an action-free reply. Contract, settings and limits: `docs/TWO-STEP-ACTIONS.md`;
tests: `unittests/tests/TwoStepActionTest.php`.

Legacy hooks (thin call-throughs): `main_synth_pipeline.php` (`prepare`) and `lib/data_functions.php`
(`filterActions`).
