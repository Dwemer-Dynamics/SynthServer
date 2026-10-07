# Native middle-term narrative memory migration

Status: native storage, worker and both prompt readers implemented; synthetic
PostgreSQL, connector HTTP, full-main response, saved branches and process-death
recovery exercised in flat/VR lanes. Read-only provenance inspection and 110-source
backlog/rebuild coverage are verified. Saved narrative edit backend and CSRF API are
implemented, including saved archive/restore and rebuilt-source-family reservation.
Confirmed editor/archive/restore UI is browser-verified. Exact-target regeneration
preflight is SQL/HTTP-verified. Internal durable approval and saved eligibility are
implemented. Fresh-owner worker execution and atomic saved replacements are now
SQL/full-main-HTTP verified. Public confirmed regeneration and status/navigation are
now SQL/public-HTTP/browser verified. Deployed locally at product9c26273 with
schema20260907012 on2026-09-07. Actual replacement-process death/restart and
multi-page obsolete-approval discovery pass773 checks per flat/VR lane.
Remaining recovery/ownership
variants, sustained stability and manual game acceptance remain open.
This remains a required part of the active full parity goal, not a deliberate exclusion.

## Public confirmation and visibility contract (2026-09-07)

The existing native_middleterm_control endpoint accepts action=regenerate with exact
session/generation/snapshot/actor/narrative, expected_revision, expected_plan_sha256,
32-hex approval_token and its existing CSRF token. It records intent only, never a
game request or synchronous provider call. Same-token replay returns the original
receipt. GET exposes regeneration progress separately from regeneration_preview
eligibility and replacement_id in the selected saved scope. Unsaved publication
metadata cannot expose future narrative text or a navigation target. Original results
remain immutable; their replacement targets cannot be edited/archived/restored again.
Active third attempts report running until execution ends or ownership/lease expires.
Publication-recorded status checks normalized result linkage and integrity; saved
success additionally validates the existing owned-publication reader.

The UI displays exact owner/revision/source plan before confirmation, distinguishes
approval from completion, invalidates stale pre-POST status, and supports explicit
status refresh/current-replacement navigation. Unknown POST outcomes are not retried
automatically; unchanged reviewed intent retains its token for an explicit retry.
Changing snapshot/navigation discards unconfirmed drafts. Exact current evidence,
browser coverage and remaining acceptance are in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Native worker adoption and atomic saved replacement (2026-09-07)

Schema20260907012 adds middleterm_inputs.regeneration_approval_id and the immutable
middleterm_replacements relation. Each approval can publish once; each original target
can be replaced once per session/generation. last_considered_at and its discovery index
are scheduling metadata on approvals, not changes to their immutable payloads/hashes.
The exact-version rollback refuses any replacement or admitted regeneration input;
empty rollback returns to011 before earlier pre-approval migrations can be reversed.

Admission and execution:

- synth_middleterm_capture/enqueue may adopt a supplied approval or discover eligible
  approvals automatically. Discovery considers at most20, rotating checked timestamps
  to avoid starving later candidates behind invalid old plans. The existing NPC/profile
  opt-out, session/actor locks, pending-job exclusion and unacknowledged-result fence
  remain. A snapshot alone is insufficient: adoption additionally matches a completed
  immutable ingress event and its canonical hash for the current responding actor.
- Internal input schema synth.middleterm.input.v2 pins approval ID, selected target,
  reviewed plan hash, exact sources/prior and current connector fingerprint/settings.
  v1 ordinary inputs must have no regeneration field/reference. This is an internal
  input version, not a native wire protocol change. Normalized prior IDs are checked
  against frozen JSON before any provider attempt.
- Claim takes the actor lock and checks aggregate attempts across all inputs/jobs for
  that approval. There are at most three claimed attempts. New owner jobs inherit the
  earliest deadline, initially120 seconds; a stale-owner cancellation does not reset
  either budget. Invalid plans or provider failures reaching their bound stop retrying.
  Existing15-second attempt leases and bounded HTTP cancellation remain in use.
- Provider generation remains outside database transactions. Publication rechecks
  exact owner, current approval, source/prior revisions and attempt lease, then writes
  result/history, replacement edge and ready job state in one transaction. A rejected
  link cannot leave an orphan result or falsely ready job.

Saved reader semantics:

- Ordinary validated publications seed middleterm_owned. Each v2 replacement joins
  its validated approval and a previously owned target; strictly increasing saved IDs
  prevent cycles. Frozen replacement sources/prior must equal the approved plan.
- Replacement resolution follows the newest accepted edge visible in the selected
  saved ancestry. Original result text and old snapshots are unchanged. A later saved
  replacement suppresses the old record and its stale descendants. Their source-family
  reservation prevents ordinary automatic re-creation of superseded coverage.
- A stale descendant may be explicitly regenerated using its own original families
  and the resolved replacement of its own prior. It never uses an arbitrary latest
  narrative. Directly replaced originals report replaced rather than becoming new
  regeneration candidates. Archive/invalid-control ancestry still blocks regeneration.
- Separately scoped replacement fences prevent a corrupt replacement result/receipt
  from reviving already superseded text. Other valid unsuperseded ancestry can remain
  available. Invalid replacement evidence itself is not accepted as a narrative.

Current evidence:

- 146 real SQL checks in each flat/VR lane: fresh completed owner, normalized-prior
  corruption rejected before attempts, exact source/prior binding, duplicate pending
  exclusion, old text during work, atomic link/job completion, saved cutover, replaced
  prior resolution for a descendant and automatic approval discovery. Old snapshots
  and original result text remain unchanged.
- A replacement INSERT veto rolls back result/history and ready state. The existing
  text stays available; a bounded retry succeeds. Two stale-owner attempts followed
  by a third successful attempt retain one deadline and total count3. Three stale
  owners exhaust the budget, with no fourth attempt. An expired approval cannot gain
  a new deadline. An archive committed inside the provider callback blocks publication
  and leaves no new result; saved archive does not revive superseded descendants.
