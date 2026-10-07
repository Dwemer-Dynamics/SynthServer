# Native relationship migration

Required M2/M6 work under the approved SYNTH goal. This is the next architectural
implementation slice, not a new goal or permission to modify other products.

## Verified starting point, 2026-09-05

- `main_synth_pipeline.php` loads relationship context before the model and relationship
  postprocessing after the complete model turn. Its request-active check does not make
  the legacy relationship read or subsequent write atomic with cancellation.
- `RelationshipManager::getRelationships` selects `core_npc_master` by display name.
  `parseChanges` rewrites the profile's `extended_data.relationships` by that row's ID.
  Neither operation uses canonical actor identity or the saved history frontier.
- The dedicated path in `ext/relationship_system/postrequest.php` selects the latest
  speech listener and NPC IDs by names before queueing. Its worker needs a coordinated
  migration; replacing only the inline parser would leave the dedicated path unsafe.
- Connection-local PostgreSQL probe `relationship-isolation-audit.php` in
  `<scratch>` confirms the actual relationship reader returns
  the same synthetic row for two nonexistent native contexts and an unclaimed request.
  The earlier context also observes a later profile mutation. These are reproduced
  missing guards, not gameplay evidence or passing acceptance tests. All fixture writes
  use a temporary table and roll back in the isolated synthetic database.
- Frozen DialecticServer `907eb634fc49b47abe3490ea8229977ad0b0f6be` retains enabled,
  dedicated-connector and inline-completed-response modes in relationship_runtime.php.
  SYNTH must preserve those behaviors while replacing the unsafe storage/ownership path.
- Parser checkpoint `d3dff2a5b72f934c481d9fd3a36283720fc89279` canonicalizes player aliases
  before deltas/types. It fixes split player updates, not any isolation issue above.

## Required implementation boundaries

Storage groundwork is now implemented in `lib/synth_relationship.php` at
`53da8cb89999557bf2a92bc62857959fbe5dca68` (schema 20260905006). This is not a native
prompt/worker cutover: legacy relationship hooks still have the documented isolation gap.
The internal reader/publisher uses canonical directed pairs, a distinct insertion-history
publication ID, visible raw-event dependencies and prior relationship dependencies. It
rejects stale pair heads and conflicting same-request replays under bounded database locks.
Unknown state is explicit. Catalog types and already-saved received/emitted dialogue inputs
are supported by this groundwork; no public endpoint or provider call was added.

Processed response capture is now implemented and locally deployed at
`88e37e6fe0131921236a4dba7c57aa67558a281e` (schema 20260905007).
The model speech call sites opt into bounded capture before subtitle/TTS cleanup. Player
playback and canned errors do not opt in. Provider attempts reset capture; rejected,
interrupted, oversized, invalid-encoding and owner-changed attempts cannot be accepted.
Relationship directives are retained in evidence and removed from presentation, including
punctuation inside target names and the supported player-name placeholder. This evidence is
processed speech, not raw provider JSON, all model inputs/actions, or proof of client hearing.

Completion stores an immutable response input and distinct derived history marker only
after the exact request body is durably complete and canonical actor, saved context, lane,
generation and active claim agree. Replay cannot duplicate it. Optional storage runs only
with an idle caller connection: failure rolls back its marker/payload without undoing the
completed dialogue, and does not poison or commit a caller transaction. An unresolved speaker
refuses the whole capture. There is no relationship state change or job dispatch yet.

Owned input admission and the internal inline evaluator are now implemented and deployed at
`55fc73adebdcab18d150b12418cf634afe98ec70` (schema 20260905008). Response payload v2 records
the verified canonical ingress event/hash and the exact processed player/STT input insertion.
Missing, duplicate or aborted player inputs refuse capture. The response history marker
fingerprints its body and payload, and relationship_response_sources freezes input edges.
There is no guessed migration of older v1 capture into this stronger evidence format.

Only proposal admission can use the exact current request's accepted response beyond its
saved frontier. Ordinary readers still require the response and relationship publication
inside saved ancestry. Missing edges, altered input/response payloads, changed canonical
origin or response body invalidate dependent state. This does not make arbitrary current
events, other requests, nearby actors or future branches visible.

