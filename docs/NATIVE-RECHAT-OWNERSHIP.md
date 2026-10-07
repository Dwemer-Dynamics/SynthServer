# Native rechat continuation cutover

## Atomic preparation checkpoint (2026-09-07)

9d0e33c coordinates mode/candidate selection, exact selected-profile H/P/action
policy, reservation and selection under one outer savepoint. It records policy
provenance, refuses unproven staged chains, and rechecks selected live bindings.
PHP 216/3293 and SQL/emitter/prepare 106 checks pass; fixtures rolled back.
No live dispatcher integration or provider/game proof.

The next entry-point cutover must pass trusted server defaults captured before
profile globals and the original owned private-mode decision. Do not pass
current target-profile globals or client payload controls. Use preparation's
stored role/identity/profile for shared response ownership, profile/voice and
history/prompt routing, and recheck binding at loading. The low-level staged
reservation/selection APIs alone are not generation authorization.
Keep the readiness guard/capability disabled until coordinated runtime gates pass.

## Canonical selection checkpoint (2026-09-07)

67a91d5 / source schema 20260907003 adds persistent NPC/narrator/terminal
selection. Captured canonical participants and their exact profile rows
drive tight/conversational/group ranking, eligibility and chain-local narrator
choice. PHP 215/3283 and SQL/emitter/selection probe 89 checks pass, rolled back.
This is not wired to live response ownership or profile/voice loading.

The next coordinator must resolve the first mode/candidate before deriving
that candidate's profile H/P/action policy, then atomically reserve and store
selection. The staged admission API currently accepts already-derived policy;
do not call it using original-target globals. Revalidate stored NPC/profile
IDs before use; historical selection evidence must not block management
deletion. Shared response readers, owned chain prompt/history, narrator
continuation, concurrent races and main/provider/gameplay proof remain open.
The top-level readiness guard and disabled client capability remain.

## Action ancestry checkpoint (2026-09-07)

Product 37ba129 implements action-result chain inheritance. It proves each
completed origin, canonical owner/hash, saved terminal receipt and emitted
action identity/arguments before reaching an input root or reserved rechat
ancestor. Cycles, incomplete edges and ambiguous chain links are rejected.
The prior rechat_action_ancestry_not_ready guard is replaced by these checks.
Root-linked aliases reuse the existing budget, including exhausted state.
PHP 214/3270 and real SQL/emitter/receipt probe 88 checks pass; all fixtures
rolled back. See the goal ledger for the probe hash and synthetic-intent limit.
Speaker selection and dispatcher/profile/voice integration are still pending.
The top-level owned_rechat_not_ready guard and disabled capability remain.

## Durable admission checkpoint (2026-09-07)

Product 41a47ff / source schema 20260907002 adds chain and continuation rows.
The admission API reserves one child per entire parent response, persists
mode/budget/private outcome once, increments rounds once, preserves exhaustion
and returns the same per-request reservation on replay. Its savepoint preserves
the caller's transaction on SQL failure. Existing parent proof is required.
72 real SQL/emitter checks and PHP 213 tests / 3260 assertions pass.

This API is not yet called by the dispatcher. Reservations do not contain a
selected actor and cannot authorize profile/model/speech work. Policy must be
derived from the selected authoritative profile, not supplied by the client.
Action-result ancestry still raises rechat_action_ancestry_not_ready rather
than accidentally starting a fresh budget. Narration/action policy is frozen,
not executed. Multi-process admission races remain untested.
Keep the capability disabled until the remaining coordinated work below lands.

## Durable route evidence checkpoint (2026-09-07)

Server 52ff3ff implements independent emitted route persistence and a completed
parent evidence reader. Schema 20260907001 is source-only. The emitter captures
the exact wire hash and canonical speaker/listener state under the native lock.
Completion evidence is proven against the stored full response and original
canonical ingress, not the presence of a pending route or a provider return.

Real emitter/completion PostgreSQL probe: 45 checks, all synthetic data rolled
back; PHP 212 tests / 3189 assertions. This does NOT implement one-child
reservation, chain budget/selection, early dispatch or prompt integration.
The reader currently requires the last NPC line; before enablement coordinate
last-eligible semantics with client actor availability/suppression/cooldowns.
Also reconcile request-derived line IDs with the 128-character parent ID bound.
The client capability and server owned_rechat_not_ready guard remain unchanged.
No deployment or game testing. Continue the remaining implementation below.