- Corrupting the newest replacement checksum leaves only valid unsuperseded ancestry,
  not a fallback to the previously replaced child. Populated schema012 rollback refuses
  deletion and preserves the accepted graph. Disposable clones are removed afterwards.
- Full-main mt-regen-flat-01 / mt-regen-vr-01:156 checks each. Original native narrative
  appears at snapshot7; internal approval is acknowledged by actual ingress snapshot8
  and a completed request. Ordinary enqueue discovers it. Actual connector HTTP sees
  the original five sources and null prior, then publishes the replacement. Snapshot9's
  actual model request contains the new marker, excludes the old/legacy markers, and
  returns ready synthetic speech. Public API approval is not claimed by this fixture.
- Existing correction131/archive160/ordinary VR238 checks pass. Ordinary process death
  and natural lease recovery are reverified; replacement-specific kill/restart variants
  and a many-obsolete-approval discovery stress gate remain to be exercised separately.
- An initial EXPLAIN ANALYZE showed79.179ms planning versus1.592ms execution, with no
  JIT. Materializing the saved-scope-only approval set reduced planning to13.339ms;
  final integrity-fenced cases measure18.847/20.192ms planning. Management ID/token
  lookups retain predicate pushdown. No global PostgreSQL tuning was performed.
- Final scale471 checks:110 source summaries/109 rebuilds;100-source preview96.19ms,
  final recall39.22ms and total17.63s. These are synthetic measurements, not gameplay
  or large user-history acceptance. No native rebuild was required.

Final suite: 289 tests / 4664 assertions. Release audit passes738 tracked files,
423 PHP,152 JSON,12 runtime directories,three catalog hashes and128 theme sources.
Reader and worker files match the full-main fixture. Diff checks pass; all owned
processes are terminal. Client ce8097d is clean and unchanged. No installed deployment.

Scratch evidence under `<scratch>` (SHA256):

- native-middleterm-replacement-probe.php: 17DBDF5DCF1437254AC03140BF01314051EC1120E0DFE74A1568CB6520D56650
- native-middleterm-probe.php: B0E5F78CC8F797270AB3B1C9994A2A2D8B55CA74770FEAA99042B87E131630E5
- native-middleterm-full-main.php: E28BD59E7F8D8CF4A061C87FA2D1C3AD8A8910C3A88E4DA1AD38EB50010D56D9

Next: expose the confirmed public Regenerate action, reviewed fingerprint/token and
truthful pending/succeeded/failed/replaced status with current-replacement navigation.
Do not conflate accepted publication with saved visibility or restored source validity.
The public preview still reports execution_available=false. Then finish remaining
recovery/ownership probes, deploy the paired local artifacts and request manual flat
and independent headset acceptance. The full parity goal is not complete.

## Immutable regeneration approval and saved eligibility (2026-09-07)

Schema 20260907011 adds middleterm_regeneration_approvals, version/bootstrap detection,
the derived middleterm_regeneration history type, and an exact-version rollback that
refuses any approval or orphan approval history. It does not alter the native wire
protocol, current control revisions or accepted narrative results. Apply rollback011
before earlier empty narrative rollbacks; populated journals require verified backups.

lib/synth_middleterm_approval.php is an internal backend contract, not yet called by
the public control endpoint. Confirmation accepts exact session/generation/snapshot,
actor/narrative IDs, expected control revision, expected_plan_sha256 and a 32-hex token.
It validates the current saved plan and appends its immutable receipt plus history in
one bounded management transaction. The token is unique within session/generation/actor;
same-scope replay returns the existing receipt even after later state changes, without
approving the new state. Changed-scope token reuse conflicts. No input/job/result is
created, current narrative hidden, source coverage released or request global bound.

The preflight now computes a PostgreSQL-canonical JSONB SHA256 over target/prior/sources
and family IDs. Control GET exposes this fingerprint with execution_available=false.
It is required because a changed prior/source can leave the target's own revision
unchanged. Receipt readers independently validate both outer receipt and inner plan
hashes plus normalized owner fields and derived history binding.

The saved eligibility reader requires a strictly later snapshot that acknowledges the
receipt in its original active session/generation/runtime/playthrough. A saved fork
cannot inherit permission to execute, even though it can read the original narrative;
an explicit new approval in that fork is allowed. Its local cutoff may be zero with
valid parent history, so provenance must not assume narrative ID <= local cutoff.
Newer confirmations supersede earlier ones immediately. NPC-over-profile generation
opt-out remains authoritative, matching frozen DialecticServer907eb634's middleterm
entrypoint. Changed evidence, target correction or archive blocks the plan. Ready here
is saved eligibility only: completed canonical owner binding and attempt admission are
still required, not inferred from a snapshot or this function's return value.

Verification:

- 120 checks in each synthetic flat/VR lane, including two independent PHP processes
  racing one confirmation. Exactly one new receipt and one replay result; repeated and
  changed-scope confirmations tested. Both child processes assert game-request decoder
  absence, exercising management dependencies separately from request-loaded tests.
- A database trigger vetoes receipt insertion after the statement begins. The bounded
  transaction leaves neither an approval nor orphan history/event row. The original
  narrative remains visible before and after accepted approval; no worker/input/result
  rows are added. A populated rollback fails and retains all three main-scope receipts.
- Wrong actor/runtime, new fork ownership, valid explicit fork reapproval, new-snapshot
  acknowledgement, NPC opt-out/re-enable, original token replay after an edit, changed
  prior with unchanged target revision, new reviewed correction approval, checksum
  corruption, newer-intent supersession and archive intent are covered.