synth_relationship_apply_inline_response parses only canonical target-speaker entries,
groups player aliases by identity, preserves sequential bounds and applies all subjects in
one transaction. Opt-out/dedicated mode does no inline work. Replay is idempotent; an injected
second-subject failure rolls back the first. Existing actor-owned saved custom types remain
selectable; invented types do not. Invalid pre-frontier state no longer permanently blocks
fresh valid updates, while concurrent post-frontier publications remain stale.

This evaluator is not yet called by game-facing hooks. Next: coordinated native prompt,
inline and durable dedicated-worker integration, relationship lock authority and native
management, with frozen dedicated model inputs and leases. No native custom-type management
or lock migration is claimed from the ability to reuse an existing journal type. A captured
request/transcript/processed response is not the full dedicated evaluator prompt or every
input to the conversation model. Do not connect production callers until these remaining
ownership/control paths are validated.

Dedicated input/lease groundwork is deployed at
`e31c5a570f22bd140f08c50e71d6174a04c5d347` (schema 20260905009).
`lib/synth_relationship_jobs.php` freezes the supplied selected system prompt, connector
ID, verified owner, exact context, subject prior states, accepted response and up to ten
visible recent events. The payload is fingerprinted and raw-source edges are explicit.
Claims recheck source membership/content, response integrity, context and every prior
state. Capture replay keeps the original input, even if the caller supplies a newer prompt.

Native relationship jobs are separate from legacy and memory jobs. Queue capacity is two
active jobs per actor; attempts are capped at three, with ten-second retry delay, two-minute
leases and ten-minute job deadlines. The request-active guard recognizes a distinct
relationship lease token. Two real processes prove one claim winner; expired/replaced
tokens cannot alter the replacement. Caller transactions and failure rollback are preserved.

Result checkpoint b84c34a5f626169564507ebd747035708297bfe0 (schema 20260905010)
adds strict frozen-subject JSON parsing and an idle-connection provider callback boundary.
Result, all prior dependencies, changed pairs and the ready transition publish atomically
under the current lease. No-change is durably recorded. Ready replay is idempotent;
conflicting output and completed requeue are refused. Saved results suppress later-owner
capture, while independently restored pre/post-result sessions retain exact visibility.
Results and revisions share a dependency graph: invalidating any frozen subject's prior
also invalidates derived results even when that subject was not changed. Actual SQL
proves multi-pair rollback/retry, cancellation/lease fencing, fingerprint/dependency
checks and saved ancestry. Selected PHPUnit: 155 tests / 1146 assertions. Server-only
deployment, six source comparisons, HTTP 200 and schema verification pass.

Configured worker checkpoint 88c3ec952a0e8ba246f41d6a494cc74787d5077b now restores
the persisted canonical origin and executes the frozen prompt/selected connector ID
through five existing HTTP drivers. Current authentication/provider row settings are
read at execution, not copied into immutable history. Actual loopback adapter tests
prove HTTP/malformed output retries, slow-request lease cancellation and replacement,
replay refusal, no first-connector fallback and caller/global restoration. Memory uses
the shared reversible binding and its full regression probe still passes. Selected
PHPUnit: 156 tests / 1152 assertions. Installed CLI rejects HTTP (404); an absent job
run as www-data returns unavailable. Production evaluations/results/jobs remain empty.

Dispatch checkpoint 16b1699b90bb1eaecb25c787bf1b55e4a2dad8db (schema 20260905011)
adds completed-owner discovery, two-second session reservations, original-owner frozen
recovery, due retries and explicit-only manager registration. Response mode is frozen
at capture, so inline and old unclassified responses cannot become dedicated backlog.
Invalid frozen/queued work retires for that owner without starving later responses.
SQL probes prove competing dispatcher processes and recovery after a killed provider
process. Installed task-runner child exit zero was observed with an empty live queue;
HTTP dispatcher access is 404, health 200 and eight deployed files match source.
Selected PHPUnit: 157 tests / 1156 assertions. The full goal ledger records exact evidence.

