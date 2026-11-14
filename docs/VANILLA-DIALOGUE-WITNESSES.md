# Vanilla dialogue witnesses (server candidate)

The server admits the flat client's `context.observed_dialogue`: one vanilla NPC line, observed by
sampling, with canonical witnesses granted at the first observation. Policy
`same_cell_speaker_radius_1000_v1`, basis `first_observed`. The client documents capture
semantics, limits and ABI provenance in its own `docs/VANILLA-DIALOGUE-WITNESSES.md`. Nothing
here proves a voice start, audibility or that anyone listened.

- Admission (`lib/observed_dialogue.php`, `lib/synth_ingress.php`) requires protocol v2, the
  `flat` runtime and the negotiated capability. The observation must bind to its own context:
  no target, the audience is the speaker followed by exactly the witnesses (at most 8), and the
  witness scene and clock are the published world's. Anything else is rejected. Display names
  never grant visibility.
- Persistence writes one `backgroundchat` event row and one provenance-bound history row. That
  row records the speaker and witness identity IDs, frame, game clock ticks, snapshot sequence and
  capture age. `people` stays empty so name-matched readers cannot pick it up. A retry with
  identical input is idempotent. A differing retry is rejected as a conflict.
- Ordinary and `/talk` history reads use the identity-bound hearer list. Model answers have not
  been reviewed or accepted.

## Status

The opt-in loopback check (`unittests/loopback/`) compiles the real client driver against this
server's `main.php`, a private disposable database and the fake provider. It publishes synthetic
game state, not engine or game state. It passes (3 tests, 778 assertions). Counting the
observed-line marker in the prompts sent to the fake provider: the granted witness (Piper) has it
once in an ordinary turn and once in `/talk`. A non-witness (Nick Valentine), a different actor
also named Piper, and the granted witness after loading a save from before the observation all
have it zero times. A retry left one unchanged history row.

The full existing suite passes against a private disposable database: 1310 tests, 26571
assertions, 0 failures, 1 skip. The source tree hash was identical before and after the run. The
skipped test, `AutoGreetingRuntimeTest::testSourceWiresCanonicalActivationThroughTheNormalEventQueue`,
needs the client checkout, which that run did not provide. It was then run on its own against the
paired client commit with skips treated as failures and passed (1 test, 8 assertions).

A read-only source review of the paired client and server found no wrong-grant defect. It
accepted three conservative limits that can only miss lines, never over-grant: the audio-playing
check can lag the selected subtitle; the 5-second delivery cadence, busy gate and 4-capture queue
can drop lines in fast scenes; and strict plugin-name and text eligibility can reject unusual
input. The client records audio start and queue pressure as in-game acceptance checks.

These are prompt checks, not model answers or game acceptance. The limits above still apply:
first-observed basis, sampling, dependence on subtitle settings and residency keying, and the
fixed 1000-unit radius. It uses existing tables only, with no schema or database migration. No
real provider has been called, nothing has run in game, native gameplay and performance are
unmeasured, and nothing is deployed. VR clients do not offer the capability.