- The inner-plan hash SQL initially hit ambiguous operator precedence; explicit JSONB
  grouping fixes it. Both real SQL/process lanes passed after correction.
- Updated preflight148, correction130, archive159 and backlog470 checks pass. Scale
  covers110 summaries and109 descendant rebuilds; control preview49.96ms, recall24.61ms,
  total17.09s. Synthetic timings are not game-thread or user-history performance proof.
- Actual control HTTP returns a stable64-hex plan hash and disables execution. A valid-
  CSRF POST action=regenerate returns400. Temporary HTTP PID2034285 was stopped. The
  persistent synthetic fixture was migrated to011; no installed runtime was migrated.
- Full-main mt-approval-schema-flat-01 passes126 ordinary-response checks through actual
  ingress, native narrative HTTP, saved later prompt and ready synthetic speech. Its
  servers/workers stopped. This baseline is not regeneration execution acceptance.

Final suite: 289 tests / 4662 assertions. Release audit: 737 tracked, 423 PHP,
152 JSON, 12 runtime directories, three catalog hashes and 128 theme sources.
Diff checks pass; all owned probe processes are terminal. Only the final purpose
comment changed after the HTTP fixture copy; that comment was refreshed and the
approval module now matches the fixture byte-for-byte. Client ce8097d is unchanged.

Scratch evidence under `<scratch>` (SHA256):

- native-middleterm-approval-probe.php: 722EE721336B335311AB55ED9F0978AFF1589D1EFE89C06DCEBFD8CA11C37DF3
- native-middleterm-approval-child.php: 83942984A479E061FC32DBB6F481AEE80BDB4945691330F9040B14134C238C59
- native-middleterm-probe.php: 2FF56F04FD7F7FCDA88056C25454E1AAFC9400A95FE4D31E0B603F77DC445209
- native-middleterm-backlog.php: 7AA5A12D03A8F0A978D805D703B920207370E4C0CE92B8D6FA6BBAB40609BB32

Next: bind a real completed fresh owner to an immutable replacement input; cap attempts
across stale-owner readmission; publish result/replacement/job terminal state atomically;
preserve old text until success and saved history afterwards; then expose confirmed
Regenerate and pending/succeeded/failed status. Eligibility is not a substitute for this
execution lifecycle. Deployment, game and separate headset acceptance remain open.

## Exact-target regeneration preflight (2026-09-07)

The pure saved-data planner in lib/synth_middleterm_regeneration.php runs inside its
caller's bounded transaction. It selects one owned narrative, its own effective
prior (not whichever narrative is latest), and only validated current replacements
of its originally pinned short-term source families. Frozen positions, IDs, original
revision text/hash and game timestamps must agree. No family can appear twice; no
source can be dropped to fit the whole-source bound. Target control/content revisions
and input hash are returned for later approval/adoption validation, not as approval.

Unavailable states distinguish control_unavailable, archived, ancestor_suppressed,
awaiting_snapshot, prior_unavailable, target_integrity_unavailable, sources_unavailable
and source_bound_exceeded. A ready plan contains the exact prior and source evidence.
The existing control GET returns only status, source count and prior ID under
regeneration_preview, always with execution_available=false. POST remains unchanged.
No provider, worker claim, input/result/job insertion, coverage release or fake game
request is performed. Generation policy and durable approval are not decided here.

Evidence from current source:

- 147 real PostgreSQL preflight checks: root five versus child ten sources, exact own
  prior, foreign-actor exclusion, no ingress globals or added jobs/results/inputs,
  pending-control fence, edited prior revision, old saved reader unchanged, archive
  and ancestor suppression, restore, stale short-term dependencies, fourteen valid
  replacement summaries, effective source revisions, unchanged original families,
  unrelated newer summaries excluded, and corrupt frozen source hash rejected.
- Existing correction/archive/ordinary VR suites: 129/158/236 checks, including real
  connector HTTP and worker death/lease recovery. Populated rollback refusal messages
  are expected checks, not failed migrations. Each disposable clone was removed.
- Expanded scale probe: 469 checks, 110-source coverage and 109 descendant rebuilds.
  The original 100-source regeneration preview stays exact even after rebuilt
  narratives are published. Control preview 49.01ms; final recall 24.29ms; total14.82s.
  These synthetic measurements do not prove large user histories or gameplay latency.
- Actual loopback HTTP GET: narrative2433/session rechat-fixture-mt-main-vr-02,
  generation1/snapshot7/actor2 is ready with five sources and no prior. Narrative2673
  in rechat-fixture-mt-archive-flat-01 is awaiting_snapshot at13 and archived at11.
  Wrong actor3 returns404. Responses explicitly disable execution and omit source text.
- A clean management bootstrap initially failed because the planner used a decoder
  declared only by game request ingress. Native JSON decoding fixes it. The isolated
  probe now asserts the ingress decoder is absent and succeeds (42.81ms). Existing
  request-loaded SQL tests alone would not have caught that dependency.

Final suite: 288 tests / 4650 assertions. Release audit passes 735 tracked files,
422 PHP, 152 JSON, 12 runtime directories, three catalog checksums and 128 theme
sources. Its unchanged 17-adapter inventory does not broaden five-provider TTS work.
Both changed product PHP files match the synthetic HTTP fixture byte-for-byte.
Temporary HTTP PID2016426 was stopped; every probe completed. No installed deployment.

Scratch evidence under `<scratch>` (SHA256):

- native-middleterm-regeneration-plan-probe.php: 5FA01E243C7B05CF2889A716DE03B9EA5105F091F1D3FC4E58A50D9CC438DAFF
- native-middleterm-probe.php: 61294722E2244058E808D5D88B6AD4A2AAAAF768FAD6C8E3E090A31C737DA4D6
- native-middleterm-backlog.php: 72F0CB225C111B69A82CE4D789463627441117C3A6152BE50CEFC9A121E6C9D8
- verify-narrative-regeneration-preview.php: AE64C9C4C028E78C681C604F0236ECB06DC5563A5FA3F7294AEFDBE2275F55BE