The task remains explicit-only; normal scheduled manager runs skip it. Enable normal
scheduling only with the coordinated native hook/control cutover. Existing
memory selectors are type-specific; relationship execution requires its own evaluation
ID and excludes memory batches. Lifecycle cancellation intentionally applies to all
owned job kinds.

Policy checkpoint 6b74e820f107070d3f48fc1c26ae9a7d0e66c156 (schema 20260905012)
adds versioned dedicated notes/best/worst/role and neutral-only type evolution. Frozen
new evaluations use policy.v2/result.v2; existing inputs retain legacy result parsing.
Raw proposed deltas determine moment significance even when affinity clamps. Equal
significance replaces the older moment, and an existing role is retained. Native policy
recognizes the baseline's inferred wary type without altering the legacy catalog.
Journal metadata is saved at its own publication frontier and inherited by inline
updates; frozen priors include all details. Actual SQL proves saturation, metadata
inheritance, dependency integrity, old-policy replay and restored note visibility.
Selected PHPUnit: 161 tests / 1192 assertions; deployed source/schema/HTTP checks pass.

Actor-lock groundwork is deployed at `7a7c217ac09a29157be55bbda4f389d67d77d9e0`
(schema 20260905013). `native_relationship_control.php` resolves canonical actors from
exact native scopes, uses optimistic revisions and audits every policy change. This
current actor/playthrough policy is not rewound with a save and never removes accepted
history. A shared actor advisory gate serializes policy changes with native publication
and scheduling. Canonical request sequence barriers reject pre-change work after unlock,
including in-flight and backlogged responses; pending/running jobs lose their leases.
Sequence compatibility is checked before reserving the administrative barrier.

`ui/api/native_relationship_control.php` GET accepts session_id, generation and
context_sequence and returns status/revision plus a session-bound CSRF token. JSON POST
adds locked (boolean), expected_revision and csrf_token. The endpoint follows the existing
local management trust boundary and does not impersonate a native game request. Unknown
fields/actor IDs, stale revisions, unavailable scopes and invalid tokens are rejected.
Synthetic SQL, two real administrative writers, forced audit rollback and actual endpoint
HTTP tests pass. An independent lock interrupts actual slow provider HTTP within one
second without publishing. PHPUnit: 162 tests / 1200 assertions. Source/schema/health
and installed Apache-user table access are verified; live controls remain empty.

Native scheduled activation, management screen/manual pair edits and both NPC-to-NPC
directions remain open. Existing legacy locks are not guessed into native identity; their
explicit migration policy belongs in the management cutover. The coordinated native
prompt/inline/dedicated cutover remains required, and legacy game-facing hooks remain
name-keyed and outside this native control. No external live-provider or game proof is claimed.

1. Resolve directed source/subject pairs from canonical actor identities in the exact
   saved context. Player aliases are input conveniences, never persisted identity keys.
   Reject ambiguous same-name subjects and arbitrary global-registry/name fallbacks.
   Preserve renamed actors and different actors sharing one template.
2. Add an immutable native relationship journal with playthrough, actor pair, originating
   session/generation/request/context, frozen input and prior-state dependencies. Give
   publication a save-visible ordering marker: attaching a late result to an older
   dialogue event alone would let an older save acquire a future relationship change.
   Reuse the existing insertion-frontier machinery where its semantics fit; verify the
   snapshot capture and restore boundary before selecting the final schema.
3. Read only dependency-valid state reachable from the exact saved timeline. Missing
   provenance must be reported as unknown, not inherited from the mutable legacy profile
   and not fabricated as an observed neutral relationship. Do not assign legacy rows to
   native actors by guessing. Keep legacy settings/data intact for legacy/admin callers.
4. Preserve the inline and dedicated-connector modes. Both use bounded parsed proposals
   and the same fenced publication path. Inline publication needs the complete accepted
   output, once; verify where tags are stripped and where emitted/aborted delivery is
   recorded. Dedicated jobs freeze all model inputs, including prior relationship state,
   and run outside database locks. Reuse durable lease/cancellation patterns without
   treating memory-specific tables or IDs as interchangeable relationship storage.