## Staged contract checkpoint (2026-09-07)

The first contract/queue step is implemented in client 6c42a7b and server
5c6471b, but deliberately disabled. The client now retains actual Line parent
IDs with the original actor, snapshot and ownership in one PendingRechat item.
The optional v2 capability requires the paired IDs and turn ownership.
The plugin does not advertise it. Server binding raises
owned_rechat_not_ready before reaching the legacy path.

Remove that guard only after durable emitted routes, completed-parent proof,
transactional selection/budget/replay and early owned dispatch are implemented
and verified. Explicit server readiness acknowledgement is still required;
client wire selection alone is not negotiation or completion proof.

Both DLL builds and 17 native suites passed; fake-client checks 510;
protocol 29 schemas / 140 fixtures / 157 mirrored files; PHP 211 tests /
3170 assertions. Both internal package audits passed. The 17-check SQL
diagnostic still reproduces legacy defects; it is not a working rechat test.
No deployment or in-game acceptance. Full parity remains active.

The following audit describes the pre-contract path. Item 1 is now addressed
in staged source; the remaining routing/decision/integration work is open.

## Verified status (2026-09-07)

REPRODUCED / NOT IMPLEMENTED. Source server 79fabf8 (product d9fd4cd),
client 13bbecf (client product fc2a484). The previous action reply milestone
does not establish native NPC-to-NPC continuation. This is required full-parity
work, not an optional expansion. The canonical profile migration remains
source-only; do not deploy it before the coupled gates are complete.

Actual SQL/binder/parser diagnostic:
`<scratch>/rechat-binding-defect-sql-probe.php`

SHA256: B4BF1C19B766DB3FA55420D3F637E28C8CF9ADC6AC61FAA5B05190DF1EC74C2A.

Seventeen defect-detection checks pass using only
synth_parity_context_20260904. All session/context/actor fixtures roll back.
Passing means the defects were reproduced, not that rechat works. No provider,
live user database, runtime deployment, client build or gameplay was involved.

## Current path and mismatches

1. Client PluginSession::handle_line retains the last eligible dialogue actor,
   original copied snapshot and ContextBinding. Line already contains request_id
   and line_id, but queue_rechat sends neither; only actor and original context
   ownership reach SynthClient::trigger and encode_trigger.
2. synth_protocol_bind_trigger_context requires every trigger actor, including
   rechat's last speaker, to equal the original captured target. A second
   contextual NPC cannot continue even if it just spoke. The SQL diagnostic
   confirms trigger_actor_mismatch for a same-name non-target actor.
3. The adapter produces npc/npc_id/npc_plugin plus audience_snapshot as a pipe
   string. synthParseServerSideRechatPayload expects speaker, speaker_formid,
   listener_hint and audience (or a structured audience_snapshot). The actual
   parser loses the captured audience and uses mutable SYNTH_NAME as speaker.
   It receives neither a durable parent nor a round counter.
4. synthResolveServerSideRechatTarget selects by name and deduplicates names.
   Its fallback reads latest legacy nearby state. synthSwitchActiveNpcProfile
   can then disagree with the original target still governing native writers.
5. synthBuildServerSideRechatSessionKey hashes names when chain_id is absent.
   The diagnostic changes session, turn, generation and runtime lane while
   preserving the identical resulting key. Budget/mode temp files therefore
   are not native ownership. Main also unlinks exhausted budget state, allowing
   a later request to pre-roll it again rather than preserving terminal state.
6. synth_emit_json_response_envelope emits speaker/text/speech but discards
   listener metadata. The client's closed dialogue payload rejects extra
   fields beyond speaker/text/media/speech. Optional relationship route capture
   is disabled with relationship evaluation and cannot be rechat's authority.

## Required coordinated implementation

### Exact parent contract

- Carry reply_to_request_id and reply_to_line_id from the actual eligible
  dialogue Line through the pending rechat item, queue and encoder. Keep the
  original generation/turn/context/cancellation binding. Do not recapture the
  world, derive a parent from a name, or guess the latest completed request.
- Add a negotiated native continuation capability and mirrored optional v2
  payload fields. Require the parent pair when that capability is advertised;
  reject the fields on non-rechat triggers. Preserve v1 and old capability-off
  contracts. Do not advertise the new capability until both runtime sides work.