Next execution requirements remain mandatory: durable exact-revision approval;
fresh native owner adoption without duplicating work across save branches;
revalidation of the approved target/prior/source plan; bounded provider retry and
owner recovery; atomic result/replacement/job publication; immutable old saved text;
archive intent respected; and truthful pending/succeeded/failed controls. A source-
stale child cannot reuse its stale prior merely because its summaries were rebuilt.
No Regenerate button or execution endpoint is exposed by this preflight milestone.

## Confirmed management UI and uncertain write recovery (2026-09-07)

The page now offers Edit and Archive/Restore beside inspection. Each opens a separate
panel using fresh control API state and shows the exact saved identity and revision.
Only Confirm sends a POST. Edit validates nonblank, well-formed, NUL-free text up to
65536 UTF-8 bytes. Archived/unavailable edits and already pending controls cannot be
confirmed. A navigation read aborts the old read chain and discards unconfirmed intent;
confirmed POSTs disable the page controls, use a ten-second bound and never auto-retry.
A lost response does not mean the database write was cancelled. After any outcome,
confirmation stays disabled until control state is reloaded. Original provider text
and its hash are displayed separately from the effective saved text and its hash.

Browser proof used only synthetic narrative 2673 in rechat-fixture-mt-archive-flat-01:

- Snapshot 9: exact confirmation keys and focus, blank rejection, draft discarded
  on selecting archived snapshot 8, then confirmed edit accepted but original selected
  text unchanged. Reopening control reports awaiting a newer snapshot and disables it.
- Snapshot 10: correction appears; literal `<b>` markup creates no child elements and
  original provider text remains unchanged. Confirmed archive waits for another
  snapshot. Snapshot 11 is archived, Edit disabled, Restore available. Cancelling
  restores focus to the opening button; confirmed restore waits for snapshot 12.
- Snapshot 12: corrected text is restored. A fixture shutdown handler lets the real
  POST commit then replaces the response with synthetic 503. The page reports a lost
  response and warns not to assume cancellation, with Confirm disabled. Snapshot 13
  displays UI_LOST_RESPONSE_2673. SQL verifies exactly one matching receipt and the
  unchanged immutable original, not merely a plausible success message.
- While a snapshot-13 editor is open, a separate API client appends an archive. The
  editor's later stale confirmation is rejected and remains disabled. SQL verifies
  zero receipts with its UI_STALE_EDIT_MUST_NOT_COMMIT text. Pending archive evidence
  remains in the fixture only; there was no user state mutation.
- Desktop 1440px and narrow 390x844 screenshots reviewed. Narrow document width 390
  equals viewport width; controls wrap and exact scope metadata stays readable.
  Viewport override reset. Browser console error list empty. No new CSS framework or
  shared diary changes were needed; existing layout and confirmation patterns reused.

The browser initially could not open an existing PHP session cookie belonging to
another localhost service. Only the fixture router was changed to use the separate
SynthNarrativeFixture session name and its private 0700 directory; no other session
was accessed or chmodded. Fault injection was restored to false after its one write.
The fixture server was then stopped. Helpers advance copied synthetic snapshots for
UI boundary checks; they do not simulate a successful game/plugin acknowledgement.

Validation: 288 PHPUnit tests / 4647 assertions, node syntax, clean diff, and release
audit 734 tracked / 421 PHP / 152 JSON / 12 runtime directories / three catalog checksums /
128 theme sources. Both modified UI files compare byte-identical with the fixture.
The prior Claude UI authentication failure used the skill's no-retry Codex fallback;
no independent Claude review is claimed. No installed deployment or game test.

Scratch helpers under `<scratch>` (SHA256):

- native-middleterm-ui-router.php: 1E374F6A662ECEF77DDE3571B7B1CA25C3FEE881B3728E707A4C058C6014B1AB
- advance-narrative-editor-fixture.php: 698BC4A22820096805A49689F5E9121A4159ACA863B6E971A6866C660BB6F991
- verify-narrative-editor-fixture.php: 5AA121087AC324538C9EDFCF8FC91976AE80ADB734F444B1EE3C3C2AC6FA17F7

Next: explicit regeneration must record durable intent, release only approved source
coverage, bind a fresh native worker owner and make pending/completed/failed status
inspectable without running the provider inside a management transaction. It must
preserve old saved text and archive intent, prevent duplicate admission, and fence
stale/replaced requests. The editor is not a substitute for that lifecycle. Full
parity also still requires deployment, sustained reliability and manual flat/VR gates.

## Archive/restore without automatic recreation (2026-09-07)

Schema 20260907010 admits v2 archive/restore receipts while keeping existing v1 edits.
The management API now accepts archive or restore without a narrative text field.
expected_revision compares control_revision, not content_revision. Both are returned:
the former changes on any accepted control; the latter changes only on edits. A no-op
does not append a receipt. Existing optimistic, CSRF, exact-owner and new-snapshot
requirements remain, and an archive immediately fences an in-flight worker.

The reader derives middleterm_suppressed from archived/unavailable controls and their
accepted descendants. Suppressed narratives do not supply a prior narrative. Their
direct source-summary families remain covered for admission, including replacement
summary IDs from validated native memory rebuild families. This prevents archiving
from immediately regenerating identical evidence. It does not delete or archive the
short-term summaries themselves, or prevent independent short-term recall. Unrelated
new sources can meet the ordinary first-root threshold and form a new narrative.

Restore removes that suppression in its saved lineage. It preserves prior edits and
does not revive source-stale dependencies. A newer valid root can remain selected;
restoring an older record does not promise to make it the latest. Previously reserved
coverage becomes eligible again where absent from the current valid ancestry. Explicit
regeneration remains a separate required control, not implemented by calling restore.
The read-only inspector exposes archived, suppressed and control_revision, and its
labels distinguish direct archive from unavailable/archived lineage suppression.