5. Serialize per-pair state transitions, reject stale parent-state writes, deduplicate
   completion and preserve prior immutable evidence. A retry must not apply a delta twice.
   Reader visibility, model evaluation and physical hearing are different facts.
6. Preserve master opt-out, dedicated connector selection, relationship locking, bounded
   affinity, allowed/custom type policy, NPC-to-player and NPC-to-NPC directionality.
   A profile lock must not silently act as a relationship lock. Manual controls need
   native authority and audit rather than direct mutation of the legacy name map.
7. Cut over prompt reads, inline writes and dedicated queue/worker together for each
   supported native mode. Do not call the milestone complete by disabling relationships,
   leaving a permanent neutral fallback or silently falling back to legacy name storage.

## Acceptance before native cutover

### NPC-to-NPC participant evidence checkpoint

At `2a26188277131180446900b754214b5f81f5d7bf`, actual returnLines captures the final
per-line listener after strict/rechat selection and speech buffering. Attempt/line tokens
prevent later or fallback routing contamination. Internal response.v3 freezes canonical
listener and utterance_id or explicit unavailable routing. V2 remains unmodified evidence
with unknown routing. Dedicated input/prompt now preserves intended addressing without
claiming physical hearing. Real SQL and returnLines fixtures plus 164 PHPUnit tests pass;
server-only deployment/source comparison/HTTP health are verified.

This is not bidirectional publication. The frozen reference's evaluateNpcToNpcContext
evaluates two independent perspectives. Native implementation still needs exact participant
saved-state reads, frozen pair priors, selected rel_llm_npc_to_npc prompt, versioned bounded
speaker/listener parsing, both actors' control gates and atomic dependency-valid publication.
Do not implement this by swapping the request's target/global owner, reading another
actor's global profile or granting every nearby actor access. Relationship-specific
participant authority must not widen ordinary memory/history reads. A speaker's score
must never be mirrored into the listener's score. Unknown listener routes cannot become
reverse-perspective authority merely because an actor was nearby.

### Required acceptance

Linked legacy control checkpoint `593281c`: native control GET previews only a profile
already linked by actor_identity_id. Confirmed POST operation=import_legacy pins that
legacy_profile_id, its current boolean locked value and expected_revision=0. The existing
CSRF, exact scope, transaction, barrier and cancellation checks apply. Audit records retain
the source profile ID; legacy records are unchanged. Existing native decisions reject
imports, including a first explicit unlocked decision. Unlinked or ambiguously encoded
legacy controls require explicit management resolution, never name-based guessing.
SQL and HTTP probes plus PHPUnit 173/1287 pass; schema 20260905018 is locally deployed.
No live controls were imported. Management presentation/manual correction and coherent
gameplay activation remain open; this API addition is not automatic legacy migration.

Native prompt/explicit dispatch checkpoint `1ac1255`: synth_relationship_prompt_context
reads canonical contextual subjects through one saved relationship graph query, restores
display globals and distinguishes unknown from observed neutral. Existing formatter modes
and saved notes are retained without profile reads or raw response/history leakage. SQL
checks cover wrong globals/target, missing frontier, ambiguous names and invalid dependencies.
The explicit CLI now runs separated player-only and NPC-pair dispatch lanes; the manager
remains explicit_only. PHPUnit 172/1280 and local server deployment are verified.

The gameplay context_pre/postrequest/completion hooks are not switched yet. Replace the
legacy worker-start/profile paths coherently with native prompt/completion work, account
for existing legacy locks through explicit native management policy, then enable scheduled
activation. This is an installed internal boundary, not in-game prompt acceptance.

Player-only contract checkpoint `8ccede9`: explicit capture with playerOnly=true creates
synth.relationship.evaluation.v2 using only the canonical Player subject and selected
player-addressed lines. The provider projection excludes NPC-addressed lines and fresh
recent-history bundles; the immutable full response remains an integrity source. Pending
and saved-result checks reconstruct selection and reject widened subjects even after
rehashing. V1 frozen inputs retain their meaning. Both publication orders with paired work
and later saved independent player/forward/reverse states pass real SQL; PHPUnit 171/1274.
Locally deployed with schema unchanged at 20260905017. No user setting or wire change.

