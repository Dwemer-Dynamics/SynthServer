# Everyone audience

Everyone is an explicit public audience for one Standard or Shout turn. It is not a mode and it is not
a 200-unit range. Normal keeps the existing automatic, directed and untargeted behavior and sends none
of the new fields. Whisper (one listener within 200 units), Close (the original group within 200 units,
no random narrator) and the Standard/Shout hearing ranges are unchanged. See
[WHISPER-CLOSE-AUDIENCE.md](WHISPER-CLOSE-AUDIENCE.md).

The wire contract (`dialogue.everyone`, status `control_audience`, control `setting: "audience"`,
`audience_intent` and `audience_reset_epoch`) is in [protocol/v2/README.md](../protocol/v2/README.md).

## Admission

- Ingress accepts the two input fields only together, only on v2 `input_text`/`input_audio` of a session
  that negotiated `dialogue.everyone` and `control.menu`, with explicit Standard or Shout
  `payload.control` and a bound context sequence. Triggers, action results and tools cannot carry them.
- The first canonical claim requires the client's responder to be a non-player member of the context's
  captured audience with exactly one available captured state, in a capture whose audience observation is
  `complete` or `partial`, and compares the acknowledged epoch with the stored one under the gate, session
  and row locks. A stale epoch or an incompatible
  mode refuses the request; there is no directed fallback. A replay or pending duplicate returns before
  this check, and an admitted turn is never re-checked.

## Response and history

- The client-selected responder is only the transport. There is one provider reply and no per-hearer
  dispatch.
- Ordinary input is recorded as `(Talking to everyone)` (`(Shouting to everyone)` in Shout). The existing
  people scope already treats that label as a broadcast and keeps the captured hearers. The provider
  prompt says the player addressed everyone nearby. The transport target still selects the profile.
- Native `/talk` records the same label, adds `addressed_everyone` to the situation, keeps zero
  follow-ups and still records heard history only from an accepted delivery receipt.
- Ordinary text and audio keep their bounded continuation budget. Candidates, prompts, routes and
  continuation history use the original captured group narrowed to available members of the fresh
  capture's audience (the candidates' state exclusions apply): no newcomers and no Close distance bound,
  and the previous speaker must itself still be such a member. Close keeps its 200-unit bound. Without a
  fresh scene no bystander is assumed to hear. Everyone turns schedule no random narrator. The rechat
  root input keeps `addressed: "everyone"`.
- Quest-knowledge source verification accepts the Everyone label; its reader remains the identity-bound
  responder.
- Existing emitted-history rules are unchanged: an ordinary reply is history-visible when emitted, which
  is not proof of playback.

## State, locking and migration

- `synth_meta.runtime_sessions` gains `audience_intent` (`normal`/`everyone`, default `normal`) and
  `audience_reset_epoch` (non-negative, default 0) through the idempotent `synth_protocol.sql` and patch
  `synth_protocol_schema` 20261113002. No table is added.
- A negotiated init resets an existing session to Normal and advances its epoch; a new session starts at
  0. Without the migration the init does not acknowledge Everyone, and audience controls and changes to an
  incompatible mode fail until the update runs.
- Lock order is the audience gate, then the session advisory lock, then the `runtime_sessions` row. Global
  mode writers take the gate exclusively; Everyone claims, audience controls and status reads take it
  shared. Selecting a mode other than Standard or Shout resets every active session to Normal and advances
  its epoch in the same transaction as the `conf_opts` write. Ordinary preference edits never advance it.
- Every global mode writer uses that path: the native control claim, legacy `setconf`, legacy requested
  modes and the legacy Director reset. Legacy writers use one bounded management transaction that commits
  before any service call. Ordinary Normal input takes no gate and makes no extra query.
- `ui/api/audience_control.php` edits the preference of one explicitly chosen session generation. The
  dashboard home page's Audience fieldset lists active Everyone-capable sessions without preselecting or
  polling; Apply saves Normal or Everyone on the server for that session and generation, and the game uses
  it for future input after the existing `SYNTHControl` refresh. A timed-out save is reported as unknown
  and asks for a list refresh. Access keeps the ordinary dashboard network trust plus a CSRF token; there is
  no new login. A pending change to Normal does not revoke an acknowledged Everyone.

## Evidence boundary

Off-game checks only; acceptance is tracked in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md#everyone-audience).
The production-path PostgreSQL claim, replay and concurrency checks and the paired-client matrix have not
been run, and deployment and flat or VR gameplay are unverified.