Current verification:

- 158 real SQL archive checks: unsaved/saved readers, descendants, no-op, correction
  preservation, separate control/content revisions, stale revision rejection, four
  saved forks, fourteen source-family rebuilds, exact five-source unrelated new root,
  restoring stale sources without false recall, renewed coverage and provider-time
  archive fencing. The previous edit suite still passes 129 checks.
- Ordinary VR ownership/connector/process-death probe: 236 checks. Backlog probe:
  467 checks, 110 summaries and 109 descendant rebuilds; latest synthetic read 24.09ms,
  total 16.08s. These figures are not game-thread latency or gameplay acceptance.
- Full-main mt-archive-flat-01 / mt-archive-vr-01: 155/152 checks through actual ingress,
  saved archive then restore, model prompt serialization and ready synthetic speech.
  Narrative marker is absent after archive and present after restore; legacy marker
  stays absent. Counts differ with speech polling. Providers remain synthetic.
- Actual API on synthetic VR narrative 2732/snapshot 9: six checks for exact restored
  record, restore no-op, forbidden archive text, stale control_revision, valid archive
  receipt and unchanged old saved state while pending. That pending receipt remains
  in the synthetic database only; it must not be mistaken for user state.
- Browser: snapshot 8 renders Archived with inspectable text/focused detail heading;
  snapshot 9 renders Selected for recall and hides previous details. No console errors.
  Only labels/help changed, not editor buttons or confirmation flows. The earlier
  Claude authentication failure required the UI skill's no-retry Codex fallback.
- 288 PHPUnit tests / 4647 assertions; JavaScript syntax and release audit pass:
  734 tracked / 421 PHP / 152 JSON / 12 runtime directories / three catalog checksums /
  128 theme sources. Eight fixture files match source. Empty archive rollback and
  repeated schema application pass; populated archive rollback is refused. Disposable
  database clones and the exact HTTP fixture server were cleaned up.

Scratch evidence under `<scratch>` (SHA256):

- native-middleterm-archive-probe.php: 99DE165FEECA77B529964A41C822763A7A784979DE7354395433E3D24C1FAEEF
- native-middleterm-probe.php: 933B9F395CD819A74DBF1214920718B02797BB9D0AA1DBB05F72011AD4546524
- native-middleterm-save-branches.php: D43AE2B0BB0585B582AB33F8F5166C1D49E79CAF8444458DB7975EBA38BCA2F8
- native-middleterm-full-main.php: A7A61BA33C7CA3CFED564EF1E61D4D8B011C6835FC054848A383140830BF31EB

Run native-middleterm-probe.php archive for this bounded fixture. Earlier hashes and
test counts below remain historical. No installed migration, user data change, external
provider or game test was performed. Editor/confirmation and explicit regeneration,
local deployment, remaining ownership variants and manual flat/VR acceptance stay open.

## Immutable correction backend and real response proof (2026-09-07)

Migration 20260907009 adds middleterm_control_revisions and explicitly admits the
derived middleterm_control history type without forging a native request ID. Controls
append exact saved-scope receipts; accepted middleterm_results never change. The
reader verifies receipt/hash/history identity, actor, origin, saved frontier and prior
control ancestry. Invalid control evidence suppresses recall rather than restoring
old text. Each effective narrative has a content revision; unedited old inputs retain
implicit revision zero. Descendants match both effective prior text and revision.

native_middleterm_control.php provides edit-only backend controls. GET reads exact
session_id, generation, context_sequence, actor_identity_id and narrative_id. POST to
ui/api/native_middleterm_control.php additionally requires action=edit,
expected_revision, narrative and a CSRF token issued to that PHP session. Input is
strictly bounded, UTF-8, nonblank and NUL-free (65536 text bytes / 409600 JSON bytes).
Unknown fields, names, archive actions and invalid revisions are rejected. No-op
returns unchanged; accepted edits return awaiting_new_snapshot. Unsaved same-record
changes, unavailable dependencies or stale revisions return conflict. Session and
actor locks serialize controls with native publication; provider work stays outside.

Pending same-generation corrections fence new admissions and revalidation of an
already running worker, even before the correction enters the next snapshot. Later
admission uses corrected prior text/revision and exact uncovered source IDs. This is
dependency-driven regeneration, not the still-missing explicit regeneration control.
Inspection returns effective narrative/result_sha256 plus original_narrative,
original_result_sha256, content_revision and control_unavailable; original provenance
is not overwritten. The existing browser page still offers inspection only. Editor,
confirmation and correction-history presentation must follow in the UI milestone.

Verified with current sources:

- 288 tests / 4645 assertions in existing PHPUnit suites; 128 real SQL correction
  checks covering no-op, wrong actor, unsaved conflict, old snapshots/forks, edited
  ancestor invalidation, editing back, actual prompt recall, ten-source regeneration,
  mid-provider correction fencing, fresh readmission, original hashes, corrupted
  control fail-closed behavior and populated rollback refusal.
- 235 ordinary ownership/HTTP recovery checks per flat/VR lane. Empty correction
  rollback, original empty narrative rollback and schema idempotency pass. The
  original populated rollback check resets only the disposable schema-version marker
  to its exact required version, avoiding a misleading pass on version mismatch.
- 466 scale checks retain exact 100+10 source coverage after 109 descendant rebuilds;
  latest synthetic read 25.11 ms, total 16.77 seconds. Not game timing evidence.
- Corrected full-main mt-edit-flat-01 and mt-edit-vr-01 each pass 138 checks. Actual
  ingress accepts the next snapshot and later dialogue, actual model serialization
  contains only the corrected narrative marker, and synthetic speech reaches ready.
  The prior snapshot retains original text. Real model quality/gameplay remain untested.