The current single dispatcher still defaults to v1 capture and paired automatic scheduling
is disconnected. Next: switch the coordinated native consumers to the separated contracts,
including prompt reads, inline/dedicated writers, scheduler, and management controls.
Do not describe this internal contract milestone as automatic gameplay parity.

Paired dispatch checkpoint `243104e`: canonical completed-response/listener discovery,
original-owner frozen-input recovery, independent reservation cooldowns and shared actor
capacity are locally deployed (schema 20260905017). Repeated addressed lines form one
evaluation per listener; player lines stay out. A locked speaker still permits an unlocked
listener. SQL two-process reservation, actual killed synthetic worker recovery, retry,
saved backlog/deduplication, invalid-input liveness and terminal deadline checks pass;
PHPUnit 170/1267. Provider execution is outside reservation SQL.

The dispatcher is not yet connected to automatic gameplay scheduling. Next: separate
player-only evaluation subjects/publication for mixed-address responses and coordinated
native prompt/inline/dedicated/scheduler/management cutover. Do not activate overlapping
single-owner and paired evaluations. Earlier checkpoint remaining-work notes below are
historical; full native gameplay parity and flat/VR acceptance remain unproven.

Saved paired-reader checkpoint `26132ce`: paired revisions are now admitted through
explicit cross-owner dependency validation at the exact saved ancestry/cutoff. Missing
halves and broken paired/single/paired dependencies invalidate the whole affected result.
Ordinary history and final relationship output remain reader-scoped. SQL saved-fork,
malformed-record, no-change, absent-listener and raw-history isolation checks pass;
PHPUnit 169/1263 and local server deployment are verified. The exclusion described in
the older publication checkpoint below is historical. Paired discovery/recovery/dispatch
and coordinated native gameplay/management cutover are still required.

Paired execution/publication checkpoint `d423b9b`: persisted original-owner providers,
atomic two-perspective revisions/result/prior edges and final-token fencing are locally
deployed (schema 20260905016). Forced second-half and late-lease rollback, duplicate/replay
checks, five actual loopback HTTP drivers and listener-lock interruption pass; PHPUnit
168/1259. Paired revisions are deliberately excluded from the old single-owner reader.
Cross-owner transitive dependency visibility and saved-fork acceptance are the next gate,
followed by paired dispatcher recovery and coordinated cutover. Stored paired rows alone
are not proof that a later prompt consumes either score correctly.

Paired queue checkpoint `13f9f6e`: explicit paired jobs now share two slots per actor
with single-speaker jobs, including reverse/listener participation. Exact-input claims
have bounded deadlines, retries and fencing tokens; either actor's control cancels pending
and running work. Real SQL/two-process checks and existing worker regressions pass.
Schema 20260905015 is locally deployed. Paired publication/dependency visibility, actual
provider execution and dispatcher recovery remain required before native cutover.

Frozen-input/model checkpoint `67232d5`: independent response/listener inputs now freeze
both saved priors/dependency edges, selected paired prompt/connector, addressed lines and
sorted actor controls. Revalidation fences pending work; policy.v2 parses speaker/listener
projections independently and filters locked sides. PHPUnit 166/1240 and isolated SQL
checks pass; schema 20260905014 is locally deployed. No paired provider execution, queue,
accepted dual-owner publication or game-hook cutover exists yet. Earlier checkpoint
descriptions below are historical, not remaining-work inventories.

Read-layer checkpoint `50c63f52ce83d0631845b060cdeb7cb696472654`: the internal
interaction facade verifies a routed accepted response and resolves its canonical NPC
pair before reading either perspective. An explicit contextual-NPC identity option in
the low-level history builder retains the same ancestry/cutoff/source rules; ordinary
memory/history calls remain target-scoped. The facade returns only the two directed
relationship states and IDs, not raw listener history, and never replaces request globals
or the canonical event. It is not yet connected to the evaluator/game hooks.

