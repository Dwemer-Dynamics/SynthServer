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
- Ordinary and `/talk` history reads use the identity-bound hearer list. A model has not yet been
  shown to use it correctly.

It uses existing tables only, with no schema or database migration. No real provider has been
called, no provider prompt probe has run, nothing has run in game, and nothing is deployed. VR
clients do not offer the capability.