- Twelve HTTP checks on the actual endpoint verify no-store, CSRF rejection, session
  isolation, JSON requirement, unknown field rejection, no-op, append, replay conflict
  and old saved text. Only the named synthetic mt-inspector-flat-01 record was edited;
  its pending receipt is retained as fixture evidence. No installed data was changed.
- Release audit: 733 tracked / 421 PHP / 152 JSON / 12 runtime directories / three
  catalog checksums / 128 theme sources. Source/fixture hashes match for all eight
  copied files. API fixture server stopped and disposable clone databases removed.

Initial failures: history_request_or_manual lacked the new receipt type (source fixed);
the fixture compared reordered PostgreSQL JSONB objects with strict array equality
(fixture corrected to canonical comparison). Successful results above are post-fix.
The new rollback refuses populated corrections; run only with stopped workers and
the preceding source. No live migration or deployment has been validated yet.

Current scratch evidence under `<scratch>` (SHA256):

- native-middleterm-control-probe.php: C65989C95EAE5E2BE8B21ED161A1F528295FB74B5A60556B83633F9FE3AB69C0
- native-middleterm-control-http-probe.ps1: 733A9AD3CB2B20FF65B516EF538C36CF4A42B13F8CA9151F63AEFBA717DBDBFF
- native-middleterm-full-main.php: 3BFB900000DFC1E14735A0B2273E94CE42259E8ADE3F94DBA6A476E1C592619C
- native-middleterm-probe.php: 0125D42CF49F17B153C286EAF26BC4B1E1EBD634AD82D77D0010E1CE299DE6FC

Use native-middleterm-probe.php controls for correction checks. The HTTP probe is a
one-shot mutation of its explicitly named synthetic fixture and must not be blindly
rerun on an already corrected record. The current default, `vr` and `scale` modes
retain their existing roles. All older hashes below are historical.
Full goal remains active; archive and restore must still distinguish suppression
from coverage consumption so a worker cannot immediately undo an archive.

## Read-only provenance and bounded backlog proof (2026-09-07)

- `middleterm_owned` preserves integrity-checked saved publications independently
  of effective source revisions; the production recall reader still validates every
  direct source and prior narrative. A saved actor directory can discover narrative
  authors without requiring a diary. Effective source checks accept only one exact
  actor; zero/combined readers are rejected before database access.
- `ui/api/native_middleterm.php` is GET-only with strict operation/key allowlists,
  bounded cursors and a repeatable-read, read-only management transaction. It never
  claims a native request. Source/version text and hashes are visible; connector
  configuration, credentials and full provider input payloads are not returned.
- `ui/native-narratives.php` uses existing navigation/styles. It distinguishes saved
  provenance, valid earlier records and the record selected for recall. Snapshot or
  actor changes clear prior details; abort-scoped navigation prevents late responses
  from overwriting newer selections. Mutations are explicitly marked unavailable.
- Current standalone probe: 234 checks including exact-actor inspection, cursors,
  stale source provenance and unchanged native globals. Full PHPUnit: 287 tests /
  4633 assertions. API checks: POST 405, unknown actor_name query 400, exact saved
  detail 200. JavaScript syntax and release audit pass (730 files / 419 PHP /
  152 JSON / 12 runtime directories / three catalog checksums / 127 theme sources).
- Scale mode: 465 checks, 110 ordered sources covered exactly once in batches of
  100 and 10. Root correction invalidates recall; rebuilding all 109 descendants
  regenerates earliest corrected coverage as 100+10 without skips or duplicates.
  Latest run: 23.83 ms reader, 14.32 seconds total, disposable database removed.
- Actual main.php fixture mt-inspector-flat-01: 125 checks through native summaries,
  narrative connector HTTP, later prompt and ready speech. Providers remain synthetic.
  All eight copied backend/UI files compare byte-identical with source. Initial
  postgres copy was denied by fixture permissions; exact fixture copies succeeded
  as root without changing permissions or touching the installed server.
- Browser: exact VR session mt-main-vr-02 snapshot 7 shows narrative 2433 and five
  sources; detail heading receives focus, Enter opens source disclosure, selecting
  snapshot 6 clears the later publication. At 390x844, document width is 375px and
  full saved scope wraps visibly; no console errors. Temporary viewport reset and
  exact UI server stopped. Corrected a duplicated closing main tag before final lint.
  Claude UI delegation failed authentication (401); the skill's no-retry Codex
  fallback completed these checks. No independent Claude review is claimed.

Reproduction files under `<scratch>` (current SHA256):

- native-middleterm-probe.php: D01B0AB814253A8433525B0132C235D2DA5E580E295C439B5DB1B84AA73A792E
- native-middleterm-backlog.php: 0268C8F206B12ECD5EDA7E5A71353EA0DCC844D1787655C6DE107EC168859D98
- native-middleterm-inspector-probe.php: 8D28FB0AC7FD71921E5AB57260C20E6D6E68763B486E4BDEBF1183F7A4AEB0F0
- rechat-http-probe.php: 37DAD10DFB7143C8B47B36A8C045CBC539E3C163B224B278D4D1517D9CC353F4

Run `native-middleterm-probe.php scale` for backlog/rebuild checks. Earlier fixture
hashes below are historical. Existing suites and disposable probes were extended;
no new repository test harness or installed database mutation was introduced.

Next management implementation must retain immutable correction receipts, exact
saved scope, optimistic revision checks and CSRF. Prior narrative dependencies must
pin revisions (old inputs use revision zero), so an edit followed by editing back
cannot resurrect stale descendants. Archive must not silently trigger automatic
regeneration that immediately undoes the user's action. Define explicit archive,
coverage and regeneration semantics before wiring mutations; do not substitute a
global NPC pause or base-profile overwrite. These controls remain required, not
deliberately excluded from parity. Deployment and manual flat/VR gates also remain.

