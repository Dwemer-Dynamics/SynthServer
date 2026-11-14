# Auto Chat

Auto Chat (`AUTOCHAT`) is an explicitly selected targeted mode. The player's typed text, or the
server's own transcript of their audio, is an instruction for what the player says. The server
rewrites it into one complete player line, publishes that line immediately as the player's speech
and then lets the selected NPC answer it through the ordinary targeted route. There is no preview,
no shorthand, no NPC initiation and no approach. Legacy `**` respeech is unchanged and unused.
Open checks are in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md#auto-chat).

## Contract

- `AUTOCHAT` is in the closed control vocabulary (`lib/synth_control.php`) and the v2 mode enum
  (`protocol/v2/schemas/control.schema.json`). It arrives as a `control` mode write or as
  `payload.control.mode` on `input_text`/`input_audio`. No setting, table or capability is added.
- Native `/talk` (`intent: "conversation"`) refuses it as `conversation_mode_unsupported`.
- A mode write takes the exclusive audience gate, so selecting Auto Chat clears Everyone; Everyone
  input requires Standard or Shout. Legacy (non-native) input never inherits a global `AUTOCHAT`.
- A failed rewrite ends the turn with status `provider_failed` and a closed detail code from
  `SYNTH_AUTOCHAT_FAILURES` (for example `provider_unsupported` or `rewrite_output_unsafe`). It
  makes no NPC call and writes no echo, generated history or player line.

## Turn (`main_synth_pipeline.php`, `lib/synth_autochat.php`)

1. The claimed input binds its context. The social-task directive check, gift-menu handoff and
   raw-intent shortcuts (action classifier, wait, combat identification, quest report) skip the
   mode, so the intent is never run as a command.
2. The raw intent is frozen unchanged. For `input_text` the canonical event keeps the original
   typed text and `dialogue_origin_policies.input_text` holds that same text. For `input_audio`
   the canonical event keeps only its original audio media reference (it gains no text), and the
   trusted server transcript is frozen in `dialogue_origin_policies.input_text`.
3. One rewrite call (`lib/synth_player_rewrite.php`, `drivers/llm/PlayerRewriteDriver.php`)
   replaces only the derived input text; the raw transcript echo is suppressed.
4. The generated line is stored once as the turn's player history row and published once as player
   speech. The NPC then answers with the existing response policy, actions and continuations.

## Rewrite

- Context: the claimed snapshot's player and its bound target only. History is the saved-ancestry
  native reader (older-save cutoffs apply) narrowed to the pair: player input addressed to the
  target and the target's own replies. Witnessed lines of other NPCs and observed vanilla dialogue
  are left out, never relabelled. Player bio and style come from the configured player profile.
- Model: the configured `CORE_CONNECTOR_PLAYER` row with the existing `HTTP_TIMEOUT`. Supported:
  the fresh-install default OpenRouter row #2 (non-reasoning `openrouterjson`) and the Quickstart
  non-reasoning `openaijson` row. Any other or advanced configuration (reasoning families, extra
  parameters, unknown metadata) fails closed before config or network.
- Bounds: intent 4096 characters/16 KiB, prompt 64 KiB, output 4096 bytes, never truncated. Output
  that the input adapter would alter (pipes, repeated whitespace) is refused.
- Ownership: each step rechecks that the claimed request is unchanged and active. Nothing is
  retried after an uncertain provider outcome; a duplicate claim replays the stored terminal or
  answers `request_pending`, and the rewrite is never invoked again. Remote exactly-once is not
  promised.

## Publication and history

- The line is emitted once with `speech_role` `player`, the canonical player as speaker and the
  exact target as listener (`addressed` route), under utterance id `autochat.<sha256>` of the
  session, request, generation, turn and context sequence.
- With a configured player TTS connector, audio for the line is synthesized synchronously before
  buffering; synthesis is not playback. A synthesis failure keeps the line visible with a failed
  speech status. Otherwise it is text-only.
- It counts as published only once buffered; a buffering failure ends the root before the NPC call.
- The history row (`synth_autochat_freeze_input`, `sql::insertNativeHistoryOnce`) holds exactly
  `<player>: <line> (Talking to <target>)` with `delivery_state` `received`: accepted as this turn's
  input, never `spoken`. Re-entry reuses one identical row; a conflicting or extra row is refused.
- Raw intent, generated history and publication stay separate. The server never labels the line
  spoken, played or heard from its buffers and adds no receipt marker.
- Rechat for an Auto Chat root uses the stored generated line (the root's only player line, same
  route, target and utterance id) instead of the raw intent, and fails closed otherwise.

Cost: one added rewrite call per Auto Chat root, plus one player TTS synthesis call when configured. No new
workers, polling or retries; ordinary routes are unchanged.

## Automated checks

Database-free: `JsonSchemaCatalogTest` (vocabulary, Everyone exclusion), `NativeDialogueTurnTest`
(`/talk` refusal), `PlayerTtsHelpersTest` (single publication, no echo, buffering failure) and
`ResponseRuntimeRegressionTest` (stage gates, failure codes, frozen history text, pair-only context,
shortcut exclusion, rechat root, pipeline order). Disposable PostgreSQL:
`NativeRelationshipDispatchTest` (history reuse and refusal). Paired with the compiled client:
`testCompiledClientAutoChat` ([unittests/loopback/README.md](../unittests/loopback/README.md)).
Run them on the exact commits under review; none is gameplay evidence.
