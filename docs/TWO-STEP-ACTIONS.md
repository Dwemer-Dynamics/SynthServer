# Optional two-step action resolution

Off by default. When the dialogue call produced speech but **no action** for an owned native player turn
(`input_text` / `input_audio`, protocol v2, flat or VR), one second, short call may pick an action for it.
It is a server-only option: no client change, protocol schema or capability is added, and no table is used.

## Settings

`TWO_STEP_ACTIONS_ENABLED` (default `false`), `CORE_CONNECTOR_TWO_STEP` (a connector row; `0` means no second
call) and `TWO_STEP_ACTION_TIMEOUT_MS` (default 4000, clamped to 500..10000), under **Global Settings > Global
Connectors**. The connector must be an `openaijson` row or a non-reasoning `openrouterjson` row, the same set Auto
Chat's player connector accepts (`PlayerRewriteDriver`); other rows fail closed.

## Behaviour

- The catalogue shown to the second call is exactly the one already negotiated for the request: enabled for the
  NPC, advertised by the client (`SYNTH_CLIENT_CAPABILITIES`/event capabilities, so flat-only actions stay out of
  VR), `synthActionCodeIsNegotiated()`, and permitted by the Jev policy (`synth_jev_action_code_allowed()`).
  Jev and the catalogue are not replaced; two-step can only choose something they already allow.
- The model answers `{"action":"<name or none>","arguments":{}}`. `none`, Talk, an unknown name, undeclared or
  mistyped arguments, an argument outside a declared enum (Inspect takes only a listed actor) or a missing
  required argument all mean no action. Nothing is guessed.
- The result is handed to the ordinary command path as a `synth.action.v1` line, so
  `synth_protocol_action_from_legacy_line()` still validates actor, target, capability and arguments, records the
  intent and applies Jev's one-action rule. An Inspect pick is followed up by the existing action-result
  continuation like any Inspect.
- One attempt, no retry, `max_tokens` 128, temperature 0, no history. An answer arriving after the budget is
  dropped. Cancellation propagates. Diagnostics (`[TWO STEP ACTION]`) hold only a fixed reason, elapsed time and
  the chosen code, never the utterance, reply or key.
- Cost: with the switch on, every eligible action-free reply adds one sequential call after the reply has been
  produced (the lines stream first, as in single-step). The call is skipped when the catalogue is empty or no
  connector is set.

## Limits

The provider wall-clock is bounded by the driver's own transport timeout (openrouterjson: at least 30 s), so the
budget is enforced on the answer, not on the socket. Not exercised with a live model or in game.