## Save branches, actual HTTP and full-main evidence (2026-09-07)

- Reproduced a real role gap: native manual activation could inherit a stale
  SYNTH_NAME=The Narrator and suppress its NPC narrative reader. The owned-role helper
  now treats explicit manual activation as an NPC request, consistent with native
  turn ownership. Unspecified legacy activation retains its previous behavior.
  Existing RequestStreamingTest has a targeted three-assertion regression.
- Updated disposable-clone probe passes 224 checks. Flat and VR each exercise
  pre-publication anchors, first/second saved narratives, rewinding to an earlier
  generation, independent session forks, absent parent, same-name different actor,
  different playthrough and rejected cross-runtime anchors. The existing same-runtime
  saved-anchor guard remains intact; no cross-lane compatibility was invented.
- Actual openrouterjson fast_request sends frozen prompt/evidence to a loopback
  provider. The provider independently checks the 2048-token request and confirms
  no other connection in the synthetic database holds a transaction during HTTP.
  The accepted text remains hidden until a subsequent snapshot, then enters recall.
- A separate PHP worker process is killed only after entering generation outside a
  transaction. Its durable lease prevents early takeover, naturally expires after
  15 seconds, and attempt two publishes exactly one result. No user process is controlled.
- Full-main fixture refreshed from the entire a729b40 source archive, followed by
  the role fix. The copied protocol helper SHA256 matches source:
  7a1308bd66a1ecb2972fb148b2365341e4d21e1c3aa965050163948992c1344f.
  Actual main.php ingress and ready PocketTTS speech supply five settled native
  batches; fixture summary text is accepted through production memory publication.
  The native narrative worker calls the real connector against loopback HTTP.
  A subsequent real model request contains its exact narrative and no legacy base
  marker, with another ready speech result. Post-fix runs mt-main-flat-03 and
  mt-main-vr-02 pass 121/122 checks respectively (speech polling affects the count).
- Full regression suite: 286 tests / 4622 assertions. No real LLM/TTS service or game
  was used. The full-main fixture keeps its synthetic evidence database and files;
  disposable clone databases were removed and all started fixture servers stopped.
  Initial full-main runs exposed fixture log-directory permissions, repaired only
  in /tmp/synth-cancel-pipeline-PmHVx1Rk/log; post-fix runs have no such warning.

Reproduction files under `<scratch>` (SHA256):

- native-middleterm-probe.php: B81305AB18BA533306F19809E082BFC6BFB3D6A069BF4ACA914855922CBC175A
- native-middleterm-save-branches.php: 52030F219B178A84781A8E5E8F0F8AF7AB95FA6DEC710B734365CFD6E98736BB
- native-middleterm-provider.php: 6CDD3477AB44264DF3BAFCB8275614B7176C24237A22FDE797F82D80CE47637C
- native-middleterm-provider-router.php: BEC7EF6BA471551853D2B8CFC1830FEEDE4CEEB9F57F523EEB6547271A31D2C3
- native-middleterm-doomed-worker.php: E474060151B8FA5E27C8F2D7EDBF7A69F8E546E04EEE77664D7D12A5CBBC66E7
- native-middleterm-full-main.php: 7BF63821D5C20201F1F3337D45E5267C97A78A127D8B2ADA4D20EFE5D67FDF08

Run the standalone probe with no argument for flat or `vr`; full-main uses
rechat-http-probe.php with a fresh one-shot run name, `narrative`, and `flat|vr`.
The original 152-check evidence below remains historical, not the updated probe hash.

## Initial implementation evidence (2026-09-07)

- Migration 20260907008 stores canonical inputs, ordered summary versions and
  immutable history-backed results. Input text is stored once; transport evidence
  is deterministically serialized from those frozen fields. No base NPC narrative writes.
- Worker admission and publication use short ownership transactions and actor locks;
  model work uses an idle connection. Three attempts, a 120-second deadline and
  renewable private leases fence retries, cancellation and replacement attempts.
- Existing scheduler invokes one native dispatch independently of automatic
  short-term summary enablement. Explicit NPC policy still overrides profile inheritance.
- Both main_synth_pipeline.php and synth_prompt_manager.php use native saved recall
  without a legacy middle_term_memory fallback. Legacy requests retain their reader.
- A disposable clone of synth_parity_context_20260904 passes 152 checks: schema
  reapplication; empty rollback and populated refusal; 4/5 and 9/10 thresholds;
  exact source capture; worker publication; unsaved-result exclusion; saved recall;
  foreign actor exclusion; actual prompt-manager reader; source archive/correction
  invalidation across two narrative generations; frozen retry input; terminal replay;
  cancellation, expired lease and replacement-token protection. Clone removed afterward.
- Probe: `<scratch>/native-middleterm-probe.php`. Uses the
  production database adapter and worker with an injected synthetic generator;
  it does NOT prove connector HTTP, full main.php prompt serialization or gameplay.
  Probe SHA256: DFD2282243DF1D69FECDB4D8251B22C0650C4FDDCADD0DEBA58D8859CDC047EA.
- Existing full regression suite passes 285 tests / 4619 assertions. The scheduler
  legacy-selection test intentionally bypasses native dispatch through its adapter;
  native dispatch is tested separately by the real-PostgreSQL probe above.
- Release-tree audit passes: 726 tracked files, 416 PHP files linted, 152 JSON files,
  12 Herika-style runtime directories and three World Knowledge checksums verified.
  Its inventory still checks retained provider adapters; new TTS work remains limited
  to XTTS, Chatterbox, PocketTTS, Cartesia and Inworld.