Real SQL proves independent +11/-7 scores (including a dedicated result's wary/Distrust
metadata), exact prior IDs, pre-publication exclusion, dependency failure, canonical
same-name renames, unavailable participants and independent before/after save forks.
Selected PHPUnit: 165 tests / 1224 assertions; memory/history regressions and local
server-only deployment/source/health checks pass. Frozen bidirectional inputs, model
execution and dual-owner publication/control fencing remain required.

- Two same-name actors, two playthroughs and two runtime lanes cannot share owned state.
- Rename retains the same actor; an unknown/ambiguous subject remains unavailable.
- Later changes are excluded from an older saved frontier, including provider completion
  after snapshot capture; exact saved ancestry restores only reachable prior state.
- Missing context/frontier, unclaimed request, wrong actor, cancellation and stale lease
  refuse publication and cannot expose mutable global state.
- Independent concurrent processes prove no duplicate delta, stale-parent overwrite,
  partial state/audit commit or cross-pair lock dependency. Provider waits hold no SQL lock.
- Inline and dedicated modes both update a later turn's actual prompt using synthetic
  end-to-end evidence. NPC-to-NPC directionality, locks and opt-outs retain their behavior.
- Fresh/upgrade/repeated migrations preserve legacy profiles and do not invent provenance.
- Local deployment, provider evidence and manual flat/VR acceptance remain separate gates.

Embedding model/revision provenance still requires separately authorized MiniMe service
work. It does not block the relationship migration in these two authorized repositories.
Other milestones in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) remain in scope and unfinished.

### Management UI checkpoint (2026-09-05, server 49f6515)

The Native relationships page now exposes exact snapshot-scoped native controls and
one-time linked legacy lock import. It distinguishes default/unrecorded, explicitly
unlocked, locked, unlinked and unreadable states. Each mutation requires confirmation,
the previewed revision, same-session CSRF and exact canonical scope. Unknown outcomes
are never retried; a failed authoritative reload cannot claim fresh state.

Claude Opus 5 implementation plus Codex diff/browser review, isolated Chrome desktop/
390px checks and actual API/disposable SQL tests pass. Local deployment is byte-verified;
live controls/audits remain zero. This UI neither activates gameplay hooks nor edits
affinity/history. Native manual pair correction and coherent runtime cutover remain.

### Manual correction backend (2026-09-05, server 75a7524)

Exact canonical directed-pair replacement is now available through
ui/api/native_relationship_correction.php, with its own same-session CSRF token.
GET requires session_id/generation/context_sequence and returns canonical subjects with
saved relationship or null, unsaved_change, control_revision and locked.
POST additionally requires subject_identity_id, expected_revision (0 for unknown),
expected_control_revision and a complete bounded state object (aff, type, optional
note/best/worst/relation/best_delta/worst_delta). Successful writes return a new revision
and availability=awaiting_new_snapshot. Never silently retry a submitted correction.
Explicit edits work while automatic updates are locked and do not unlock the actor.

The requestless relationship_manual history marker is administrative provenance, not
dialogue or model output. Schema 20260905019 narrowly permits that marker without a game
request. A correction is an independent full replacement with its old revision audited,
not a computed delta depending on that old state. It becomes visible only in a newer saved
frontier. Later model results use it as their saved prior; the reverse pair is unchanged.
Atomic publication/cancellation, two-process conflicts, restored forks, in-flight worker
fencing, subsequent inline inheritance and actual HTTP/SQL tests pass. Locally deployed,
with no live corrections. Editor UI, remove/reset semantics and coordinated runtime
cutover are still required; this endpoint alone is not full management/gameplay parity.

### Reset-to-unknown integration requirements (2026-09-05 audit)

Frozen DialecticServer's relationship_editor.php removes a target from the posted map;
npc_save_handler.php replaces the normalized map. Native removal must therefore represent
unknown state, not a recorded zero/neutral correction or deletion of historical rows.
The current correction API intentionally rejects a null state and has no reset operation.

Required coordinated work before exposing reset:

- Record an explicit actor/pair-owned reset marker after the selected saved frontier.
  Retain its revision even when the semantic relationship is unknown. Do not resurrect
  an older pair revision by simply filtering the newest row out of the current reader.