- Validate the exact completed parent response body plus canonical originating
  event: same session/turn/generation/context/runtime; exact line ID and
  canonical last eligible NPC speaker. A provider return or pending prefix is
  not parent completion. Supersession/cancellation still wins.
- Permit only one accepted continuation per parent response, even if callers
  change child request IDs or choose a different split-sentence line. Replays
  of the same child return its saved decision/body without spending another round.

### Durable routing and selection

- Persist emitted canonical speaker/listener routing independently of optional
  relationship evaluation. Bind it to the response request and line, preserving
  explicit unavailable/ambiguous listener state. Use the existing captured
  identity/FormID metadata; never turn an ambiguous label into a listener.
- Add transactional native chain/decision rows keyed by the exact ownership
  tuple and parent response. Store the resolved mode, original pre-rolled
  budget, used round, selected canonical actor/role and terminal reason under
  the existing native session/request lock. Preserve caller transactions.
- Preserve RECHAT_H, RECHAT_P, RECHAT_MODE, RECHAT_ALLOW_ACTIONS, private-mode
  suppression, creature/eligibility exclusions, closing prompts and configured
  narrator interjections. Do not add configuration or silently disable modes.
  Exhaustion/no-candidate/private outcomes remain terminal; no temp-file reset.
- Rank captured canonical participants, not deduplicated names or live/latest
  nearby rows. Tight requires a proven addressed NPC; conversational prefers
  that partner; group rotates captured eligible actors; random resolves once
  per owned chain. Same-name actors remain distinct.
- Select and persist before profile loading. Extend the shared response-actor
  resolver with this exact decision, then let profile creation/read/write,
  connector selection, speech and action routing use it. Do not grant ownership
  through SYNTH_NAME/SYNTH_CORE_CURRENT_NPC_DATA or overwrite the original
  immutable context. Narrator routing keeps its explicit role.
- Bypass native execution of the legacy rechat selector and temp-file budget
  block, while keeping legacy behavior available for its own protocol lane.

### Prompt/history consistency

- Ordinary history/recall uses the selected canonical responder's saved
  visibility. Explicit profile/diary/relationship workers keep their own scope.
- The parent utterance and accepted earlier replies in this exact continuation
  chain must be available to the prompt as immutable turn evidence. The saved
  history frontier must not be moved to expose newer unrelated events.
- Audit relationship response capture, current-turn memory and prompt actor
  state selectors for assumptions that the responder is always context.target.
  Changing only profile/speech ownership would leave these paths inconsistent.

## Implementation and acceptance order

1. Mirrored parent contract, encoder/pending client state and narrow existing
   protocol/client tests; keep advertising gated until server integration.
2. Additive transactional schema plus independent emitted-route capture and
   exact-parent admission/selection/replay. Real SQL tests must exercise
   same-name actors, different saves, retries, duplicate children, old prefixes,
   missing/forged parent IDs and cancellation; retain rollback evidence.
3. Integrate early native dispatcher, selected profile/voice/history readers,
   chain prompt evidence and all existing rechat behavior controls. Test actual
   main entry through a bounded synthetic connector, not just helper returns.
4. Existing PHP/native suites, both flat/VR builds and package manifests,
   mirrored protocol, release audit; populated synthetic migration/concurrency;
   configured provider/text/TTS integration and safe local server/client deploy.
5. User-run flat save-load/message/NPC-to-NPC/interrupt tests; separate VR
   headset acceptance. Never launch or control the game on the user's behalf.

### Queue-parity diagnostic checkpoint (2026-09-13)

The `codex/chim-queue-parity` follow-up retains canonical selection, immutable
chain mode/profile policy, one-child reservations, action ownership and replay.
Terminal selections now return a closed reason through the existing status detail:
`no_candidate`, `private`, `budget_exhausted`, or `ineligible`. The status code
remains `rechat_complete`; older clients and saved responses remain compatible.
No schema, migration, provider call, profile default or selection policy changes.
The last inspected live chain was tight/no_candidate, not a failed HTTP request.

The paired SYNTH client warms one child's first WAV but keeps complete-response,
parent-delivery and fresh-scene gates. Its manual acceptance matrix is in
SYNTH `docs/CONVERSATION-PARITY-GOAL.md`. No voiced multi-NPC continuation or
audible interruption has been newly proven in game. Unit test counts are not that proof.

The parent contract and durable routing/decision work must land as a coherent
source milestone before enabling the native path. Do not call this complete
from the diagnostic or replace it with a name-key patch.