- debug/rollback_middleterm_20260907008.sql requires the exact schema and refuses
  populated native inputs/jobs. Use only after stopping workers and restoring preceding
  source. Populated rollback requires a verified backup/restore plan; no user data
  has been removed and this migration has not been applied to the installed server.

## Verified baseline and current gap

Reference: DialecticServer 907eb634fc49b47abe3490ea8229977ad0b0f6be,
service/processors/middleterm/{entrypoint.php,cmd/generate.php}.

- NPC middle_term_enabled overrides inherited MIDDLE_TERM_MEMORY_ENABLED. The
  existing scheduler inherits only when the NPC key is absent; explicit false
  disables generation. Disabling generation does not delete existing narratives.
- First narrative requires five summaries; later updates require ten new summaries.
  The worker reads at most 100, combines them chronologically with its prior
  narrative, and uses CORE_CONNECTOR_MEDIUMTERM with a 2048-token generation limit.
- Preserve editable middleterm_narrative_summarizer and middleterm_narrative_request
  prompts, with the Fallout 4 character placeholder and factual continuity rules.
- Current SYNTH generator reads legacy memory_summary by name/companions and
  writes timestamp-keyed extended_data.middle_term_memory. This is not proof of
  native actor, playthrough, checkpoint or branch ownership.
- Native short-term summaries already have source visibility, immutable versions,
  archive/rebuild controls and dependency verification in synth_memory.php.
  Native diaries and dynamic profiles have separate saved journals. Neither is a
  substitute for the middle-term narrative feature.
- The initial cutover now reads native saved journals in both prompt paths;
  end-to-end connector serialization and save-lineage acceptance are still required.

The boundary patch excludes actor_identity_id-bound rows from the legacy scheduler
and carries the exact selected legacy row ID into generation. Missing, renamed,
native or native-request selections stop before connector/profile/provider work.
This prevents unsafe legacy processing; it does not provide native narratives.

## Required vertical implementation

1. Add reversible PostgreSQL schema migration for dedicated middle-term inputs,
   ordered input-summary references and immutable narrative results. Add an explicit
   middleterm worker job type; do not disguise it as a profile refresh or diary.
   Each input records canonical owner tuple, actor identity, playthrough, exact
   context sequence, prior result reference, effective settings/prompts and content
   digest. Sources pin summary ID, batch family, revision and exact text/digest.

2. Discover eligible completed native owners using the same canonical ingress,
   active session/generation and snapshot verification as synth_memory_dispatch.
   Resolve the actual selected response actor, including owned rechat/action-result
   speakers, not merely context.target or a global display-name lookup. Exclude the
   Narrator's atmospheric role from NPC narratives. Character roles remain explicit.
   This task must not depend on AUTO_CREATE_SUMMARYS being on when existing visible
   summaries already satisfy the middle-term threshold.

3. Select only effective, unarchived summaries admitted by
   synth_memory_visible_batch_cte for that exact actor/save. Freeze the prior
   visible narrative and all ordered new sources under a short native request lock.
   Require five initial or ten new sources; cap one batch at 100. Process oldest
   eligible unconsumed sources first so a backlog is eventually covered rather
   than silently skipped. Equal game timestamps use stable source IDs. Previous
   narrative coverage is tracked by exact sources, never only a timestamp cursor.

4. Persist input and enqueue idempotently in one transaction. Use one bounded
   middle-term attempt per manager pass, independent of the speech dispatcher.
   Claim/renew exact attempt tokens and use an overall deadline. Provider work runs
   outside database locks. Reuse established connector/credential boundaries;
   general job payloads and diagnostic output must not contain API keys. Freeze
   provider selection and prompts before the first call; retries cannot change them.

5. Before publication, recheck the live owner, attempt token, exact source revisions,
   archive/rebuild state and full previous-result ancestry. Append the immutable
   result and its derived history journal atomically with ready state. Never write
   it into the base NPC row. Store no generated result on cancellation/expiry;
   failed or abandoned attempts must not consume summaries or advance coverage.

6. Add a save-visible narrative reader that verifies the result's history frontier,
   actor/playthrough, every direct summary source and the complete prior chain.
   Archive, source correction or ancestor rebuild invalidates dependent narratives;
   regeneration starts from the earliest affected coverage, without duplicate or
   skipped summaries. Cut both native prompt readers over to this reader while
   retaining legacy behavior only for unbound legacy requests. No automatic import
   of old name-keyed narrative text into a native save lineage.

7. Expose native narrative provenance and controls through the existing owned
   management architecture with exact saved scope, revision checks and CSRF. UI
   implementation requires the UI delegation skill. Preserve existing configuration
   placement; do not present disabled generation as deletion or erase manual legacy
   data. Add native edit/regenerate/archive behavior only with explicit provenance,
   corresponding source-dependency invalidation and truthful status reporting.

## Evidence required before claiming parity

- Real PostgreSQL: explicit/inherited/disabled policy; 4/5 first and 9/10 subsequent
  thresholds; >100 backlog; equal timestamps; exact character and prior narrative.
- Same-name actors, distinct playthroughs, rewind/forked saves and unsaved late
  completions cannot share or leak narrative state. Exact source corrections,
  archive/restore and multi-level rebuilds propagate through the whole dependency chain.
- Real worker process: immutable prompt/provider selection, retry, lost lease,
  process death, cancellation, deadline, replay and atomic publication; no model or
  embedding request under a held database transaction.
- Full main.php request -> native summary batches -> narrative job -> later actual
  model request contains the correct narrative, with no legacy or foreign marker.
- Existing short-term memory, diaries, profiles, relationships and legacy
  middle-term behavior regressions continue to pass. No new large harness by default;
  extend existing suites and use disposable full-stack probes.
- Local deployment with preservation checks, followed by user-run save/load and
  conversation tests; independent VR acceptance remains required. Green SQL/HTTP
  checks alone are not gameplay or narrative-quality proof.