- Carry that unknown-state revision through inline admission, frozen player evaluations,
  both directions of paired inputs/results and their dependency graph. Existing null
  relationship fields currently mean no prior and cannot alone preserve reset provenance.
  Read sites include synth_relationship.php, synth_relationship_interaction.php and
  synth_relationship_prompt.php; frozen/result validation is in the corresponding jobs,
  interaction jobs, result and result-state modules.
- Keep current administrative locks separate from the saved reset. A confirmed reset must
  cancel/fence pre-reset work without silently unlocking automatic changes. Explicit
  replacement, later model delta and no-change results must preserve correct dependencies.
- Prove unknown versus observed zero in prompt and editor; pre/post-reset restored saves;
  no older-state resurrection; invalid/reset-parent propagation; independent reverse pair;
  conflicting reset/edit races; late worker refusal; and later inline/player/paired updates.

This is unfinished implementation work under the existing goal, not an exclusion from
parity. The correction editor must not offer a reset button until this contract is ready.

Correction editor checkpoint `ff69d7c` is locally deployed. The native page now exposes
complete directed state replacement, details and custom types through the reviewed API.
Claude Opus 5 implementation plus Codex/browser review preserves exact IDs, explicit
unknown inputs, single confirmed submissions, independent CSRF tokens and saved-frontier
visibility. Failed rereads retain drafts without transferring them to another subject.
Actual API/disposable SQL and desktop/narrow browser checks pass; no live correction.
Reset-to-unknown and coherent gameplay activation remain unimplemented requirements.

### Reset-to-unknown implementation (2026-09-05, 431ee4c)

The preceding reset requirements are implemented in the administrative path. Explicit
`state:null` publishes manual.v2 provenance and a retained revision with null affinity
and type, never a zero/neutral substitution. Schema 20260905020 narrowly permits this
shape. Known replacements remain manual.v1; omitted state is rejected. GET exposes
`{revision:string,aff:null,type:null}` for a saved reset and null only for no available
revision. Model inputs retain reset revisions as dependencies; ordinary and paired
state publications still require known affinity/type. No-change preserves unknown.

Disposable SQL proves restored pre/post-reset forks, inline/single/player-only/paired
updates, no-change dependency retention, corrupt reset descendant rejection, reverse
independence, reset/edit concurrency and late paired worker cancellation. Actual HTTP
and desktop/narrow browser checks pass. The reset UI is separate from form-only restore
and allows explicit administration while automatic changes are locked. No live reset.

Compatibility: worker input schema labels retain their existing single/player/paired
versions, with nullable reset prior values understood by this coordinated server build.
This is internal server state; the client wire protocol is unchanged. Do not roll back
to a pre-reset reader after recording resets: it cannot preserve this unknown boundary
and may expose pre-reset values. A rollback would require an explicit state-preserving
migration; dropping the marker or coercing it to zero is not a valid rollback.

Coherent gameplay hooks and worker scheduling remain unfinished. This checkpoint is
administrative/worker readiness, not in-game relationship activation or full parity.

### Coordinated runtime integration (2026-09-05, bda8c09 / f8e45c9)

Native context_pre now reads the saved canonical graph, without legacy profile reads
or legacy daemon launch. Native postrequest returns before name/speech/profile lookup.
The real streaming and buffered response finalizers perform bounded inline publication
after durable protocol completion. Dedicated evaluation is scheduled in the service
manager; captured modes cannot be reinterpreted after a mode switch. Legacy requests
retain their existing path. No model work runs inside the native prompt hook.

The inherited background port collided with Dialectic. SYNTH now owns loopback 12348
and start.sh uses the same existing environment/default resolver as its health probe.
Installed process ancestry and manager logs confirm a separate SYNTH service, with
Dialectic left untouched. Initial child-task failures were followed by successful
entrypoint/manager cycles; their transient cause is not yet established.

Real hook SQL and fresh production CLI/loopback provider tests pass. The installed
openrouterjson worker also produced one ready player-only result and corresponding
state revision from existing accepted native evidence. This proves server execution,
not player-visible behavior. Manual flat/VR and sustained reliability gates remain.
