# Synthserver feature parity matrix

## Current testing candidate

| Feature | Candidate behavior | Game acceptance |
|---|---|---|
| Audio input | Immutable request ownership; valid recognized-empty LocalWhisper success ends without dialogue/actions and shows a retry notice | Quiet and spoken return checks open |
| Wait commands | Explicit text/audio, negotiated flat capabilities, strict owned NPC and empty arguments | New return check open |
| Enemy naming | Current responder's captured opponent overrides older guesses; absent identity stays unknown | Molerat return check open |
| NPC conversations | Owned two-NPC idle origins, eligible random modes and finite proven continuations | Idle and post-player checks open |

The dated sections below are historical checkpoints, newest first. Their dates, hashes and
limits are original; workstation paths are replaced with placeholders (see
[HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)). Current open requirements are
in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md).

## Current Windows launcher

A local Windows executable resumes existing owned database, backend and speech
containers without installing source or running migrations. It checks full local
readiness, opens the dashboard once and requires a separate F4SE button press.
Deterministic adapter tests cover ownership, saved-data mounts, network/ports,
ordered immutable starts, deadlines, cancellation and generic errors. The optional
Codex bridge remains separate. Manual Windows/browser/F4SE interaction and actual
stopped-service startup remain open. See [WINDOWS-LAUNCHER.md](WINDOWS-LAUNCHER.md).

## Current native memory recovery

Accepted summary text is checkpointed before optional embedding, then reused by an
eligible recovery attempt. Exact job/input binding and current lease, actor, source
and saved-ancestry checks remain required. Ready state and immutable publication are
still atomic. No migration or wire contract changes are needed. The retry budget is
unchanged; provider response loss before checkpointing can still repeat a call.
Native history projects game time from the exact captured source/scene context,
preserving raw UTC timestamps. Frozen batches must agree with the corrected clocks
before checkpoint recovery or prior-summary reuse. Disposable fixtures cover
strict settlement, five-event capture and actor/save isolation; game acceptance
remains open. Completed-input cancellation retention is preserved.
See [NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md) and
[ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md) for validation and open game/soak checks.

## Current quest-aware knowledge

NPC dialogue uses save-visible reports addressed to its actual responder and exact
authored participant background. Private journal objectives and native stage events
do not establish NPC knowledge or witnessed outcomes. Protected quest World Knowledge
text is replaced by reviewed material without importing catalogue rows. Current
prewar, vocabulary, equipment and journal-action restrictions remain additional gates.
Later explicit cancellation preserves completed player text/audio learning receipts
while retiring replay and late work. Interrupted inputs and nonplayer descendants
remain cancelled; previously erased completion receipts are not reconstructed.
The quest reader evaluates its actor-owned history projection once per query;
other readers retain their previous default. Query-result equivalence, source
exclusions and evidence bounds are covered without changing request timeouts.
See [QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md) for the eight-rule scope and
[ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md#quest-aware-knowledge) for validation and game checks.

## Player caps payment locally installed (2026-09-08)

Client08f5d2d and compatible servercd59050/c020de9 now provide the matched flat
payment path: distinct ACK, guarded dispatch, player-donor/NPC-receiver receipt
and immutable follow-up. Flat17/VR17 suites, fake-server1306, compatible PHP304/
5744, payment41,12 invalid modes23 each and cancel/reinit/replay races pass.
Installed DLL0F722E88CCCB260FCEA4CB192CE1B24F2D6F9CDC034A74D23B7776040DD9D86E;
19 server files and mirrored protocol32/188/200 verified. Custom INI/PEX unchanged.
See [deployment and backup evidence](HANDOFF.md#retired-ledgers). Manual payment,
save/reload, sustained stability and independent VR acceptance remain open.
Full parity stays active; only the five agreed TTS providers are in scope.

## Player caps payment server contract (2026-09-08)

Maintained source only: server378c7e1 and client protocol bb37542 implement the
separate payment contract. NPC performer/player target map to player donor/NPC
receipt recipient; strict player funds, immutable ancestry and recorded amount
survive follow-up. PHP308/5831, actual-main payment41,12 invalid modes23 each,
cancel/reinit35 each and duplicate/conflict40 pass. Flat17/VR17 suites and mirrored
protocol32/188/200 pass. See [full evidence](HANDOFF.md#retired-ledgers).
Client payment readiness/dispatch and compatible server port remain pending;
nothing new deployed or game-tested. Full parity stays active. Five TTS providers only.

## TakeCapsFromPlayer native producer only (2026-09-08)

Client4ccb308 on codex/dialectic-parity-core adds exact player-to-NPC caps
preparation and paired continuations. The dialogue performer remains the NPC;
the native donor and first inventory receipt are the exact captured player.
The receiving NPC owns the second receipt. Strict amount/currency identity,
same-scene actor ownership, original snapshot immutability, conservation,
cancellation and independent observation quality are preserved.

Source/build/package proof: actions341, all17 flat suites, VR build/CTest17/17,
build gates36, packaging11 and unchanged protocol32/180/194 pass. Read-only
base currency/EXE/Address Library audit passes. Native engine execution is unproven.
No capability advertisement, PluginSession dispatch, server contract or deployment
for TakeCapsFromPlayer yet; those are the next matched milestone. The installed
GiveCapsTo candidate remains unchanged. Manual payment, save-load, voice and
independent VR acceptance remain open. No game control or additional TTS work.

## Matched caps and read-only rechat update locally installed (2026-09-08)

Client fdbffe4 on codex/dialectic-parity-core advertises caps only in flat and
requires exact init_caps_inventory_accepted before dispatch/paired receipts.
Maintained server6ecf670 closes the item-transfer rechat catalog bypass at lookup,
mapping and emission. Compatible codex/equipment-local-20260908 selectively carries
ee834a4/82a8a16 (checkpoint7c54684), without pending embedding changes.

Flat/all17 and VR/CTest17/17, build gates36, packaging11, maintained PHP306/5641,
compatible302/5554 and both release audits pass. Real main.php with isolated DB
and synthetic model passes item/caps NPC52/player51, twelve invalid caps modes23
each, and cancellation/reinit35 plus duplicate/conflict40 for each recipient.
Protocol32/180/194 matches maintained, compatible and installed trees.

Matched flat DLL7175F1AC883CBF8E5B3AD6B58A7A092220D0719D151152436B57F95B0410F6ED
and21 selectively checked server PHP/protocol files are locally installed.
Health200 and exact correlated caps init ACK pass. Custom INI, original PEX,
configuration, live schema, saves, voices and unrelated work are preserved.
Backups and detailed proof are in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

Manual save-load, caps conservation, audible responses, cancellation/recovery
and independent VR acceptance remain open. VR caps stay disabled. No game was
controlled, no real provider was called, and no push/PR/release occurred.
Only XTTS, Chatterbox, PocketTTS, Cartesia and Inworld are in TTS scope.
Full parity remains active; older checkpoints below describe historical states.

## Caps server contract verified; client gate pending (2026-09-08)

Maintained server PHP95cc124 and client protocol513059f on codex/dialectic-parity-core.
Strict typed caps issuance, exact observed currency, separate ACK, paired receipts
and recorded amount/recipient follow-ups pass305 PHP tests/5607 assertions and
isolated actual-main NPC51/player50 checks plus twelve invalid modes23 each.
Flat/VR builds and17 suites pass; protocol32 schemas/180 cases/194 files,
build/packaging gates and both package audits pass. Caps remains unadvertised:
client readiness/dispatch and compatibility/matched deployment proof are pending.
Existing GiveItemTo read-only rechat catalog bypass is the next scoped fix.
Installed client2a20aaa and compatible server0150b04 remain unchanged.
See [goal ledger](HANDOFF.md#retired-ledgers); no gameplay or VR acceptance claimed.

## Flat caps transfer producer (2026-09-08)

Client c620ba5 adds typed GiveCapsTo preparation, exact observed currency selection
and guarded native two-inventory transfer reuse. The exact executable audit corrected
stale CommonLib singleton signature and initialization-array assumptions before use.
Flat/VR builds, all17 suites, actions274, protocol and package gates pass.
Source only: no wire/server negotiation or PluginSession dispatch yet, no capability
advertised, no deployment. VR remains unsupported. Installed speech-cache/transfer
builds are unchanged. See [goal ledger](HANDOFF.md#retired-ledgers) for next steps.

## Speech cache recovery locally deployed (2026-09-08)

Compatible server0150b04 (codex/equipment-local-20260908), maintained producta69be38
(codex/dialectic-parity-core). Invalid WAV caches no longer suppress generation in
the durable worker or the five supported adapters; valid audio remains reusable.
PHP suites302/5397 and298/5310 pass, plus25 isolated actual-adapter/real-ffmpeg
recovery checks with synthetic transports only. Seven server files hash-verified,
healthHTTP200; no cache/config/database cleanup or native DLL change.
This is not evidence that the earlier game freezes are resolved. Manual flat/VR
acceptance and full parity remain open. See [goal ledger](HANDOFF.md#retired-ledgers).

## Matched transfers locally deployed; manual acceptance open (2026-09-08)

Flat client 2a20aaa on codex/dialectic-parity-core and compatible Synthserver
product dd9a22c / deployed source 0c43aac on codex/equipment-local-20260908
are installed locally. Maintained server product189c6f4 remains on
codex/dialectic-parity-core; its pending embedding work was not deployed.

- Flat transfer advertisement requires a separate owned transfer acknowledgement.
  Impossible paired results cancel only their own turn/context, never a replacement.
- Flat native suites and VR CTest17/17 pass; build gates36, packaging11,
  protocol32 schemas/172 fixtures/188 mirrored files and both package audits pass.
- Installed DLL SHA256:
  2092EEFD9E747DDAFD035C0496D7EC0C553C9C0631F995C8173D0907EB05FDE1.
  All28 selectively deployed server hashes match; healthHTTP200 and actual runtime
  init_transfer_inventory_accepted verified. Configuration and bridge unchanged.
- No game was launched or controlled. Transfer conservation, inventory follow-up,
  sustained dialogue and save/load responsiveness still require the user's manual
  flat test. VR transfer stays unsupported; independent headset acceptance remains.
- Full evidence, backups and remaining work: [goal ledger](HANDOFF.md#retired-ledgers).
  Full parity is not complete. TTS only: XTTS, Chatterbox, PocketTTS, Cartesia, Inworld.

## Matched Consume deployed; gameplay acceptance remains open (2026-09-08)

Installed flat client ee8a192 (codex/dialectic-parity-core) and compatibility server
ddda7d4 (codex/equipment-local-20260908). Flat advertises both Consume capabilities;
execution still requires the explicit owned-inventory acknowledgement. VR stays disabled.
All 17 server files match source; installed init returns init_consume_inventory_accepted.
Installed DLL: 8052167338934999DB4A20443819F0553CEFCB0FAB85024843D767AD2DAA9881.
Both builds/17 suites, protocol 31/157/174, PHP 294/5029, 245 compatible full-main
pipeline checks and 33 installed-source rollback wire checks pass. Both ZIP audits pass.
Custom/default INIs and PSC/PEX unchanged; schema remains 20260907012. Pending embeddings,
unrelated runtime drift and shared MiniMe were not deployed/restarted.
Manual Consume effects, same-save/message stability and independent VR proof remain open.
See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for artifacts, backup paths and evidence limits.
Only XTTS, Chatterbox, PocketTTS, Cartesia and Inworld remain in TTS scope.


## Inventory observations enabled and deployed locally (2026-09-08)

Flat client 5b81452 (codex/dialectic-parity-core) + compatibility server 092e5ad
(codex/equipment-local-20260908) are installed together. All 18 changed runtime/
protocol files and the client DLL were hash-verified; actual installed init ACK
and health HTTP 200 pass. Full-server sync was avoided to preserve unrelated
differences and pending embeddings. Schema stays 20260907012; configs/PEX preserved.

Compatibility pipeline: 200 synthetic full-main checks across ordinary inventory,
cancel, generation reset and duplicate/replay. PHP 294/4948; both builds/17 suites;
765 fake-server checks; protocol 31/150/167; 34 build gates/11 package tests; audits pass.
Full details, exact artifacts and backups are in the goal ledger's current checkpoint.

ENABLED / LOCALLY DEPLOYED / NOT GAMEPLAY VERIFIED. User manual save-load/dialogue/
equipment test is next. Actual model judgment and independent VR acceptance remain
unproven. No game was launched or controlled. Five approved TTS providers only.

## KoboldCpp native long-term summary admission (2026-09-07)

DEPLOYED749d5ad / SYNTHETIC FLAT AND VR VERIFIED / LIVE MODEL AND GAME OPEN.
Native memory now admits the existing KoboldCpp background chat adapter alongside
the five other LLM drivers. Prior code rejected the configured driver before HTTP.
103 SQL/actual-adapter checks per lane cover frozen prompt/settings,503 retry,
in-flight lease cancellation, one publication/replay and exact-actor recall.
289 PHPUnit/4680 assertions and release audit pass. Server-only deployment is
hash-verified and healthy with preserved configuration/data and unchanged client.
No TTS provider scope expansion or automatic provider configuration change.
See the current goal checkpoint for exact artifacts, backups and remaining gates.

## Native narrative local deployment (2026-09-07)

DEPLOYED SERVER9c26273 / SCHEMA20260907012 / MANUAL GAME ACCEPTANCE OPEN.
Native saved narrative storage/worker, inspection, correction/archive/restore and
explicit regeneration controls are installed at /var/www/html/Synthserver. Public
HTTP health/scopes and actual background processes are verified. Client DLL remains
the previously deployed843f848 binary; ce8097d changes documentation only.
New773-check flat/VR probes cover actual replacement-worker death, natural lease
expiry, attempt2 recovery without deadline reset/duplicate publication, and bounded
discovery beyond22 obsolete receipts. Prior public-control/unit/HTTP/browser evidence
applies to unchanged product9c26273. Database/config/voice/cache preservation and
private backups are documented in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)). One actor last_seen_at
timestamp refreshed; no substantive actor data changed. No game or live provider
test; sustained reliability and full roadmap/headset acceptance remain open.

## Public native narrative regeneration (2026-09-07)

IMPLEMENTED / SYNTHETIC SQL, PUBLIC HTTP AND BROWSER VERIFIED / NOT DEPLOYED.
Regenerate uses explicit CSRF-protected exact-plan approval. Status distinguishes
approval, pending/running/retry/recovery, terminal failure and publication awaiting
a newer snapshot. A third live attempt is not falsely exhausted. Original records
remain inspectable with saved replacement navigation; pending/saved replacements
fence further changes to their originals. Failed/corrupt evidence cannot claim success.
The UI preserves uncertain intent for explicit retry, never retries POST automatically,
and invalidates outdated status after confirmation. Claude authentication fallback
was used; controls were reviewed and tested in Codex.

Proof:169 SQL checks per flat/VR; public API plus actual full-main ingress/connector
HTTP171 flat and168 VR;289 PHPUnit/4674 assertions; correction131/archive160/
preflight149/scale471; release audit and Node syntax pass. Browser covers confirm,
cancel/focus, committed503/status recovery, original/replacement navigation, old
snapshot exclusion and390px layout. SQL verifies no duplicate UI approval.
Pre-commit lost-response retry is not yet browser fault-tested. See the current
the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) checkpoint for exact fixtures, hashes and remaining gates.
No deployment or game proof; five-provider TTS scope unchanged.

## Native middle-term memory implementation in progress (2026-09-07)

Native narrative storage, worker dispatch and both saved prompt readers are now
implemented but NOT DEPLOYED. Existing suites pass 289 tests / 4664 assertions.
Expanded real-PostgreSQL/HTTP probe passes 236 checks including save branches,
rewind, actor/save isolation, connector HTTP and real worker-process death/recovery.
Full-main flat/VR fixtures prove dialogue -> native summaries -> narrative HTTP ->
later model prompt and ready speech, without legacy narrative leakage. Explicit
manual activation no longer inherits a stale Narrator role. Providers and summary
text remain synthetic. Read-only exact-snapshot narrative provenance is now exposed
through an API and browser-checked management page; source invalidation stays visible
without making stale narratives eligible for recall. A 467-check scale probe proves
110-source 100+10 coverage and regeneration after 109 descendant rebuilds. Current
full-main correction runs pass 138 checks in each lane. Revision-checked edit backend
and CSRF API now preserve original text, fence in-flight workers and invalidate prior
revision dependencies (129 SQL correction checks / earlier 12 HTTP checks). Archive
and restore backend/API now suppress descendants while reserving rebuilt source
families without pausing unrelated new roots: 158 SQL checks, six HTTP checks and
155 flat / 152 VR full-main checks. Saved archive/restored status is browser-verified.
The confirmed editor/archive/restore UI now passes browser mutation checks, including
new-snapshot visibility, draft invalidation, stale conflicts, escaped text and a
server-committed/lost-response recovery case. Original provider text remains separate.
Explicit regeneration now has a read-only exact-target source/prior preflight:
147 SQL checks, four HTTP states and a 469-check 110-source/rebuild probe pass.
The control API explicitly reports execution_available=false; no approval/job is
created. Standalone management loading was verified without game request ingress.
Schema011 now provides an internal durable approval backend: reviewed-plan fingerprint,
idempotent token, saved acknowledgement, exact branch ownership, opt-out and supersession
fences. 120 SQL/process-race checks pass per flat/VR lane. No worker is claimed or queued;
the public Regenerate action remains unavailable. Updated ordinary suites pass148
preflight/130 correction/159 archive/470 scale checks; full-main flat baseline passes126.
Schema012 now implements completed native owner adoption, bounded cross-owner attempt
recovery and atomic replacement publication. Older saves retain original text; later
snapshots use replacements, and descendant regeneration follows its replaced prior.
146 replacement checks pass per flat/VR lane; actual full-main regeneration passes156
per lane including replacement HTTP and later model prompt/ready synthetic speech.
Existing correction131/archive160/ordinary VR238/scale471 checks pass. Public execution
controls and truthful replacement/status navigation, remaining ownership variants,
deployment and manual flat/VR acceptance
are still open; this is not full management or gameplay parity.
See NATIVE-MIDDLETERM-MIGRATION.md for precise scope and evidence.
The paired deployed voice build remains unchanged.

## Ordered speech and shared-cache fixes deployed (2026-09-07, historical)

_Historical receipt, retained as dated evidence. Workstation paths are replaced with
placeholders; see [HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)._

The paired local flat deployment now includes client 843f848 (ordered independent
speech delivery) and server db0b59a (shared-cache cancellation/readiness). Both are
on codex/dialectic-parity-core. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for exact hashes,
backups and passing build/test/deployment evidence. No game was controlled;
manual flat and independent VR acceptance remain open. Five TTS providers only.

## User scope update: five TTS systems only (2026-09-07)

The user explicitly narrowed ongoing TTS implementation and acceptance to:
XTTS (xtts-fastapi), Chatterbox (chatterbox), PocketTTS (pockettts),
Cartesia (cartesia) and Inworld (inworld).

This supersedes earlier checkpoints requiring completion across all 17 TTS
adapters. Do not spend further implementation or provider-acceptance work on
the other adapters. Existing code and already committed fixes stay intact;
"ignore the others" is not authorization to delete adapters or user settings.
Shared response ownership, cancellation, recovery and media publication remain
in scope where they serve these five systems. Earlier outstanding OmniVoice,
xVASynth and Zonos-specific work is no longer a gate for this goal. No external
OmniVoice service changes are needed under this narrowed provider scope.

The remainder of the approved SYNTH/Dialectic parity objective is unchanged,
including gameplay/actions, persistent AI, management/public APIs, local paired
deployment and separate manual flat/VR acceptance. Full goal remains active.

## Shared speech cache cancellation fix (2026-09-07)

SOURCE VERIFIED / NOT DEPLOYED / FULL GOAL ACTIVE.

Reproduced through real main.php, synthetic PostgreSQL and loopback PocketTTS:
two independent flat sessions generated the same plan/cache key; cancelling the
second owner made the first reply's tts_status report synthesis_failed while its
worker was still generating. Pre-fix cache-overlap6 failed on that exact response.

synth_tts_resolve_job_status now retains pending when another unexpired
pending/running durable job owns the same cache key. Normal polling retains its
primary-key lookup; only failed/expired owners need the active-job lookup.
Already published audio may override a later pending admission only when both
the immutable object and its database registration are ready. Polling never
publishes/registers audio or rewrites jobs/status files. Request cancellation,
attempt leases, deadlines and client playback ownership are unchanged.

Evidence:
- Final server suite: 282 tests / 4598 assertions; two existing suites extended.
- Full HTTP cache-overlap13: 106 checks, surviving owner delivers hash-verified
  media with one provider synthesis; an unrelated cache cannot keep it pending;
  a staged late pending write cannot hide registered audio or mutate on polling.
- Full HTTP cache-overlap12: 41 checks, cancelling both owners leaves terminal
  failure and no media after the delayed provider would have completed.
- cache-overlap9 VR was inconclusive: voice_metadata.updated_at differed between
  the captured plans, so it did not exercise a shared key. Not VR acceptance.
- Release audit: 722 tracked files, 414 PHP, 152 JSON, 12 Herika-style runtime
  directories and 3 World Knowledge checksums pass. The 17-adapter inventory is
  an existing structural check, not expansion of the five-provider work scope.
- Scratch probe: `<scratch>/rechat-http-probe.php`;
  runtime /tmp/synth-cancel-pipeline-PmHVx1Rk; DB synth_parity_cancel_20260907.
  All fixture servers/workers stopped. No production dialogue or provider calls.

Installed client/server pair remains the earlier paired deployment below.
No game was launched, closed or controlled. Real five-provider voice/cloning
quality, manual flat/VR gameplay and the remaining parity matrix stay open.

## Paired local deployment: owned replies and truthful action results (2026-09-07, historical)

_Historical receipt, retained as dated evidence. Workstation paths are replaced with
placeholders; see [HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)._

DEPLOYED LOCALLY / MANUAL GAME ACCEPTANCE OPEN / FULL GOAL ACTIVE.

Used synth-full-deploy with explicit clean worktrees on codex/dialectic-parity-core:
- SYNTH 3fde817e3cb4c14b527f435d15685e30a8bb2b65 (client product 9d2ec76).
- Synthserver 950fc5298b286267f8b350df52ed25739c039df0 (latest product d02ec3b,
  including 29b6740 cancellation, 322b301 capability catalog and 2b4431f actor binding).
The client's owned automatic replies and all coordinated server fixes are now
installed together. TTS scope stays XTTS, Chatterbox, PocketTTS, Cartesia, Inworld.

Read-only process/MO2 checks found no Fallout4/Fallout4VR process. Default profile
uses `<Fallout4-install>`, executable 1.11.240.0.
No game, VR or editor was launched, closed or controlled.

Verification:
- Fresh flat and VR x64 builds pass, all 17 native suites each, including 539
  fake-server checks. 34 build guards, 11 packaging tests and 140 protocol
  fixture cases pass. Both lane package audits pass; VR packaged, not installed.
- Skill reports SUCCESS - Synthserver to WSL, SUCCESS - SYNTH flat client to MO2,
  and Deployment completed successfully. Bootstrap/schema and Apache syntax pass.
- Flat installed `<MO2-mods>/SYNTH_dev/F4SE/Plugins/SYNTH.dll` SHA256:
  837CF1FB20237922437D37AED6017F636E863FC1A262D1CEF5D01B19F95A24DF.
  Built VR SHA256:
  ACB769088E184464289384CA2758635B47B12979F8A81079CC6A7ED26391476C.
  Installed original SYNTHNative.pex SHA256 unchanged:
  56D6D12F82FFC41F1CFA91A2AA465B67466022F7C550B0B4CEBA3E56D11FEF9E.
  Custom INI SHA256 unchanged:
  51972C4A66F1EF89B067C6C26747CFB5CFC425E6C667F52180296F433CDF82F3.
- Server `<wsl-distro>:/var/www/html/Synthserver`; ten changed product files
  compared byte-for-byte with source: request, provider_stream, funcret,
  synth_protocol_state, functions catalog and five JSON connectors.
- HTTP http://127.0.0.1:8087/Synthserver/health.php is 200 with database/schema/
  background_processor true. Explicitly restarted only the verified SYNTH
  supervisor after stopping its exact old parent/children before synchronization.
  New supervisor 1899318, nested supervisor 1899330 and tts_dispatch.php --loop
  1899332 remain alive on a subsequent process check. Port 12348 stays isolated.
- Private job endpoint returns 403; invalid tts_status request returns 400.
  Server conf/conf.php, data/voices, soundcache and uploads compare unchanged
  against the pre-deploy archive. No production dialogue/test requests inserted.
- Last source server suite, before this documentation-only deployment checkpoint:
  282 tests / 4571 assertions plus full synthetic action/reply/outcome checks.

Recoverable pre-deploy backups retained:
- Windows `<private-backup>/deploy-20260907-2218/SYNTH_dev`.
- WSL `<server-backup>/synth-parity-20260907-2218`, mode 0700.
  synth-before.dump SHA256
  4c0b310061ba21c5b6d8e9aa966111d4b403a7d4ff970378f4faac6d6006f120;
  pg_restore --list succeeds (747 output lines); no restore test performed.
  Synthserver-before.tar.gz SHA256
  087c30b27511c21230149918e8c1232de67bd24ff43c148411b43c6f8d5446ac.
No push, PR, release or version bump. No sibling mod/service files were targeted.

Manual handoff: user launches Fallout, loads a save, sends a message and
interrupts an automatic reply with another message; then correlate fresh logs.
This is deployed readiness, not in-game acceptance. Separate VR headset proof,
real five-provider voice/cloning/model behavior, private-mode controls, native
actions and remaining gameplay/persistent AI/management/public API parity remain
open. Full goal stays active.

## Explicit native action outcomes in provider prompts (2026-09-07)

SOURCE / SYNTHETIC PIPELINE VERIFIED / NOT DEPLOYED THIS TURN / FULL GOAL ACTIVE.

Product d02ec3b closes the previously observed terminal-status rendering gap.
processor/funcret.php attaches the bound effective status to its exact tool
message as internal _synth_action_status metadata (including server-derived
unavailable inventory). Raw detail and tool-call identity remain unchanged.
Five JSON connectors and the paired Kobold text-template lowering path now
pass that status to the shared formatter. All eight terminal outcomes are
allowlisted; invalid values fail closed. Non-success results bypass success
templates and explicitly warn that no successful observation/effect is proven.
Successful results retain configured return wording with an explicit succeeded
prefix. Empty details remain visible, and text beginning Error cannot override
an explicit native status. Legacy/detail-only behavior remains unchanged.

Evidence:
- Actual full-main HTTP outcome-http2: flat failed, 71 checks;
  outcome-http3: flat unavailable, 70; outcome-http4: VR succeeded, 69.
  Actual outgoing model requests contain the exact terminal status and
  non-success Inspect no longer says the actor "inspects ... and sees this".
  Correct performer dialogue, durable PocketTTS media/hash, replay without a
  second model call and original chain budget all pass.
- 30 actual JSON connector request-building cases: openrouterjson, openaijson,
  google_openaijson, groqjson and player2json; normal/custom return templates;
  failed with empty detail, unavailable with Error-shaped detail, succeeded
  with Error-shaped literal observation. Inputs stay unchanged; internal status
  metadata does not leak as an API message property. Transport intercepted in
  these 30 cases; not real provider/network acceptance.
- Kobold: 76 failed and 76 succeeded template/action/shape/prefill cases over
  real loopback HTTP/native cURL. Actual generated prompts preserve all 512
  inventory rows, paired IDs, literal history, explicit outcomes, streamed
  parser output and input immutability. The fake model returns fixed text;
  this proves prompt/transport behavior, not real-model interpretation.
- Existing actual funcret processor probe: 27 checks; isolated/retry-stable
  native tool IDs, quoted arguments, all 512 rows, mismatch rejection and
  legacy behavior pass.
- Existing RequestStreamingTest expanded for all seven non-success statuses,
  empty/Error-shaped/misleading detail, success with custom templates,
  invalid status, legacy behavior, and differently ordered paired outcomes.
  Full PHPUnit: 282 tests / 4571 assertions.
  Release audit: 722 tracked, 414 PHP, 152 JSON, 12 Herika-style directories,
  3 World Knowledge checksums; diff check passes. No schema/migration/version
  change; no client or TTS-adapter product changes.
- Scratch scripts under `<scratch>`:
  rechat-http-probe.php SHA256
  FC659EA5D7AE1828B8C8715E4D79064D9ECDE649073B173C188420EA8C915E6B;
  connector-tool-serialization-probe.php SHA256
  27237E012018D146EF7F4D3F0994433836C68BF76E27FDC051E2BFD54982A218;
  kobold-template-http-probe.php SHA256
  3B52E91A5E920999B37B2BFC6325BAE0D0F8ACCF7FC14A4CC18AE87046B853C1.
  Full-main runtime /tmp/synth-cancel-pipeline-PmHVx1Rk, DB
  synth_parity_cancel_20260907. Fixture processes stopped/reaped.

No deployment, production test data, game launch/control, real provider call,
push or PR this turn. Next paired deployment must include client 9d2ec76 and
server 29b6740, 322b301, 2b4431f and d02ec3b. Re-read deployment skill and verify
current game/runtime state before replacing the installed manual-test build.

Still open: native client action execution/results and private-mode controls,
real model behavior and five-provider voice/cloning acceptance, deadline/cache
ownership edge cases, remaining gameplay/persistent AI/management/public API
parity, and manual flat plus independent VR headset acceptance. Full goal stays
active; provider scope remains the five explicitly approved systems.

## Action receipt performer binding and narrator baseline audit (2026-09-07)

SOURCE / VERIFIED SYNTHETIC HTTP / NOT DEPLOYED THIS TURN / FULL GOAL ACTIVE.

Product 2b4431f fixes action-result profile selection: the native bound payload
uses actor for the performer and target for the inspected/action target, but
synth_extract_funcret_actor ignored actor and selected target. A rechat-issued
Inspect by Fixture Settler targeting Preston Garvey therefore rebound Preston's
profile; response ownership then rejected that speaker and durable speech
threw "Durable speech actor mismatch". The actor field now takes precedence,
with prior aliases/target fallback retained when actor is absent.

Evidence:
- action-receipt2 reproduced the full-main partial response containing
  speaker_identity_unresolved followed by the logged durable-speech mismatch.
- action-receipt4: 67 checks, flat succeeded. action-receipt5: 68 checks, VR
  succeeded. action-receipt6/7: 68 checks each, flat failed/unavailable.
  Inspect emits its action without claiming a spoken observation before the
  receipt. Actor B owns the intent, actor A is its target, and B receives the
  post-result dialogue plus verified downloaded PocketTTS media. Exact receipt
  replay adds no model request; later rechat remains round 2 of the original
  player's chain, then budget exhaustion stops further model work.
  Receipt terminal outcome is preserved independently of intent state complete.
- Initial fixture assumptions corrected: Inspect intentionally emits no
  pre-execution speech; successful/failed/unavailable intents all become
  complete while action_terminal_results.status carries the actual outcome.
- Added 11 assertions in existing RequestStreamingTest for performer priority
  over target/legacy aliases, legacy fallback and invalid JSON.
  Full PHPUnit suite: 281 tests / 4498 assertions. Release audit: 722 tracked,
  414 PHP, 152 JSON, 12 Herika-style directories, 3 knowledge checksums.
- Read exact Dialectic c6e92f375a44a680affc22c8ba378320650b92da
  Plugin/src/SpeakManager.cpp:4201-4216 and SYNTH
  src/client/plugin_session.hpp:1898-1916. Both exclude narrator/player
  dialogue from launching another automatic reply. Narrator chain stopping
  is intentional baseline behavior, not a parity defect; no client edit.
- Scratch rechat-http-probe.php in `<scratch>`:
  SHA256 1CE171276B0D9DC15E6616D52D5FBE2972CEACF3EE298ACA702FF920A299038C.
  Synthetic runtime /tmp/synth-cancel-pipeline-PmHVx1Rk, DB
  synth_parity_cancel_20260907. All fixture listeners/workers stopped.

NEXT VERIFIED GAP: failed/unavailable status is correctly persisted, but the
actual action-receipt6 model request says the actor "inspects ... and sees this"
followed by the failure detail; it omits the explicit terminal status.
processor/funcret.php passes only result detail to the tool role, and provider
return templates still assume success. Preserve paired action ownership/history
while making native terminal outcomes explicit and non-success narration
truthful across JSON and text-template connectors. The successful transport/
speaker tests above do NOT establish truthful model interpretation of failure.

No local deployment, game control, production test data, real provider calls,
push or PR. SYNTH client remains unchanged at 3fde817. Next paired deployment
must include client 9d2ec76 and server fixes 29b6740, 322b301 and 2b4431f.
Five-provider scope and the full gameplay/persistent AI/management/public API
goal remain active; client action execution and separate flat/VR game acceptance
remain unproven.

## Native action catalog and full-main branch acceptance (2026-09-07)

SOURCE / VERIFIED SYNTHETIC HTTP / NOT DEPLOYED THIS TURN / FULL GOAL ACTIVE.

Product 322b301 fixes database catalog merging reintroducing unnegotiated
actions after the initial native capability filter. The final enabled/runtime
catalog now applies the same capability predicate after database rows and
prompt overrides load. Removed entries cannot remain in DEFINED_FUNCTIONS.
Existing final wire/intent guards remain in place; no schema, migration,
provider, client or version changes.

Evidence:
- Before the fix, rechat-actions4 with no action.end_conversation capability
  advertised End_Conversation to the model although the output guard correctly
  refused to emit it. The real main/profile/database/model path reproduced this.
- After the fix, rechat-actions9/action-allowed (70 checks),
  rechat-actions10/action-disabled (69), and
  rechat-actions11/action-unnegotiated (65) pass. Exact selected NPC identity
  owns the emitted action and durable intent; replay is byte-identical, adds no
  intent and makes no extra model call. Unsupported actions are absent from
  both the model catalog and wire response. Two successive responders retain
  the first-selected profile's captured chain policy after a live profile edit.
- Fixture correction: action display names use End_Conversation, not the
  canonical server code EndConversation. Profile action policy freezes at the
  first selected continuation, not the player's root input; tests now match
  that existing contract. Neither mistaken fixture expectation caused a
  product semantics change.
- catalog-flat1: 76 full-main checks; catalog-vr1: 73. Alternating canonical
  responders, speech download/hash, ancestry, replay and two-round budget pass.
  VR here is HTTP/wire acceptance only, not game/headset acceptance.
- catalog-narrator1: 51 checks. Narrator output, durable voice job and downloaded
  speech retain narrator identity (NULL actor_identity_id), verified ancestry,
  and replay without another model request.
- catalog-whisper1 and catalog-close1: 41 checks each. The captured private
  origin blocks the child without dialogue/model/TTS work after the current
  server mode changes to Standard. This does not prove client mode controls.
- Focused existing ActionCatalogTest regression: 1 test / 9 assertions.
  Full server regression suite: 280 tests / 4487 assertions.
  Release audit: 722 tracked files, 414 PHP linted, 152 JSON validated,
  12 Herika-style runtime directories, 3 World Knowledge checksums.
  Audit inventory retains 17 adapter files; provider-specific work remains
  strictly limited to the five systems named above.
- Fixture listeners and workers stopped after every run; no game or real
  provider calls. Scratch runtime /tmp/synth-cancel-pipeline-PmHVx1Rk,
  database synth_parity_cancel_20260907; scripts and receipts retained under
  `<scratch>` and runtime data/tmp.
  rechat-http-probe.php SHA256:
  457B708FF31A397348267FF46B90123047B153D43C964DBF5D289B8D79FDABF6.
  cancel-pipeline-provider.php SHA256:
  52484648EE0FE34E358F73C0591B61D0B9EE1EB7F031D422E4483BE7B34232E3.

No deployment, production test data, game launch/control, push or PR this turn.
The next paired deployment must include client 9d2ec76 and server 29b6740 plus
322b301. The installed manual-test baseline is intentionally not changed here.

Remaining gates: compare narrator continuation behavior with Dialectic,
validate native client private-mode controls and action execution/results,
complete five-provider real voice/clone lifecycle acceptance and deadline/cache
ownership cases, remaining gameplay/persistent AI/management/public API parity,
then manual flat and independent VR headset acceptance. Full goal stays active.

## Owned reply-chain HTTP acceptance and client enablement (2026-09-07, historical)

_Historical receipt, retained as dated evidence. Workstation paths are replaced with
placeholders; see [HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)._

SOURCE / BUILT / PACKAGED / NOT DEPLOYED THIS TURN / FULL GOAL ACTIVE.
Client product 9d2ec76 enables dialogue.rechat.ownership in the shared flat/VR
capability set. Existing scheduling now sends the exact completed parent
request and line, original turn/snapshot and cancellation token. No new
setting, legacy fallback, schema/version change or runtime adapter change.

Server product 29b6740 fixes a defect found in the actual child-reply path:
cancelling a completed root request only cancelled that row, leaving the child
provider running. It later failed the prepared-parent proof and left its own
request claimed. Negotiated v2 cancellation now resolves either a context or
an owned response as the exact turn/snapshot anchor. It retires matching
requests and the active turn, and cancels workers with either nested legacy
or top-level durable context_sequence. Other sessions, generations, turn IDs
and snapshot sequences remain excluded. Unnegotiated, v1, unowned and
non-manual activation requests keep request-only cancellation.

Evidence:
- Full-main synthetic HTTP: two captured NPCs alternate for two rounds, use
  the selected canonical profile/speaker, include verified original-input
  ancestry in actual model requests, synthesize/register/download PocketTTS
  WAV, replay exact response bytes without another provider call, then stop
  at the two-round budget without another model request.
- rechat-finalflat and rechat-finalvr: 73 checks each. VR is wire/HTTP proof,
  not headset/game proof. rechat-samename1: 73 checks with distinct FormIDs
  sharing the same label; conversational selection preserved each identity.
- rechat-cancel3: ancestor-request cancellation unwound the child in 0.055 s;
  88 checks including next-message speech recovery. rechat-ctxcancel1:
  context-anchor cancellation 0.034 s, 105 checks with next-message recovery.
  Counts include polling assertions and vary with scheduling.
- Existing full-main model/TTS cancel and generation-advance suite:
  ownercancel-http1, 237 checks; recovery passed. All fixture listeners/workers
  stopped; ports 18971/18972/18973/18976 verified unbound afterward.
- Server PHPUnit: 279 tests / 4478 assertions; release audit 722 tracked,
  414 PHP, 152 JSON, 12 Herika-style directories, 3 catalog hashes.
- Both Windows x64 DLL builds, all 17 native suites in each lane, 539 fake-server
  checks, 34 build guards, 11 packaging tests and 140 protocol cases passed.
  Both generated package manifests/release audits passed.
  Flat DLL: 837CF1FB20237922437D37AED6017F636E863FC1A262D1CEF5D01B19F95A24DF.
  VR DLL: ACB769088E184464289384CA2758635B47B12979F8A81079CC6A7ED26391476C.
- Fixture script `<scratch>/rechat-http-probe.php`,
  SHA256 6AAB6DD4D234EDE7D302692B0BF6E248F3098F71E3106F98880E1FBE73EE8726.
  Synthetic provider script cancel-pipeline-provider.php SHA256
  132C38CB92C7446DE11B98411AF87A046E34EB29CE2AAE7A42A84E823F060F47.
  Runtime /tmp/synth-cancel-pipeline-PmHVx1Rk and DB
  synth_parity_cancel_20260907 contain only retained synthetic fixtures.

Installed client remains f1ff7ce / DLL
0CBA561B8D45EC0A43C8EC462A47094A11DF3583BC4C8414DB01FE13D9B4D514;
installed server remains 240e3b3 / schema 20260907007, HTTP health/background
true. No deployment/service mutation, game control, production test data,
real provider call, push or PR this turn. Preserve the user's manual-test
baseline; the next paired deployment must include BOTH product commits above.

Still open: real voice quality/provider lifecycles for the five allowed systems,
narrator/action/private-mode full-path variants beyond the existing narrower
proofs, client game scheduling and independent headset acceptance, deadline
origin/cache-owner edge cases, and the remaining full gameplay, persistent AI,
management and public API parity matrix. This enables a proven source path;
it is not full parity or in-game completion.


## Local paired deployment and legacy speech cutover (2026-09-07, historical)

_Historical receipt, retained as dated evidence. Workstation paths are replaced with
placeholders; see [HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)._

DEPLOYED LOCALLY / GAME ACCEPTANCE OPEN / FULL GOAL ACTIVE.
Scope remains XTTS, Chatterbox, PocketTTS, Cartesia and Inworld only.

Server product 240e3b3 closes the old file-worker bypass: native turn-owned
five-provider envelopes exit before runtime bootstrap/provider work instead
of executing without a durable job lease. Both captured-plan and older
tts_function shapes are recognized. Old envelopes are retained, not adopted,
deleted or replayed. Ten real worker subprocess cases verify the refusal and
unchanged files. Out-of-scope providers and non-native worker paths are unchanged.

Verification:
- Full server PHPUnit: 279 tests / 4414 assertions.
- Full-main synthetic PocketTTS pipeline: cutover-http1, 242 checks. Model
  cancel 0.183 s, speech cancel 0.098 s, model generation advance 0.183 s,
  speech generation advance 0.201 s; next-message recovery passed. Actual
  status/media HTTP checks verify registered audio bytes. All fixture servers
  and workers stopped. No real provider credentials or production test jobs.
- Release audit: 722 tracked files, 414 PHP, 152 JSON, 12 Herika-style
  directories, 3 knowledge checksums. Inventory still includes 17 adapters;
  that is packaging preservation, not an expansion of the five-provider goal.
- Actual installed Apache invoked a PHP CLI child as www-data (UID 33).
  Synthetic private-input bytes matched, outside the document root; directory
  mode 0700 and file mode 0600. Endpoint and input were removed after the probe.
  Probe SHA256: 67295719D34602784C0420C75B2F58A502F7C3ED0AEBF5CFF5CB29CC52F8E2C6.
  Script: `<scratch>/synth-private-access-probe.php`.

Deployment:
- Sources: SYNTH fa45fd4 (product f1ff7ce), Synthserver 240e3b3;
  both codex/dialectic-parity-core, clean before deployment.
- Used synth-full-deploy with explicit worktrees and -SkipBuild after the
  preceding flat/VR full build/package checkpoint. Both required success
  summaries and overall completion were returned.
- Flat target `<MO2-mods>/SYNTH_dev`; verified portable MO2 Default
  uses `<Fallout4-install>`, executable 1.11.240.0.
  Installed DLL SHA256:
  0CBA561B8D45EC0A43C8EC462A47094A11DF3583BC4C8414DB01FE13D9B4D514.
  Installed original bridge PEX SHA256:
  56D6D12F82FFC41F1CFA91A2AA465B67466022F7C550B0B4CEBA3E56D11FEF9E.
- Server: `<wsl-distro>:/var/www/html/Synthserver`. PostgreSQL synth migrated
  from 20260906005 to 20260907007; bootstrap/seed verification passed.
  Selected worker, durable-job and supervisor source/runtime SHA256 pairs match.
- HTTP 200 at http://127.0.0.1:8087/Synthserver/health.php, with database/schema
  true and background_processor true after explicit service restart.
  Version/build strings remain 0.7.2 / 2026081007; no release/version bump.
- Old supervisor PID 417146 was verified idle and stopped before synchronization.
  New parent 1861418, nested supervisor 1861438, durable speech dispatcher
  1861440 were confirmed alive; dispatcher remained stable across observations.
  Real manager children completed their tasks. No Fallout process was present.
- Important deployment-helper limit: HTTP 200 did not start/verify the background
  service. Explicitly called the installed synthEnsureBackgroundProcessorRunning
  as www-data after synchronization and verified both heartbeat and dispatcher.

Preservation and rollback evidence:
- WSL backup directory `<server-backup>/synth-parity-20260907-2130`, mode 0700.
  synth-before.dump SHA256:
  e24e542b763a0ef4a43287f249aa49e817b7b748145382070cbeff78e7ba2a4b.
  Archive index readable, 702 entries; no restore into production was performed.
  Synthserver-before.tar.gz SHA256:
  e7099fcdb2e5fc236fe1d92d3991728444599f9722f94e0096d584806841ee54.
- Client backup: `<private-backup>/deploy-20260907-2130/SYNTH_dev`.
- Client custom INI hash unchanged:
  51972C4A66F1EF89B067C6C26747CFB5CFC425E6C667F52180296F433CDF82F3.
  Server conf/conf.php hash unchanged; archive comparison also confirmed
  conf/conf.php, data/voices, soundcache and uploads unchanged.
- No save/profile/other-mod/game/F4SE-root writes, game control, push or PR.
  VR artifact is built/audited but not deployed or headset-accepted.

Next: user manually loads a save, messages an NPC, cancels, and sends the next
message; correlate new client/server logs. Real five-provider cloning/lifecycle
acceptance, deadline-origin alignment, overlapping cache-owner/status behavior,
remaining full gameplay/persistent AI/management/public API parity, and separate
flat/VR sustained acceptance remain open. Do not equate deployment with parity.


## Client speech deadline checkpoint (2026-09-07)

Client product f1ff7ce bounds speech status/media lock acquisition and HTTP by
the same utterance deadline, rejects late transport success, and rechecks
expiry before playback admission. Cancellation can overtake lock contention
without removing existing context serialization. Server product stays 3b7b62a;
no server code, configuration, schema or runtime changes in this checkpoint.

Client evidence: 17 native suites pass in each flat/VR build, including 539
fake-server checks; protocol 140 cases / 29 schemas; 34 build guards and 11
packaging tests. Both Windows x64 DLLs and audited packages built. See client
parity ledgers for hashes. Not deployed; no game or real-provider acceptance.
Five-provider scope above remains authoritative and the full goal stays active.
Next gates include deadline-origin alignment, old file-job cutover, cache owner
overlap and actual Apache access to private durable inputs before paired deploy.


## Latest checkpoint: supervisor lifecycle and non-inherited speech locks (2026-09-07)

SOURCE + ISOLATED SERVICE VALIDATION / NOT DEPLOYED / FULL GOAL ACTIVE.
Product 3b7b62a fixes verified process-boundary failures before local deployment.

The before1 fixture observed the worker inheriting BOTH the speech dispatcher's
single-instance lock and the parent background-service lock. Killing the
dispatcher delayed stable replacement until 4.314 seconds; stopping the parent
left its manager child alive. These were real /proc descriptor/process observations,
not inference from stale lock files.

Changes:
- Speech dispatcher and both durable/legacy speech cache locks use close-on-exec
  on POSIX, so workers/converters cannot retain their parents' locks. Windows
  retains its existing open mode and still needs separate handle-inheritance proof.
- Shell service lock descriptor 9 is closed in listener, speech supervisor and
  manager children; only the owning top-level supervisor retains it.
- Main manager and idle-sleep children are tracked, stopped and reaped on exit.
  The nested speech supervisor also tracks its dispatcher/retry-sleep child.
  Shutdown has bounded TERM waits and targeted KILL escalation for unresponsive
  owned children. No global process-name killing was introduced.
- start.sh now honors the EXISTING background state-directory helper/override
  for its lock file. Default installed /tmp behavior is unchanged; isolated
  worktrees no longer have to share the production service lock.

Evidence:
- The real start.sh plus real speech dispatcher/worker/converter were exercised
  against the synthetic PostgreSQL fixture. Only the unrelated legacy manager
  executable was replaced with a harmless nine-second stand-in via a PHP shim.
  No actual production service was started/stopped or reconfigured.
- after2: duplicate service start refused, stable dispatcher restart 1.173 s,
  shutdown 0.020 s, converter inherited NO locks, worker held only its own
  media lock, and no manager/dispatcher/supervisor child escaped shutdown.
- Final source final1: paused the exact replacement dispatcher with SIGSTOP to
  model an unresponsive child. Targeted shutdown completed in 1.146 s; restart
  before the pause was 1.167 s. Duplicate start and descriptor assertions passed.
- Active speech worker/converter may finish an already-owned request after
  supervisor shutdown; this is not cancellation of that request. The fixture
  subsequently cancelled its exact origin and removed all owned test processes.
  Teardown confirmed no scratch service/worker/converter processes remained.
- Full PHPUnit: 278 tests / 4363 assertions. Release audit passed 722 tracked
  files, 414 PHP, 152 JSON, 12 Herika-style directories and 3 knowledge checksums.
  LF-normalized actual shell source passed bash syntax and execution checks.

Read-only local deployment preflight, following synth-full-deploy targets:
- Installed Apache request workers and SYNTH supervisor run with effective UID 33
  (www-data), in the same mount namespace mnt:[4026532217]. The existing installed
  SYNTH supervisor was PID 417146. This supports same-user/private-temp access;
  it is not proof that the new private-input flow has run under Apache yet.
- Flat Fallout4.exe file version is 1.11.240.0 at the declared Steam target.
- Installed SYNTH.dll remains SHA256
  CB517DE21A2CB8F27DD859C352439B3E0E404D4C632086340397F87975C20364.
  Source client remains 5acaefb on codex/dialectic-parity-core, clean.

Evidence: /tmp/synth-cancel-pipeline-PmHVx1Rk/data/tmp/supervisor-{before1,after1,
after2,stopped1,final1}.json; database synth_parity_cancel_20260907 only.
Scripts under `<scratch>`:
- synth-supervisor-probe.php SHA256
  89CB4C43CB661176B7262801041F5643FE318DD01CC8F4C99F10975C521503CB
- supervisor-bin/php SHA256
  70E615CBC5DC3C366383781FA0AD208378199CC86B443079C6F1243964CE66C6
- synth-service-runtime-inspect.php SHA256
  2E69734BED96B78A35565CBB274EBAE7552C23B0615BB19498783B4781BAB6A6

Remaining deployment/acceptance gates: old native file-job cutover, exact speech
deadline alignment and client HTTP bounds, overlapping owner/status behavior,
real Apache private-input access, full legacy-manager fork-tree lifecycle and
separate Windows behavior. Cloud cloning/real five-provider acceptance and the
full gameplay/actions/persistent AI/management/public API/paired deployment/
manual flat and VR objective remain active. No game launch/control, deployment,
client modification, publication or PR occurred.

## Previous checkpoint: automatic native speech recovery integrated (2026-09-07)

SOURCE + ISOLATED FULL-MAIN/WORKER/HTTP VALIDATION / NOT DEPLOYED /
FULL GOAL ACTIVE. Product 4456645 wires the durable primitives into normal
owned, portable speech for XTTS, Chatterbox, PocketTTS, Cartesia and Inworld.

Normal deferred admission now persists the frozen job and launches a worker by
numeric job ID, without writing a credential-bearing envelope in the document
root. Pending status is written inside admission's owner-locked transaction
before a dispatcher can claim the job; duplicate admission never downgrades an
already-ready utterance. The worker retains the per-cache OS lock and guarded
media registration, and does not emit terminal failure while a retry is pending.
Ready status metadata is allowlisted; frozen plans/credentials/ingress bodies
must not leak into status files or HTTP responses.

service/tts_dispatch.php runs a single-instance 500 ms due-job loop. start.sh
now starts it under a separate restart loop so slow legacy manager tasks cannot
delay speech recovery. Durable terminal/deadline status is resolved read-only
for HTTP and response encoding; internal job IDs are removed before the wire.
Terminal private inputs older than ten seconds are cleaned only when their
registered hash matches. Changed files are preserved and marked for inspection.

Evidence (all synthetic PostgreSQL/loopback, no real provider calls):
- durable-live1: full-main PocketTTS baseline, converter cancellation, subsequent
  completion and same-session recovery still passed after native integration.
- durable-auto1 (PocketTTS), durable-xtts1 and durable-chatter1: killed only the
  exact scratch worker verified by PID/command/request ID. The running dispatcher
  automatically recovered the SAME job, completed attempt 2 and served exact
  ready-media bytes. Recovery after death: 5.453 / 5.472 / 5.369 seconds.
  Subsequent same-session and separate baseline/recovery inputs passed.
- durable-fail2: both captured PocketTTS voice candidates returned synthetic
  503 on attempt 1. HTTP remained pending during the durable retry; attempt 2
  generated ready audio. Initial fail1 instead exercised the adapter's local
  fallback and completed attempt 1; the fixture assertion was corrected.
- durable-stop1: killing a worker then sending native cancel left the job
  cancelled at attempt 1; no audio/revival, HTTP terminal failure, next input OK.
- durable-all1 on final source: automatic dispatcher plus baseline/model cancel/
  speech cancel/model during save-generation advance/speech during generation
  advance/recovery, with same-session recoveries. All passed. Cancel/load unwind
  measured 183-208 ms. Ready HTTP audio hashes match generated WAVs.
- tts-durable-probe core4: 33 PostgreSQL checks, job IDs 50-58; includes repeat
  admission preserving ready status, attempt/deadline/token/input/owner guards.
- Cleanup snapshot confirmed 42 hash-matching generated fixture inputs removed
  and 3 deliberately changed inputs preserved. No user save, voice sample,
  configuration or production data was deleted.
- Every owned fixture dispatcher/server and speech worker stopped at teardown.
- PHPUnit: 278 tests / 4357 assertions. Release audit: 722 tracked files,
  414 PHP, 152 JSON, 12 Herika-style directories and 3 knowledge checksums.
  LF-normalized start.sh passed bash -n; the real start.sh supervisor restart/
  shutdown lifecycle has NOT yet been executed as an acceptance test.

Evidence root /tmp/synth-cancel-pipeline-PmHVx1Rk/data/tmp; database
synth_parity_cancel_20260907. Probes in `<scratch>`:
- cancel-pipeline-probe.php SHA256
  1F57CCEBBFE7E5EB790524CB34C9B482A8091AF7ABD210A577377E71AB9EEAE6
- cancel-pipeline-provider.php SHA256
  EC3E5632F47AC559C4E8B5F92EFDF09D64180895891042437F77FF00B9DA6350
- tts-durable-probe.php SHA256
  7C7E1AD9046462497237139413A117AE56320CFDF246B5BB6433A9C885435532

Before deployment: verify the actual Apache/admission/worker/supervisor user and
temp namespace (private inputs require same-user access), supervisor lifecycle/
descriptor inheritance, old native job cutover and overlapping owner/status
behavior. Exact server/client deadline alignment and bounded individual client
HTTP waits still need review. Nonportable callbacks remain in process; legacy/
out-of-scope adapters are not silently adopted by durable recovery.
No deployment, client change, game launch/control, publication or PR. Remaining
cloud clone lifecycle, real five-provider acceptance, full gameplay/persistent
AI/management/public APIs, paired deployment and manual flat/VR gates remain.

## Previous checkpoint: durable speech recovery primitives (2026-09-07)

COMPONENT SOURCE + ISOLATED POSTGRESQL VALIDATION / NOT WIRED INTO LIVE
SPEECH / NOT DEPLOYED / FULL GOAL ACTIVE.
Product 7fffed8 adds lib/synth_tts_jobs.php using the existing worker_jobs tts
type and lease columns; no schema migration or protocol/version change.

Implemented primitives:
- Idempotent per-utterance admission under the native session lock. Captured
  inputs must match the original ingress, exact snapshot/actor/speech role,
  portable plan, text/mood/cache seed and one of the five in-scope providers.
- Credential-bearing immutable input files are stored outside the document root
  in a server-root-specific system-temp directory (0700), files 0600. General
  job metadata contains only schema/context sequence, an opaque input ID and
  SHA256. This is private plaintext storage, NOT encryption or credential removal.
- Execution reloads verified canonical ingress and the hash-checked input,
  claims a five-second renewable token, allows at most three attempts within
  a fixed 30-second deadline, and invokes generation outside SQL transactions.
- The existing protocol activity guard checks an explicitly bound TTS lease at
  provider/publication boundaries. Old tokens cannot renew or finish replacements.
  Successful completion requires registered ready media and a still-live lease
  and deadline in the final SQL update.
- Transient failure retains a bounded retry after one second; invalid input,
  owner cancellation, exhausted attempts and expired deadlines are terminal.
  Due selection retires old owners and returns at most five attributable jobs;
  it never adopts unowned legacy job rows.

Evidence:
- tts-durable-probe.php core3: 32 real PostgreSQL checks passed, synthetic job
  IDs 16-24. Covered idempotence/private storage, successful ready-media finish,
  retry, expired-lease takeover, stale-token rejection, immutable-input mismatch,
  deadline retirement, three-attempt cap, replacement-token preservation,
  deadline expiry during execution and owner cancellation.
  Generation callbacks use synthetic WAV bytes through actual guarded cache
  publication and media registration. No real model/TTS services were called.
- Evidence: /tmp/synth-cancel-pipeline-PmHVx1Rk/data/tmp/durable-core3.json.
  Database: synth_parity_cancel_20260907 only. Scratch jobs/private files remain
  isolated; no production database or game state was changed.
- Probe: `<scratch>/tts-durable-probe.php` SHA256
  0DB907A1D32D518F7B6CDAB495621229741F310A85CE53F784941F066F185756.
- Final product validation: PHPUnit 277 tests / 4343 assertions; release audit
  passed 719 tracked files, 411 PHP, 152 JSON, 12 Herika-style runtime directories
  and 3 World Knowledge checksums. Existing-file unit additions check private
  storage boundaries and refusal of inherited provider/dispatcher transactions.

Mandatory next integration work:
These APIs are not yet called by normal speech admission/worker dispatch.
Automatic crash recovery is NOT complete. Wire native five-provider admission
and execution to them; connect a supervised due-job dispatcher that runs within
the speech deadline (the existing manager waits for all other task children).
Preserve the per-cache OS lock, captured inputs and ready-media handoff. Do not
emit terminal speech failure while a durable retry is pending. Verify shared
cache/status behavior across overlapping owners and ensure native/client
deadlines agree. Validate the runtime's admission/worker service-user identity:
private storage currently requires the SAME OS user; mixed-user operation and
Windows ACL behavior have not been proved. Add terminal-input lifecycle cleanup
and explicit legacy-job cutover without adopting old unowned credentials/jobs.

Client unchanged; no deployment, game launch/control, publication or PR.
The five-provider restriction and full gameplay/actions/persistent AI/management/
public API/paired deploy/manual flat plus VR acceptance scope remain unchanged.

## Previous checkpoint: crash-safe speech redispatch (2026-09-07)

SOURCE + ISOLATED VALIDATION / NOT DEPLOYED / FULL GOAL ACTIVE.
Product 4765d7e removes timestamp-file liveness inference from deferred speech.
A fresh .lock file used to suppress dispatch for 60 seconds even after its
worker died. Each dispatch now writes its own random attempt envelope, checks
JSON encoding and the full write, and never overwrites another attempt's input.
The existing per-cache OS-held flock serializes synthesis/publication and is
released by process death. Completed workers remove only their own recognized
attempt file inside the server job directory; paths supplied in job payloads
are never unlinked. Legacy files and crash evidence remain intact.

Measured reproduction and validation:
- Isolated full-main PocketTTS fixture death-before1 killed only the exact
  currently running scratch worker (verified command path and request ID).
  Retrying the same captured job reported admission but produced no audio.
- After the fix, death-after1 successfully redispatched that same owned job.
- death-double1 (PocketTTS), death-xtts1 and death-chatter1 each observed two
  concurrent retry workers, exactly one provider synthesis call, successful
  ready-status/media HTTP reads with exact bytes, removal of completed attempt
  envelopes, and a successful next input in the same session/generation.
  The deliberately killed attempt's evidence file was preserved.
- All fixture servers and remaining workers were confirmed stopped.
- PHPUnit: 275 tests / 4333 assertions. The added small source guard checks
  immutable attempt naming, absence of timestamp liveness, OS cache locking
  and constrained cleanup; the real process fixtures provide behavioral proof.
- Release audit passed: 718 tracked files, 410 PHP, 152 JSON, 12 Herika-style
  directories and 3 World Knowledge checksums. No provider inventory changes.

Evidence remains under /tmp/synth-cancel-pipeline-PmHVx1Rk/data/tmp, database
synth_parity_cancel_20260907 only. Probe scripts in
`<scratch>` now have these SHA256 values:
- cancel-pipeline-probe.php:
  BE398B7150883998C9AE5C79E4AE47EFB86C79D76CFFFE9A7AD38C4405DB3791
- cancel-pipeline-retry.php:
  B8E37DD0B712B11620AEC681CF78A249568969A8B24B936E1EBA92D06C35AB07
- cancel-pipeline-provider.php:
  A0F1FEF5B1A61F7014DB0D029E9B0C566D41B9B5A4F25AAC88863A50FE11B4AC

Limits and next architecture work:
This is safe redispatch, NOT automatic crash recovery. The fixture explicitly
requested the retry. Dead jobs can still leave pending status and an orphaned
envelope; the client source contains a voice deadline/text-only fallback, but
that observation does not prove native runtime timing or bounded individual
HTTP calls. Native durable speech admission/attempt leasing/recovery should use
the existing synth_meta.worker_jobs tts type, with captured input, exact owner
validation, bounded retries/deadline, credential-safe persistence, service
dispatch and explicit legacy-job cutover. Do not interpret this checkpoint as
having implemented that dispatcher or the full worker-recovery gate.

No deployment, client changes, real provider calls, production data changes,
game launch/control or publication. Still in scope: five-provider acceptance,
Cartesia/Inworld clone lifecycle, remaining full gameplay/persistent AI/public
integration parity, safe paired deployment and separate manual flat/VR proof.

## Previous checkpoint: owned speech readiness and read-only polling (2026-09-07)

SOURCE + ISOLATED VALIDATION / NOT DEPLOYED / FULL GOAL ACTIVE.
Product f883bf1 closes the shared generation-to-playback handoff for the five
in-scope providers. No additional provider-specific implementation was added.

Before the fix, a pending cache WAV could be published by a status read, and
cancelled work could register media or replace readiness. Generation now checks
durable ownership before staging immutable media, then registers it and writes
ready status under the native owner lock. Status writes recheck ownership at
atomic rename, use private random temporary files, and never delete the previous
status to recover a failed rename. Deferred workers and inline generation use
this shared handoff. Immediate generation explicitly registers its audio before
advertising readiness instead of relying on a later poll.

Status reads only resolve existing immutable objects; pending stays pending.
The HTTP status endpoint and response encoder verify existing PostgreSQL media
registration rather than creating it. This is not a distributed transaction:
a late cancellation can leave unregistered immutable bytes, and filesystem and
PostgreSQL commit failures are not jointly atomic. Delivery rejects missing or
mismatched registrations; worker-death/recovery remains a separate goal gate.

Evidence:
- PHPUnit: 274 tests / 4327 assertions. Two focused existing-file regressions
  cover read-only polling, missing publication, late cancellation, preserved
  status, private-file cleanup and successful owned registration.
- Isolated PostgreSQL before/after probe: before1 reproduced poll-created media,
  cancelled registration and overwritten status; after1 refused all three.
- Actual full-main/worker loopback fixtures: PocketTTS status-media2,
  XTTS status-xtts1 and Chatterbox status-chatter1 each passed normal completion,
  conversion cancellation, subsequent completion and same-session recovery.
  Successful cases also GET actual status and media endpoints and match audio
  bytes to the generated cache. Cancellation unwound in 192/198/199 ms respectively;
  all owned fixture servers/workers stopped.
- ready-handoff-ready2 exercises the actual immediate handoff and response
  encoder with PostgreSQL, plus actual HTTP endpoints: pending poll creates no
  media; unregistered ready audio remains refused (media HTTP 404); owned
  registration retains request/turn/utterance/generation/actor correlation,
  emits ready speech and serves exact bytes (HTTP 200).
  This is component/HTTP proof, not a full-main buffered-mode test. Protocol v2
  does not permit the legacy top-level streaming override; no schema was relaxed.
- Release audit passed: 718 tracked files, 410 PHP, 152 JSON, 12 Herika-style
  directories and 3 knowledge checksums. Existing adapter inventory is unchanged.
- Evidence root: /tmp/synth-cancel-pipeline-PmHVx1Rk, synthetic database
  synth_parity_cancel_20260907 only. Windows probe directory:
  `<scratch>`. No real provider service calls.
  tts-ready-handoff-probe.php SHA256:
  C519363B4BC718CC17BFF70635DBF5D8D911C202EC17B788AADF9F804E4D89C1.
  tts-status-ownership-probe.php SHA256:
  12EF16953958E3B390DCC207CF476185551398D649774D0039CBB185D7361359.
  cancel-pipeline-probe.php SHA256:
  7B7F22DC9E2914B325E0E90783CDB713E1B22189D94A2F82567E01B7BFE23759.
  Early immediate-probe attempts had fixture-only duplicate-include and wrong
  assertion-field errors; the final one-shot ready2 run passed.

No client changes, deployment, production database edits, game launch/control or
publication. Client remains 5acaefb (product 6c42a7b). Remaining gates include
supersession/recovery, Cartesia/Inworld clone lifecycle and real five-provider
acceptance, full gameplay/persistent AI/integration parity, safe paired deploy,
and separate user-driven flat/VR acceptance.

## Previous checkpoint: all speech-adapter cache paths guarded (2026-09-07)

SOURCE + ISOLATED VALIDATION / NOT DEPLOYED / FULL GOAL ACTIVE.
Product 6a82e8e migrates the two remaining cache paths. All 17 registered adapters
now use the shared guarded publication path; this is NOT full provider acceptance.

Inworld no longer writes a public _o.pcm intermediate. Its generator returns
decoded bytes; the shared converter accepts only typed s16le input (integer
8000-192000 Hz, one/two channels, complete frames; no arbitrary flags) and keeps
Inworld's established pcm_s16le/22050 Hz/mono output. The chunk decoder rejects
mixed rates, invalid base64/WAV/type, odd PCM and oversized decoded audio rather
than publishing partial/corrupt speech. Tests exercise the extracted decoder.

xVASynth uses a fresh private job directory for each synthesis. Its Windows UNC
output path is derived from the actual server root and validated distro name,
not a hardcoded public soundcache path. Windows-hosted PHP uses its local path.
Failed model load stops the request. Only a valid, bounded WAV from that exact
private path reaches cancellable conversion. Finally removes the owned file and
directory; a late writer cannot satisfy another request. Existing shared-model
load/synthesize concurrency and external backend cancellation are NOT solved.

Evidence:
- Existing regression suite: 272 tests / 4296 assertions, exit0. Two small methods
  in ResponseRuntimeRegressionTest cover PCM format validation and Inworld chunk
  corruption/rate consistency. No new repository test file.
- Release audit passes: 718 tracked, 410 PHP, 152 JSON, 12 runtime directories,
  three catalog hashes; 17 TTS / 7 STT / 5 ITT. Diff check passes. Adapter scan
  finds the shared publisher/converter in all 17 files and no remaining shell_exec,
  public _o.pcm or part.wav conversion path in tts PHP sources.
- Only /tmp/synth-cancel-pipeline-PmHVx1Rk and synthetic
  synth_parity_cancel_20260907 used. No production DB/provider/service change.
- xVASynth full main.php HTTP/PostgreSQL/actual worker/FFmpeg against a synthetic
  provider: adapter4-xva-http passes six baseline/cancel/generation/recovery
  scenarios plus four same-session recoveries. Model cancellation 183ms;
  speech cancellation/generation interruption 105-212ms. Late provider writes
  explicitly return false after their private directory is removed. Final
  adapter4-xva-final confirms baseline, conversion cancel (211ms), recovery and
  same-session recovery against the final formatted source.
- xvasynth-private-probe private2: ten real-adapter cases, eight distinct output
  paths. Default/custom conversion byte-matches old FFmpeg; missing/invalid
  output, synthesis failure and model-load failure return false, preserve an
  existing final WAV and never consume/modify a deliberately planted old public
  _o.wav fixture. Private output directories removed. HTTP intercepted; curl off.
- Inworld pcm2: actual adapter/generator/decoder with synthetic cURL API, run
  php -n without the cURL extension and with HTTP/HTTPS wrappers unregistered.
  Six valid raw/RIFF+metadata at 22050/24000 Hz and default/custom-filter cases
  byte-match reference conversion. Six malformed/mixed/error cases return false
  and preserve existing audio. This is not native HTTP or real Inworld acceptance.
- Real-SQL pcm3 boundary: raw-input converter timeout 200ms returns in212ms,
  exact child PID1796045 gone; cancellation just before actual owner lock
  prevents replacement and restores private-file state.
- Evidence in scratch data/tmp: adapter4-xva-{http,final}-cancel-evidence.json,
  xva-probe-private2/evidence.json, inworld-pcm2/evidence.json,
  speech-cache-boundary-pcm3-evidence.json and late external-output markers.
  Four fixture ports clear, exact worker scan empty, no private XVA dirs remain.

Scratch scripts under `<scratch>`, SHA256:
- inworld-pcm-probe.php:
  F73C06F857A59DA7299F4C8251B0D6E6B24D3B8F08E8A3243BD054FB578440C2
- xvasynth-private-probe.php:
  DA189C6461412C0209CDC2837FE368BC9D175C9D9D54F23B6667304A6812BDE3
- cancel-pipeline-probe.php:
  1D6D27A044C81C647820A5729E75D75039C5EBE71EECBC40CA64793E93C10921
- cancel-pipeline-provider.php:
  EB650F1E189494EFB73F01ED78011D96E54EF91A87085ED9402DB7BB4D98753B
- speech-cache-boundary-probe.php:
  DE284590AE73055455A5DF1EDF724EE7DAE9A330CD84B1B617C16FA21A5FECC4

Next gates: media-object registration/status publication ownership, new-message
supersession, worker death/recovery and legacy-job cutover, complete speaker
settings isolation, negotiated action catalog, cloud lifecycle and Zonos full
upload contract. External OmniVoice atomic language/voice changes still await
authority. xVASynth actual Windows UNC permissions/provider model concurrency,
late providers that recreate deleted dirs, and killed-worker private-file
reclamation need independent coverage. Installed client fc2a484/server 0ade54e
remain behind source; no deployment/game control/push/PR/version bump.
Original gameplay/actions, persistent AI, management/public API parity, paired
deployment and separate user-run flat/VR acceptance remain required.

## Latest checkpoint: direct WAV, Azure and OmniVoice cache ownership (2026-09-07)

SOURCE + ISOLATED HTTP/SQL/CODEC VERIFIED / NOT DEPLOYED / FULL GOAL ACTIVE.
Product 34073d5 adds validated direct-WAV publication without re-encoding.
KoboldCpp and the current Mimic3 adapter use private temporary files and the same
native owner-locked final rename as converted speech. KoboldCpp no longer trims
binary data. Invalid/empty/truncated audio cannot replace an existing cache.
The common final publication boundary is extracted without moving provider or
converter work inside the PostgreSQL lock. Finally removes each owned temporary.

Azure and SYNTH's OmniVoice adapter now use private cancellable conversion;
Azure loudness normalization, custom filters and delay remain unchanged.
OmniVoice returns failure for failed conversion rather than merely logging it.
Legacy ttsMimicOld also uses the converter and its existing timer variable is
corrected. The unused OmniVoice shell-command builder is removed. External
OmniVoice service code, runtime, global language API and fallback contract are
NOT changed; their atomic language/voice behavior still needs separate work.

Evidence:
- Existing regression suite: 270 tests / 4280 assertions, exit0. One focused
  method in the existing ResponseRuntimeRegressionTest verifies a WAV ending in
  zero bytes, invalid/truncated bytes, late owner cancellation, cache preservation
  and temporary cleanup. No new repository test file.
- Release audit passes: 718 tracked, 410 PHP, 152 JSON, 12 runtime directories,
  three catalog hashes; 17 TTS / 7 STT / 5 ITT. Diff check passes.
- Only existing scratch /tmp/synth-cancel-pipeline-PmHVx1Rk and synthetic database
  synth_parity_cancel_20260907 changed. Actual main.php, PostgreSQL, spawned
  workers and local fixture model/voice services: KoboldCpp and Mimic3 each pass
  baseline, model cancel, speech cancel, model during generation advance, speech
  during generation advance, recovery, plus same-session recovery after all four
  interruptions. Cancellation observed 113-240ms. No stale WAV/live worker.
  OmniVoice passes baseline, conversion cancellation (216ms), recovery and
  same-session recovery using real FFmpeg and a synthetic provider.
- Persisted plans confirm eight jobs each for KoboldCpp/Mimic3 and four for
  OmniVoice (model-cancelled requests produce no speech job). JSON evidence:
  data/tmp/adapter3-{koboldcpp,mimic3,omni}-cancel-evidence.json.
- Real-SQL publication-boundary probes direct2 and extracted2 both pass. Durable
  cancellation immediately before the actual owner lock prevents direct WAV and
  converter output replacement. Both also recheck converter timeout: 200ms budget,
  211ms observed, exact converter process gone, existing cache/temporary state
  preserved. Evidence: data/tmp/speech-cache-boundary-{direct2,extracted2}-evidence.json.
- Extended codec fixture: 24 cases covering prior four cloud adapters plus
  Azure, OmniVoice, direct KoboldCpp/Mimic3 and legacy Mimic3. Direct WAVs match
  input bytes exactly; converted WAVs byte-match old FFmpeg output for WAV/MP3
  inputs and applicable custom/default filters. Every case rejects invalid audio
  without replacing the cache or leaving public/private intermediates.
  HTTPS intercepted, HTTP unregistered, curl disabled. No actual provider call.
  Evidence: data/tmp/codec-adapter-codec3/evidence.json. Initial codec2 stopped on
  an incorrect one-call fixture expectation: OmniVoice also calls active_language.
  Corrected fixture expects both existing calls; failed artifacts retained.
- Fixture server ports 18971/18972/18973/18976 clear and exact worker scan empty.

Scratch script SHA256, `<scratch>`:
- adapter-conversion-probe.php:
  FBC8A70DD8B9ABBA85A8BB275600ED5682A0397227FF993A533304821C40F5AE
- cancel-pipeline-probe.php:
  B6E0C1BCB301DC3B002708A48280151566D9BE158EBA2E01D5536C4AC0475699
- cancel-pipeline-provider.php:
  70B771DC6120FB1C71EDC53A0D6A19C604C748E2629DB65E7780FC18724F7771
- speech-cache-boundary-probe.php:
  6811AB02877CDF926BF3CF44DB0FF3996334464F7F81EE205AB9A7E45361F1F3

Remaining: Inworld raw-PCM conversion and xVASynth external-file output. Fifteen
of seventeen adapters now use guarded cache publication, NOT full provider
acceptance. Zonos full upload path, cloud lifecycle, actual OmniVoice service
atomicity, media registration/status publication, supersession, worker recovery,
complete speaker settings isolation and legacy-job cutover remain open.
No client change, deployment, game/editor control, production DB/provider/service
change, push/PR/version bump. Installed client fc2a484/server 0ade54e unchanged.
Full gameplay/actions, persistent AI, management/public API parity, safe paired
deployment and independent manual flat/VR acceptance remain required.

## Latest checkpoint: ten more cancellation-aware speech adapters (2026-09-07)

SOURCE + ISOLATED ADAPTER/HTTP EVIDENCE / NOT DEPLOYED / FULL GOAL ACTIVE.
The preceding turn was a status check. Product 42f189d completes the pending
conversion migration for XTTS, Chatterbox, Piper, Kokoro, MeloTTS, Cartesia,
Zonos Gradio, OpenAI, Deepgram and ElevenLabs. These now share PocketTTS's
private bounded conversion and owner-locked atomic cache publication. Existing
filters and delay remain intact. OpenAI/ElevenLabs no longer trim binary input.
The shared helper defaults to the established FFMPEG_PATH/ShareX/PATH resolver.
This is 11 of 17 adapters using the converter, not 11 fully accepted providers.

Evidence:
- Final existing regression suite: 269 tests / 4269 assertions, exit0. The existing
  converter-failure regression now exercises the default executable resolver too
  and restores FFMPEG_PATH afterward. No new repository test file.
- Release audit passed: 718 tracked files, 410 PHP, 152 JSON, 12 runtime folders,
  three catalog checksums; 17 TTS / 7 STT / 5 ITT unchanged. Diff check passed.
- Existing isolated /tmp/synth-cancel-pipeline-PmHVx1Rk and synthetic database
  synth_parity_cancel_20260907 only. Copied 11 changed product files from source.
  Full main.php HTTP, real PostgreSQL, actual spawned speech workers and actual
  FFmpeg: XTTS, Chatterbox, Piper, Kokoro and MeloTTS each pass baseline,
  conversion cancellation, recovery, and same-session recovery after cancellation.
  Observed cancellation 182-213ms; no cancelled WAV and no surviving worker.
  Persisted jobs confirm four plans for each of the five selected drivers.
- Evidence JSON: data/tmp/adapters-xtts-cancel-evidence.json and
  data/tmp/adapter2-{chatterbox,piper-tts,kokoro,melotts}-cancel-evidence.json.
  Chatterbox's first fixture attempt lacked its /tts_to_audio/ trailing-slash
  route and failed; corrected fixture normalizes the slash. Failed artifacts kept.
- Ten real-adapter codec cases: OpenAI, Deepgram, ElevenLabs and Cartesia each
  accept synthetic WAV and MP3; Cartesia also exercises custom volume plus delay.
  Outputs byte-match reference FFmpeg using each adapter's previous audio settings.
  Every case rejects invalid bytes, preserves an existing cache and leaves no
  public MP3/_o.wav or private conversion temporary files. HTTPS is intercepted,
  HTTP unregistered and curl initialization/execution disabled: no cloud call.
  Evidence: data/tmp/codec-adapter-codec1/evidence.json. This is unclaimed adapter
  conversion evidence, not cloud/native HTTP ownership or cloning acceptance.
- Zonos conversion reviewed/linted/shared-helper tested, but its complete upload
  path was NOT run: it still uses a hardcoded production voice directory. No
  fixture was written there. Its full provider/voice-plan contract remains open.
- All four fixture ports 18971/18972/18973/18976 clear; owned servers stopped.

Scratch scripts in `<scratch>`, SHA256:
- adapter-conversion-probe.php:
  9B114E3EB25157C6DA84860577359192C7C85045880FDB7A73984692ED36173C
- cancel-pipeline-probe.php:
  C6EE2AC249AD4668B6E54C4B965781C6B01BE89B3B7E3DCA2E3395C338FCCD04
- cancel-pipeline-provider.php:
  23079CA9D4E4641C4208A9BCF2A676928E117FDB6F37C2AAEB543D513AD99BEA

Remaining converter paths: OmniVoice, Inworld (raw format options), xVASynth,
Azure, Mimic3 and KoboldCpp (direct WAV). Media registration/status publication,
message supersession, worker death/recovery, complete player/narrator settings
isolation, legacy-job cutover and native action-catalog exposure remain gates.
OmniVoice external service changes still await authorization; SYNTH adapter work
can continue independently. No client change/deploy, game/editor control, live
provider call, production database mutation, push, PR or version bump. Installed
client fc2a484/server 0ade54e remain behind source. Original gameplay/actions,
persistent AI, management, public API, paired deployment and independent manual
flat/VR acceptance remain in scope; full goal is not complete.

## Full-main cancellation and PocketTTS conversion publication (2026-09-07)

SOURCE + ISOLATED HTTP/SQL/WORKER VERIFIED / PARTIAL: `fe63cb5` fixes an ordinary
NPC prompt reader's empty-context regression (real HTTP500 before the model).
It also replaces PocketTTS's blocking shell conversion/public-cache writes with
bounded cancellation-aware direct execution, private temporary files, WAV
validation and atomic cache rename under the native owner lock.

Fresh current-source HTTP pipeline with real PostgreSQL and spawned speech workers
passes baseline, model/voice cancellation, generation advance during model/voice,
and same-session recovery. Conversion-stage cancellation initially reproduced a
stale WAV; fixed run cancels in180ms without publishing it. Final model/voice
control observations are182-236ms. A real-SQL late-cancel publication probe and
212ms timeout/child-exit/cache-preservation check pass. Model/voice providers and
delay launcher are fixtures; FFmpeg itself is real. No game/audibility proof.

Full regression269/4268 and release audit718/410 PHP/152 JSON/12 directories pass.
Full artifacts, hashes, initial failures and coverage limits are in the goal ledger.
Fixture servers/workers stopped; production/client/runtime untouched, no deploy.

OPEN: other-provider conversion/publication, atomic media/status ownership,
new-message supersession, worker restart/legacy-job cutover, player/narrator voice
isolation, action catalog capability exposure, cloud lifecycle and separately
authorized OmniVoice service work. Paired rollout and all original feature/API/
manual flat/VR acceptance gates remain required.

## Native speech roles and reserved-name routing (2026-09-07)

SOURCE ONLY / PARTIAL: `c5664cd` separates ordinary native NPC ownership from
Player/Narrator display names, preserves action-result responder identity, and
captures explicit speech roles in buffered lines and new queued speech plans.
Player synthesis/split narration restore their temporary roles; listener metadata
cannot impersonate a player producer. NPC fallback/pronunciation/lazy voice paths
now check the speech role. Owned rechat preparation remains mandatory and a
temporary lane cannot override its selected role. Native action targets resolve
independently through unambiguous captured identities.

Full regression268/4251, focused role2/164, player9/32, NPC fallback8/32 and release
audit717/409 PHP/152 JSON/12 directories pass. Role probes include actual buffered
NDJSON output, a JSON speech-plan roundtrip and actual player processor include
with injected failure; database calls are doubles, not fresh durable SQL proof.
No real provider, deployment or game test. Full evidence and initial corrected
player fixture are in the goal ledger.

OPEN: remaining name-based consumers, full player/narrator connector isolation,
roleless legacy-job cutover, complete-main durable cancellation/recovery, cloud
repair/concurrency/management, separately authorized OmniVoice service changes,
paired rollout and every original feature/API/manual flat/VR acceptance gate.

## OmniVoice caller isolation and external service prerequisite (2026-09-07)

SOURCE ONLY / PARTIAL: `76c8e03` captures Omni-specific hooks for process-local
queued execution, restores caller hooks and removes planned guessed fallback
voices while retaining the shared captured NPC fallback and legacy behavior.
Focused2/70, full266/4087, player8/21 and release audit717/409 PHP/152 JSON pass.

OPEN EXTERNAL PREREQUISITE: inspected component and installed service ignore
request language in favor of shared active-language state and silently substitute
missing voices. Exact Omni sample/language ownership needs an opt-in atomic backend
contract, not a SYNTH-only lock/preflight check. Separate OmniVoice repository
authorization is required before modifying that service. Exact source hashes,
paths and runtime lines are recorded in the goal ledger. No backend changes,
deployment or game control occurred; full goal remains active with other in-scope
role/cancellation/recovery and feature/API/flat/VR work still available.

## Remote cloud voice pinning and lookup bounds (2026-09-07)

SOURCE ONLY / PARTIAL: `1df8742` captures remote-only Cartesia/Inworld IDs or
lookup failures before enqueue. Cache hits avoid HTTP; uncached names are resolved
with the captured credentials. Serialized IDs participate in output-cache identity;
workers cannot re-resolve names or let Inworld failure mutate newer live aliases.
Capture shares a ten-second lookup budget and voice-list readers reject repeated
page tokens. Complete-main latency, native cancellation timing and live cloud
acceptance are not proven by intercepted transport tests.

Final regression265/4051; remote-focused1/39; real synthetic SQL26 checks/6 queued
ID cases and repeated sample SQL57 checks pass with verified rollback. No cloud
service or production DB writes. Exact fixtures/limits are in the goal ledger.
Release audit717/409 PHP/152 JSON/12 directories passes.

Client/runtime unchanged. Cloud loss/reclone/concurrency/management, OmniVoice,
exact roles/player capture, durable full-main cancellation/recovery, legacy job
cutover, paired rollout and the full original feature/API/flat/VR gates remain.

## Cloud local-sample capture and clone identity (2026-09-07)

SOURCE ONLY / PARTIAL: `967dfb5` captures Cartesia/Inworld primary/fallback WAVs
and selected voice inputs. Planned sample clones/cache metadata are keyed by
sample and language within the credential/workspace scope. Old named aliases
cannot satisfy replacement local samples; late local imports are not cloned;
worker-time NPC lookup is skipped. Legacy unplanned behavior/cache rows remain.

Final regression264/4012, focused cloud2/84; synthetic real SQL + intercepted
HTTPS57 checks/8 clone requests/14 intercepted calls, rollback verified. Existing
credential-scope SQL35 checks passes again. Release audit717/409 PHP/152 JSON/12
directories passes. No real cloud request, TTS output, deployment or game test.

Remote-name alias pinning remains OPEN, as do remote loss/reclone, concurrency,
versioned-clone management/retention and current service API/version acceptance.
OmniVoice, exact roles/player capture, durable cancellation/recovery, legacy job
cutover, paired rollout and the full original feature/API/flat/VR gates remain.
Exact artifacts, official reference links and evidence limits are in the goal ledger.

## XTTS/Chatterbox queued sample ownership (2026-09-07)

SOURCE ONLY / PARTIAL: `ff746f0` captures primary/fallback WAV bytes before enqueue
and sends versioned uploads through the selected XTTS/Chatterbox adapter. A
replacement cannot alter an already captured local sample. Unknown voices,
provider mismatch, missing/tampered snapshots and upload failures fail closed;
missing local paths cannot become late backend references. Unplanned requests
and named backend presets retain selection behavior, not remote preset immutability.

Real isolated adapter HTTP/FFmpeg proof: 8 generations, 8 uploads, 26 requests,
two backend-loss repairs, three distinct expected audio outputs per provider,
caller restoration. Final regression263/3993; NPC fallback8/32; player8/21;
release audit717/409 PHP/152 JSON/12 directories passes. Goal ledger records exact
scratch artifacts and small post-HTTP regression-only refinements.

Not deployed or game-tested. Cloud/OmniVoice capture, exact roles/player handling,
durable cancellation/recovery, legacy job cutover, paired rollout and the full
original feature/API and flat/VR acceptance requirements remain open.

## Cloud connector capture and remote voice-cache isolation (2026-09-07)

SOURCE ONLY / PARTIAL: `2e31e88` binds Cartesia/Inworld queued configuration to
captured credentials, connector and workspace without mutable DB rehydration.
Persistent/process-local voice caches use the same credential-scoped v2 identity;
old cache rows remain intact but are not reused. Missing captured settings stay
missing and provider mismatch fails closed. Legacy unplanned lookup is unchanged.

Focused coverage 1/65; full regression 262/3954. A real PostgreSQL synthetic
35-check transaction proves account/workspace and cache isolation across in-place
credential changes, caller restoration and verified rollback. No cloud provider
or production DB call. Release audit717/409 PHP/152 JSON/12 directories passes.
Installed folder layout and health were reverified; no migration/deploy needed.

Client/runtime unchanged. Immutable cloud sample handoff, service/game acceptance,
other provider/player capture, durable cancellation/recovery, paired rollout and
the full original parity goal remain open. Exact evidence is in the goal ledger.

## Planned retry policy and cancellation boundary (2026-09-07)

SOURCE ONLY / PARTIAL: `0b01138` keeps planned PocketTTS/Chatterbox/XTTS retries
within captured primary/configured-fallback choices. PocketTTS cannot discover
another endpoint or accept an uncaptured audio.cpp voice inside a frozen plan.
Legacy unplanned calls are unchanged. Nine synthetic HTTP/transport cases prove
three valid fallback WAVs, three no-fallback failures without audio, and three
simulated owner cancellations before retry. No real DB/native/game cancellation
acceptance is claimed. Final regression261/3889 and release audit717/409 PHP/152
JSON/12 directories/3 checksums pass. Exact artifacts and post-HTTP audio.cpp
unit-test boundary are recorded in the goal ledger.

Installed runtime/client unchanged. Other provider sample handoff, rehydration,
exact roles/player capture, durable worker cancellation/recovery, paired deploy,
and separate manual flat/VR acceptance remain open under the unchanged full goal.

## Multi-provider process isolation and Azure initialization (2026-09-07)

SOURCE ONLY / PARTIAL: `2041483` removes the confirmed clone-adapter function
collisions, keeps selected globals unchanged while loading all 17 adapters, and
repairs Azure's missing token-cache dependency using documented direct resource-key
authentication. Azure/Mimic3 defer legacy bootstrap until invocation and preserve
frozen plans. Azure request construction is intercepted locally, not service-tested.

Six real clone-adapter load orders and 24 local HTTP/FFmpeg generations pass with
24 distinct caches, valid expected WAVs, correct request/provider/voice/language
and caller-state restoration. Final regression 259/3854; audit717/409 PHP/152 JSON,
12 canonical directories and3 checksums pass. Client and installed runtimes unchanged.
Remaining voice gates include implicit endpoint/codename fallback, immutable samples
for other providers, dynamic rehydration, exact roles/player capture, cancellation
and paired rollout. Full original feature parity and separate flat/VR acceptance
are still outstanding. Exact receipts and limits are in the goal ledger.

## Frozen queued speech settings and PocketTTS samples (2026-09-07)

SOURCE ONLY / PARTIAL: `aad0c98`, `26083dc` carry effective per-line speech
settings through immediate/deferred generation, snapshot PocketTTS primary and
fallback samples before enqueue, fingerprint configuration/text/sample inputs,
and restore caller state after exceptions. Player helpers retain their separate
path; other adapters retain their existing sample-aware key behavior but still
need immutable sample handoff. Private job JSON has source-level HTTP denial.
Driver registration fixes last-included adapter reuse; actual PocketTTS plus
Chatterbox inclusion still exposes pre-existing global function collisions.

Full-main synthetic HTTP held a live worker while changing its profile endpoint
and local sample, then proved the original voice, exact NPC/utterance route and
valid downloaded audio. Final regression 256/3827, player 8/21, NPC fallback 8/32,
audit 717 tracked /409 PHP /152 JSON /12 directories /3 checksums pass. See goal
ledger for the post-HTTP cache refinement evidence boundary and exact receipts.
No live deployment or game acceptance; full parity remains active. Next gate is
real multi-provider isolation, remaining sample adapters, role/player ownership,
cancellation/recovery and safe paired rollout, followed by manual flat/VR proof.

## Sample versions and backend sample-loss recovery (2026-09-07)

SOURCE ONLY: `555c672` uses hash-based PocketTTS clone IDs and validated immutable
upload snapshots. Runtime preparation verifies backend presence instead of trusting
old success/failure receipts. Synthetic full-main HTTP proves same-name sample
replacement changes audio for identical text, and backend sample loss causes
reupload before new synthesis. Seven requests complete; three pending-to-ready
workers preserve exact NPC/utterance routes and valid WAV hashes. Final PHP
254/3807, voice checks 8/32 and release audit pass. Source Apache/dev-router rules
protect snapshot files; live Apache rule acceptance remains undeployed. HTTP ran
before final helper extraction; final helper/router regressions pass. Queue-to-worker
voice/connector pinning, in-flight races, player independence and other backend/
manual flat/VR gates remain open. No live deploy or game changes; full goal ACTIVE.
Detailed retained evidence and limits: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Fresh PocketTTS clone provisioning and failed-attempt retry (2026-09-07)

SOURCE ONLY: `157ffe2` uploads existing local clone samples to the selected Python
PocketTTS endpoint before generation, and makes clone network I/O cancellation-aware.
A planned streaming retry publishes pending before emission instead of inheriting
an old failed status. Strict synthetic backend rejects synthesis without exact
sample upload; actual main/worker/status/media path passes after both repairs.
Seven HTTP requests complete, repaired retry transitions pending to ready, exact
NPC/utterance routing and 31,278-byte WAV hash pass. PHP 252/3796, voice tests 8/32
and release audit pass. No game/audio playback or live deploy. Sample replacement,
backend restart, player independence, backend variants and in-flight cancellation
remain open; fresh provisioning does not prove those cases. Full goal ACTIVE;
details and retained synthetic evidence in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Enabled voice uses voice-aware cache identity in the real pipeline (2026-09-07)

SOURCE ONLY: `067b5d9` removes the main pipeline's raw-text cache fallback by
requiring the existing v5 helper. Different selected voices/drivers and replaced
samples cannot silently keep that text-only key. Actual main.php plus deferred
worker, FFmpeg, status and media GET pass in a disposable runtime/database with
fake loopback model/TTS endpoints. Five requests complete, exact NPC/utterance
ownership survives, and downloaded 31,278-byte WAV matches advertised SHA256.
PHP 251/3777, existing voice tests 8/32 and release audit pass. Test processes
stopped; scratch state retained. This proves backend transport/media, not heard
speech or clone provisioning: no upload occurred because the fixture accepted a
known voice ID. Next gate requires upload-before-synthesis, independent player
controls, failures/cancellation and real provider/manual flat/VR acceptance.
No live deployment or game changes; full goal ACTIVE. Details in goal ledger.

## Disabled voice has no pending-speech obligation (2026-09-07)

SOURCE ONLY: `d08f480` freezes explicit NPC voice-off at buffer time, removes audio
cache metadata and makes wire emission respect text-only even after a profile
switch. Player voice selection remains independent through existing producer
markers; reserved NPC names do not change lanes. PHP 250/3771, focused final
three-test run 3/252, audit 716/408 PHP/152 JSON/12 dirs/3 hashes pass. Five real
HTTP requests in synthetic session pipeline-ttsnone-20260907 complete; both NPC
replies contain text without speech, replay is byte-identical, and only two fake
model calls occur. Exact utterance route/history proof survives; delivery remains
emitted, not heard. No live deploy or game changes. Enabled voice/clone routing,
owned follow-ups/actions/cancellation, safe paired cutover and manual flat/VR
acceptance remain open. Full goal ACTIVE; details: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Complete-main HTTP baseline and bootstrap repairs (2026-09-07)

SOURCE ONLY:d71e42e fixes the schema007 bootstrap gate, checksum-sensitive LF
checkout rules/manifest snapshot digest, actual release checksum audit, and the
lowercase dialogue suffix produced by the real pipeline. Fresh factory bootstrap,
repeat bootstrap and006-to007 gate pass in synth_parity_pipeline_20260907.
Five real loopback HTTP requests (init, two contexts, two text requests) complete;
two real connector calls reach only the fake local model. Generated utterance
routes and one frozen history speech proof pass. Delivery remains emitted, not
heard. PHP248/3533 and audit716/408 PHP/152 JSON/12 dirs/3 hashes pass. Test servers
stopped; synthetic DB retained. Confirmed next defect: TTS-disabled output still
advertises pending speech. Enabled voice, owned follow-ups, actions/cancellation,
configured provider and manual flat/VR acceptance remain open. No live deploy or
game changes. Full goal ACTIVE; details/evidence: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Completed utterance proof in history formatting (2026-09-07)

SOURCE ONLY:30fc00f makes native history consume exact completed wire-line,
utterance, canonical source and captured-identity evidence within its unchanged
frozen visibility scope. Same-name NPC speakers and explicit narrator roles stay
distinct. A proven referenced NPC named The Narrator can be the listener without
being hidden as private narrator dialogue; missing evidence restores filtering.
Internal markers are removed before provider output. PHP248/3532, SQL/actual
history pipeline478, audit716/408 PHP/152 JSON/12 directories pass; fixture rollback
verified. Input/prechat provenance, action-result/full-main transformations,
provider/TTS/voice, stress, safe cutover and manual flat/VR remain open. No deploy
or game proof. Client capability disabled; full goal ACTIVE. Detailed limits and
evidence: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Exact utterance-to-route correlation (2026-09-07)

SOURCE ONLY:9d942d9/schema20260907007 preserves the utterance ID on native wire
routes for both text-only and voiced dialogue. IDs are validated, immutable on
replay and unique within their request; conflicting wire/metadata IDs are rejected.
Old routes remain NULL. PHP247/3526, SQL/emitter/history/main-block459 and audit
715/407 PHP/152 JSON/12 directories pass, including populated migration replay
and fixture rollback. History consumption is still open: matching completed-body,
line-hash and frozen-context proof must precede any speaker/listener role grant.
No visibility widening, provider/game evidence or deployment. Client capability
disabled; full goal ACTIVE. Exact evidence: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Prepared dialogue and action emission boundary (2026-09-07)

SOURCE ONLY:0b8b95d rechecks owned dialogue's prepared speaker at route persistence
and binds final wire output to the claimed owner. Action creation/emission enforce
the saved opt-out, selected actor, captured target, canonical capability, existing
wire schema and live matching persisted intent. Targets resolve separately from
speaker authorization; unique other captured NPCs work, ambiguous names do not.
PHP246/3509, PostgreSQL/emission/history/main-block428, audit715/407 PHP/152 JSON/12
directories and final helper lint pass. Synthetic fixture/DDL rollback verified.
No deployment/provider/game proof; client capability remains disabled. Exact
root/action history speaker/listener provenance, narrator action-actor dispatch,
complete-main/provider/TTS, concurrency, safe cutover and manual flat/VR acceptance
remain open. Full goal ACTIVE; evidence and probe hash: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Prepared historical speech-role formatting (2026-09-07)

SOURCE ONLY:9f5ff8b carries completed prepared rechat speaker-role evidence
through native history formatting. A real NPC named The Narrator retains NPC
speech; an explicit narrator retains narrator speech. Filtering happens once,
then internal evidence is stripped from provider-ready messages. Frozen scope,
visibility and cutoff are unchanged; ordinary recall does not add the role join.
PHP245/3480, PostgreSQL/actual-history/main-block274, audit714/406 PHP/152 JSON/12
directories pass, with fixture/DDL rollback. No deploy/provider/game proof.
Exact listener and root/action speech provenance, direct emission, complete-main,
concurrency, provider/TTS and manual flat/VR acceptance remain open. Ambiguous
narrator-addressed suffixes stay conservatively filtered. Client capability
disabled; full goal ACTIVE. Exact evidence: the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Prepared current-response role decisions (2026-09-07)

SOURCE ONLY:90d9e3d makes owned-rechat profile, prompt name, history-reader/memory,
voice-refresh and narrator action-mode decisions use the prepared NPC/narrator
role. Actual main final-profile binding preserves a named NPC's canonical token;
quoted context and preselection actor names are not globally renamed. Explicit
narrator aliases resolve only with a proven narrator role. Expanded PHP244/3472,
PostgreSQL/actual-main-block257 and release audit714/406 PHP/152 JSON/12 directories
pass. Fixtures/DDL rolled back; no deployment or full-main/provider/game proof.
Historical row-level string classification/filtering, root routes/direct emitters,
full-main acceptance, concurrency and manual flat/VR still remain. Client
capability disabled; full goal ACTIVE. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for evidence.

## Verified current-chain prompt history (2026-09-07)

SOURCE ONLY:1a94c75 appends bounded quoted current-chain context after frozen
history compaction. It follows exact prepared parents and proven action-result
edges, checks canonical complete responses/routes/selected speakers, and includes
accepted typed input or captured transcription at the proven root. Sibling and
unrelated chains are excluded; historical unknown inputs are not fabricated.
No wider history cutoff or durable memory write. PHP221/3342, current PostgreSQL
and actual-main-block probe240, audit714/406 PHP/152 JSON/12 directories pass.
Fixture/DDL rollback verified. No provider/full-main/game proof or deployment;
client capability remains disabled. Downstream role/name substitutions, direct
emitter/action audit, full-main/concurrency/deep-chain and provider/TTS acceptance,
safe deployment and manual flat/VR proof remain open. Full goal ACTIVE; exact
evidence and limits are in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Originating text and voice transcript capture (2026-09-07)

SOURCE ONLY:16eabbf saves canonical typed input or the server-derived transcript
with the owned origin policy, without changing wire-event authority. Retries
cannot overwrite it; invalid, forged and late captures are refused. Schema
20260907006 adds nullable input_text, preserving historical unknowns and policy
rows. PHP220/3340 and PostgreSQL/actual-main-capture210 checks pass; populated
migration replay and fixture rollback verified. Release audit713/405 PHP/152
JSON/12 directories passes. No deploy, game or provider/TTS execution. The
current-chain prompt reader is still missing; this capture supplies its root
input, not complete conversation history or durable memory. Explicit role,
full-main, concurrency, deployment and manual flat/VR acceptance remain open.
See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for exact evidence. Full goal remains ACTIVE.

## Prepared trigger and round dispatch (2026-09-07)

SOURCE ONLY / CLIENT DISABLED:76d3daf calls atomic preparation at owned rechat
admission, binds the selected NPC/narrator role and acknowledges terminal choices.
Main uses persisted round/role instead of legacy selection, temp-file budgets,
eventlog waits or narrator rerolls. The server staging guard is removed; a valid
claim and persisted evidence are required. PHP219/3324 and PostgreSQL/actual
main-blocks199 checks pass; audit713 tracked/405 PHP/152 JSON/12 directories.
Fixtures/DDL rolled back, no deployment or game/provider execution. Plugin still
does not advertise the capability. Current-chain utterances, downstream routing
and a bounded full-main connector run remain required before deployment; full
manual flat/VR and broader parity acceptance remain open. Exact evidence is in
the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Canonical prepared profile loading (2026-09-07)

SOURCE ONLY / DISABLED:22bcb9a connects main's profile stage to the prepared
NPC/narrator role, exact profile, connector/TTS config and captured label.
Manual voice references and player input settings are preserved. Owned narrator
rotation writes narrator state, not an NPC ID, and loader failure rolls it back.
PHP219/3324; real PostgreSQL/main-block/profile-class175 checks and release audit
713 tracked/405 PHP/152 JSON/12 directories pass. Fixtures/DDL rolled back.
No provider/TTS generation, full-main execution or game proof; no deployment.
Trigger preparation, legacy selector/budget bypass and downstream integration
remain open. Readiness guard and client capability are still disabled; see
the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for the exact evidence and remaining acceptance gates.

## Original dialogue policy capture (2026-09-07)

SOURCE ONLY: 00dafa4/schema20260907005 persist the originating request's private
mode and pre-profile server defaults from live main. Preparation now loads that
completed origin policy; it cannot borrow a later request's mode/settings. Missing
policy is refused, and capture failure returns a structured terminal response.
PHP218/3322; PostgreSQL/main-capture-block/emitter154 checks; audit712 tracked,
404 PHP,152 JSON,12 directories pass. Fixtures/DDL rolled back; no deployment.
Main selection/profile/connector/voice cutover and full-main/provider/game proof
remain open. Readiness guard and client capability are still disabled. Exact
evidence and all remaining gates are in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Prepared response ownership readers (2026-09-07)

SOURCE ONLY / DISABLED: 5d5c7a3 and schema 20260907004 add the atomic preparation
receipt and require it in shared rechat actor/speaker/profile-facade/history
resolution. Same-name NPCs and reserved display names use the selected canonical
identity; terminal/narrator roles do not inherit target identity/history. Live
profile rebinding and unprepared selections are refused. PHP 217/3297 and
PostgreSQL/emission 146 checks pass; fixtures and DDL rolled back. Release audit
711 tracked /403 PHP /152 JSON /12 directories passes. No deployment or game
execution. Main profile/connector/voice loading and the coordinated dispatcher
cutover are still open; readiness guard and client capability remain disabled.
See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for exact probe evidence and remaining gates.

## Atomic selected-profile preparation (2026-09-07)

PARTIAL SOURCE: 9d0e33c coordinates exact candidate/profile policy, reservation,
selection, provenance and live-binding validation. PHP 216/3293; SQL/emitter/
prepare 106 checks; release/protocol audits pass. No deployment or client build.
Live entry and response/profile/voice/history readers are not integrated yet.
Capability remains disabled; full parity active. See goal ledger for evidence.

## Canonical rechat selection (2026-09-07)

PARTIAL SOURCE: 67a91d5 / schema 20260907003 persists exact NPC/profile or
narrator-role choices, eligibility/terminal outcomes and replay stability.
PHP 215/3283; SQL/emitter/selection 89 checks; release/protocol audits pass.
The live dispatcher and profile/voice/history readers do not consume the
selection yet. First-chain selected-profile policy derivation must precede
budget rolling in the coordinated cutover. Capability disabled; no deployment
or gameplay proof. Full parity active; see the goal ledger for exact evidence.

## Rechat action-chain inheritance (2026-09-07)

PARTIAL SOURCE: 37ba129 verifies completed action ancestry and inherits the
original input/rechat budget. Duplicate receipt aliases cannot reset exhaustion.
PHP 214/3270; SQL/emitter/receipt 88 checks; release and mirrored protocol pass.
No schema bump, client build or deployment. Selected responder, dispatcher,
profile/voice, concurrent races and main/provider/gameplay remain unproven.
Capability disabled; full parity active. Details in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Durable continuation admission (2026-09-07)

PARTIAL SOURCE: product 41a47ff, schema 20260907002. One child per completed
parent response; persistent chain mode/budget; round/replay/private/exhaustion
state; savepoint-safe failed admission. PHP 213/3260 and real SQL/emitter
72 checks pass, fixtures rolled back. Release audit 709/401 PHP/152 JSON.
See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for exact evidence and scratch probe hash.
Dispatcher, selected actor/profile policy, action ancestry, narrator execution,
concurrent races and main/provider/TTS/gameplay are NOT proven.
Capability remains disabled; no deployment. Full parity remains active.

## Latest checkpoint: durable emitted routes and completed-parent proof (2026-09-07)

SOURCE ONLY / NOT ENABLED. Server product 52ff3ff adds response_routes and
schema 20260907001. The real native v2 turn-owned emitter records each exact
wire-line hash, captured speaker and addressed/unavailable/ambiguous listener
under the bounded native request lock, independently of relationship settings.
Same-name labels remain ambiguous; captured reference metadata can disambiguate.
Conflicting line replay and late capture after completion are rejected.

The completed-parent reader proves canonical parent and child ingress, exact
session/turn/generation/context/runtime, completed response framing, every
dialogue line hash and the requested last NPC speaker. Pending prefixes,
missing routes, altered bodies, failed/cancelled parents and forged ownership
fail closed. It is evidence resolution, NOT one-child admission or selection.
The active dispatcher still raises owned_rechat_not_ready.

Validation:
- PHP 212 tests / 3189 assertions.
- Real emitter/completion SQL probe: 45 checks, including duplicate-name
  listeners, unavailable/ambiguous routes, Narrator exclusion, VR, body
  corruption, replay, stale turn/generation and caller transaction preservation.
  Source additive route migration applied twice within the rolled-back probe.
- Existing canonical profile/action SQL probe: 91 checks, rolled back.
- Protocol 29 schemas / 140 fixtures / 157 mirrored files, unchanged.
- Release audit 708 tracked files / 400 PHP / 152 JSON / 12 canonical roots.
- Diff checks clean. No client code, DLL build, provider call or deployment.

Probe: `<scratch>/rechat-route-owned-sql-probe.php`
SHA256: A338677A6B702F8B0F954A05289C46CFF39E3A932C3DED84B138961530967708.
Only scratch database synth_parity_context_20260904 was used; fixtures rolled back.
Installed flat DLL hash remains
CB517DE21A2CB8F27DD859C352439B3E0E404D4C632086340397F87975C20364.

Next: durable per-parent one-child admission and chain decisions preserving
all configured modes/budgets/private/creature/narrator controls; align the
last eligible speaker with client suppression/cooldown behavior; select the
canonical responder before profile/voice/history loading and add owned chain
prompt evidence. Audit long request-derived line IDs before enablement:
wire IDs can exceed the new parent's 128-character bound.
Explicit server readiness, full main/provider/TTS, populated combined migration,
concurrency and user-run flat/independent VR acceptance remain open.
Full parity remains ACTIVE. No game control, push, PR, release or version bump.

## Latest checkpoint: staged rechat parent contract (2026-09-07)

IMPLEMENTED FOUNDATION / DISABLED / NOT DEPLOYED. Client product 6c42a7b and
server product 5c6471b carry and strictly validate the exact parent request/line
pair. The client retains parent and original ownership in one pending bundle.
Mirrored v2 schemas and golden fixtures cover flat, VR and invalid contracts.
The plugin does not advertise dialogue.rechat.ownership; the server rejects
that staged path with owned_rechat_not_ready before legacy fallback.
No durable parent completion, route selection or working continuation is
claimed. Earlier defect-detection checkpoints remain historical evidence.

Validation completed for these product commits:
- Both flat and VR DLL builds; all 17 native suites and VR CTest passed.
- Fake-client checks: 510. Build gates: 34 tests. Packaging: 11 tests.
- Protocol: 29 schemas, 140 fixtures, 157 mirrored peer files.
- PHP: 211 tests / 3170 assertions.
- Server release audit: 707 tracked files / 399 PHP / 152 JSON /
  12 canonical Herika-style runtime directories.
- Real scratch SQL: 91 profile/action ownership checks and 17 legacy rechat
  defect-detection checks. Synthetic fixtures rolled back.
- Both internal flat/VR validation ZIPs passed manifest and lane audits.

Artifact SHA256:
- Built flat DLL: BE428041A03033AC34E7F92A3876D30E7075FDE11C24D3D81541B31D449954A9
- Built VR DLL: D2E3C298800730871B1D130982300F4257B7B14B321BC14A1D13A3808091658F
- Internal flat ZIP: 29AE4CD28B94EEAF3F4C887FCD9687EACF78B02D18029607ACD4E3AC2C7BB467
- Internal VR ZIP: DB740D71AB2F34837C52F187962D60886D1DD58DCB141EB1B1771801617E8F62
- Installed flat DLL unchanged:
  CB517DE21A2CB8F27DD859C352439B3E0E404D4C632086340397F87975C20364

Installed products remain client fc2a484 and server 0ade54e (schema
20260906005). Canonical profile schema 20260906006 remains source-only.
No deployment, provider call, game control, push, PR or release occurred.

Next: independent canonical emitted listener routes, exact completed-parent
admission, durable one-child/replay/budget decisions, selection before profile
loading, owned chain prompt/history evidence and full dispatcher integration.
Keep the staging guard until these coupled gates pass. Full parity remains
ACTIVE; flat manual gameplay and independent VR headset acceptance remain open.
The separate Herika/Lorkhan folder-alignment request remains complete.

## Native rechat ownership contract (2026-09-07)

REPRODUCED / NOT FIXED: real SQL and actual trigger/parser functions pass
17 defect-detection checks: lost speaker/audience/identity, stale profile-name
fallback, rejection of the non-target last speaker and shared name-budget keys
across sessions/turns/generations/runtime lanes. All synthetic rows rolled back.
Client already has request/line IDs but does not send them in rechat; emitted
response bodies omit listener routing. Optional relationship capture is not
an unconditional route source.

NATIVE-RECHAT-OWNERSHIP.md defines the coordinated parent contract, durable
routes/decisions/budget, selected profile/speech/history and chain-prompt gates.
No product fix/deployment this turn; source action milestone d9fd4cd remains.
Installed server 0ade54e and client fc2a484 unchanged. Full goal stays active.

## Action reply ownership across profile, speech and history (2026-09-06)

PARTIAL SOURCE / NOT DEPLOYED: server d9fd4cd derives the response actor from
the exact persisted action and captured identity, sharing that ownership with
profile readers/writers, speech and the saved history reader. Early native
funcret binding now loads that actor's profile. Exact target speech works with
same-name audience actors; ambiguous non-owner names remain rejected.
Missing action provenance cannot fall back to the original target.

PHP 210/3135; real-SQL profile probe 91, cooldown 58, ancestry 42; inventory
binder 512 stacks/1024 units; audit 697/399/143 and protocol 29/131/148 pass.
Synthetic fixtures rolled back. No full main/provider/voice/game proof.
Rechat's server-selected responder, complete profile workers, management/TTS
callers, browser/upgrade/concurrency and end-to-end gates remain before deploy.
Runtime server 0ade54e/schema20260906005 and client fc2a484 unchanged.

## Canonical profile writer boundaries (2026-09-06)

PARTIAL SOURCE / NOT DEPLOYED: server b9f2cc5 fences ID-based profile writes
to freshly read canonical ownership under the native request lock. Stale
caller rows cannot replace protected tokens/settings; partial updates retain
native profile slots. Voice/metadata/delete/backup writers share the guard.
Legacy bulk history no longer touches native rows; per-row history inserts
retain npc_id without the nonexistent actor_identity_id history column.

PHP 210/3134, real-SQL owned-profile probe 66 checks, release audit 697/399/143
with 12 canonical directories, and protocol 29/131/148 pass. SQL fixtures
rolled back. Token-collision, wrong-owner, legacy and cancelled-generation
checks are included, not full provider/worker/browser/game proof.
Installed server 0ade54e/schema20260906005 and client fc2a484 unchanged.
Remaining integration/deployment gates are in NATIVE-NPC-PROFILE-OWNERSHIP.md.
Full goal remains active; separate Herika/Lorkhan folder placement is complete.

## Canonical NPC profile storage and queued ownership (2026-09-06)

PARTIAL SOURCE / NOT DEPLOYED: server 330f8ff separates native actor-keyed
profiles from unbound legacy name rows. Captured actor metadata, stable
native row/tokens, rename/manual voice preservation, scoped reads/writes
and two-same-name durable profile job transfer are implemented. Schema
20260906006 is source-only. Existing linked rows are preserved, not reassigned
or retroactively repaired by guessing historical ownership.

PHP 210/3122, real-SQL owned-profile probe 37 checks, release audit 697/399/143,
and unchanged protocol 29/131/148 pass. Management identity labels have syntax
proof only; Claude Opus 5 authentication expired and Codex applied the bounded
fallback. Full main/voice/worker completion, remaining name/token/ID callers,
token collisions, browser/upgrade/concurrency/cancellation gates remain before
deployment. See server docs/NATIVE-NPC-PROFILE-OWNERSHIP.md for exact next work.
Installed server 0ade54e and client fc2a484 unchanged. Full goal remains active.

## Native NPC profile identity cutover (2026-09-06)

REPRODUCED / NOT FIXED. Real SQL using the actual input binding block confirms
same-name and cross-playthrough profile reassignment, private personality/
voice/metadata inheritance, original actor link loss, and rename collision.
Source and installed diagnostic: 21 defect-detection checks each; all fixture
rows/DDL rolled back. This is not evidence of a fixed system or freeze cause.

The server's docs/NATIVE-NPC-PROFILE-OWNERSHIP.md now defines the required
coordinated storage/runtime/worker/import/management cutover and preservation/
acceptance gates. No product changes or deployment this checkpoint. Deployed
server 0ade54e and client fc2a484 remain unchanged. Full goal stays active.

## Saved native action cooldowns (2026-09-06)

IMPLEMENTED / SERVER-ONLY DEPLOYED: 0ade54e records native action issuance
atomically with its intent, using the captured game clock and empty witness
audience. Cooldowns read exact actor/save history plus only the current owned
turn's later issuance, not legacy NPC-name/wildcard logs or a stale name cache.
Save reload excludes post-frontier future actions; same-name actors remain
separate. Native issuance no longer commits a caller's transaction. V1 behavior,
durations and clock conversion remain. Old ambiguous logs are not backfilled.
Schema 20260906005 adds one partial lookup index; no deletion/protocol change.

PHP 209/3110, source and installed real-SQL cooldown/writer probes 58 checks,
installed follow-up 42 checks, audit and protocol 29/131/148 pass. Old installed
code fails the unrelated wildcard test. Installed hashes/index/schema/HTTP200
verified; config/14 voices/client unchanged. No provider/game or latency proof.
Full pipeline/profile ownership, model execution, broader roadmap and manual
flat/VR acceptance remain open; see the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Owned native action follow-up depth (2026-09-06)

IMPLEMENTED / SERVER-ONLY DEPLOYED: 7abe237 replaces native v2's latest legacy
action-log depth with exact persisted action/request ancestry. Session, turn,
generation, snapshot, runtime lane, action/idempotency and first action code
stay bound across parents. Mixed action codes cannot reset the one-step limit.
Cycles/unknown parents disable extra tools without dropping textual results;
existing settings and legacy v1 behavior remain. No schema/protocol changes.

PHP 207/3088, source and installed SQL/catalog/processor probe 42 checks,
installed tool-pair 27 checks and full 512-row inventory regression pass.
Old installed code fails the native depth check against conflicting legacy
wildcard entries. Audit and protocol 29/131/148 pass. Server-only deploy,
backup, installed hashes and HTTP200 verified; config/14 voices/client unchanged.
No model/game proof. Other legacy cooldown/profile name readers, full request
composition, context budgets, broader roadmap and manual flat/VR acceptance
remain open; see the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Real SQL history-memory-provider integration (2026-09-06)

SYNTHETIC SOURCE AND INSTALLED INTEGRATION VERIFIED: deployed server 832c2f3
passes production PostgreSQL event writes, owned memory batch/job completion,
history-window/summary selection and OpenRouter native HTTP/JSON parsing
together. Current and synthetic loaded-context generation each pass plain and
compact history: 25 events, exact floor 1075, one strictly older summary,
two identical replies retaining their listener, no hidden/aborted/future/mutable
log contamination. Wrong reader/lane reads are empty; retirement cancels the
stale provider request. Four positive HTTP cases per root, eight total.
Synthetic rows and session-local audit table are verified rolled back; fixture
listener stopped/socket closed. No product change or redeploy; HTTP200 and
server/client hashes reverified. Actual context ingress/main pipeline, real
models, maximum context sizes and gameplay are not proved by this fixture.
Broader roadmap and manual flat/VR acceptance remain open. Details and scratch
reproduction paths are in the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Native history window fidelity (2026-09-06)

LOCALLY DEPLOYED / SQL-PROVIDER AND GAME ACCEPTANCE OPEN: server 832c2f3 stops
upstream native history from collapsing equal-text utterances, merging replies
across listeners or dropping source timestamps. Native event boundaries reach
the configured history window and optional compact formatter intact; internal
timestamp metadata is removed afterward. Existing legacy grouping remains.
No retention change, database deletion or ownership-SQL change.
PHP 206/3036 plus compact-history 8/90, release audit and protocol 29/131/148 pass.
Source/installed actual builder/window/formatter fixtures cover limits
1/25/150/200 with exact row counts, listener text and memory floors. SQL rows
are mocked; short fixture timing is not a database/model/game benchmark.
Runtime PHP hash/schema/HTTP200 verified; config/14 voices and client fc2a484
unchanged. Actual SQL-to-provider integration, model context budgets, broader
roadmap and manual flat/VR gameplay acceptance remain open.

## Dialogue history and JSON prefill (2026-09-06)

LOCALLY DEPLOYED / REAL MODEL AND GAME ACCEPTANCE OPEN: server 42bc1a2 keeps
native plain replies separate with exact listeners/order across five JSON
connectors. Active dialogue JSON/prefill encoding handles quoted names/text,
backslashes, newlines and Unicode; structured/compact history stays intact.
OpenRouter no longer erases its first chunk when prefill is enabled; OpenAI
and Groq now seed their parser prefix. Five Kobold JSON templates use safe
history encoding; ChatML-C no longer drops assistant lines.
PHP 206/3036, release audit and protocol 29/131/148 pass. Eight five-connector
and 76 expanded Kobold fake-provider HTTP cases pass against both source and
installed runtime. Native cURL/response parsing is real; ownership/audit DB is
a fixture, not a model/context-size or gameplay proof.
Thirteen runtime hashes and HTTP200 verified; config/14 voice files and client
fc2a484 unchanged. No game launched or publication. Long-history/context,
owned continuation, broader roadmap and manual flat/VR acceptance remain open.

## Native Kobold template action evidence (2026-09-06)

LOCALLY DEPLOYED / REAL MODEL AND GAME ACCEPTANCE OPEN: server f9e345f preserves
native action/result evidence across all 18 shipped Kobold templates using
ID-paired narrated context. Full result/custom return wording survives null or
empty assistant content and final-tool histories; input history is immutable.
Duplicate/unpaired tool evidence is rejected. Alpaca examples/prefill encode
quoted names safely. Background chat path and native DLL are unchanged.
PHP 205/3018, audit and protocol 29/131/148 pass. 76 source plus 76 installed
fake-provider HTTP/cURL/stream-parser cases retain all 512 rows and exact text;
old installed llama3 failed the negative control. Ownership DB reads are mocked,
not real-model/context-budget or gameplay proof. Four runtime hashes and
HTTP200 verified; config/14 voices preserved. No game launched.
Ordinary assistant-history formatting, real model/context acceptance, broader
roadmap and manual flat/VR gates remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Connector action JSON and result preservation (2026-09-06)

LOCALLY DEPLOYED / PROVIDER AND GAME ACCEPTANCE PENDING: server aa2404c fixes
five JSON connectors (OpenRouter, OpenAI-compatible, Google-compatible, Groq,
Player2). Action history/audit use safe shared JSON encoding with current-action
listener data. Native v2 results survive custom return templates without
#RESULT#; legacy template semantics remain unchanged. PHP 204/2994, release
audit, protocol 29/131/148 and 10 source plus 10 installed serialization cases
pass. Each outgoing fixture retains the entire 512-row result and exact quoted
action values (38,178-38,421 bytes). Transport is mocked: no actual provider
conversation or maximum-context proof. Six deployed hashes/health200 verified;
config/14 voices and client fc2a484 unchanged. No game launched.
Template-based connectors, real provider/context budgets, broader roadmap and
manual flat/VR acceptance remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Owned action/tool follow-up pairing (2026-09-06)

LOCALLY DEPLOYED / PROVIDER AND GAME ACCEPTANCE PENDING: server f43adaf uses
stable session/generation/turn/context/action-owned native tool IDs, never the
shared last-call file. Assistant/tool IDs match; owner mismatches fail closed.
Arguments are JSON encoded, and CheckInventory reconstructs its saved item
filter rather than the NPC name. Legacy shared-file behavior is preserved.
PHP 203/2977, actual processor 27 checks (full 512 rows, interleaved owners,
retry, escaped values, mismatch, legacy), real SQL/binder, audit and protocol
29/131/148 pass. Installed PHP hashes and health200 verified; config/14 voices
and client fc2a484 unchanged. No game or real provider conversation invoked.
Connector serialization, provider budgets/conversations and manual gameplay/VR
acceptance remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Full captured inventory action follow-up (2026-09-06)

LOCALLY DEPLOYED / PROVIDER AND GAME ACCEPTANCE PENDING: server 6c9b95d expands
successful CheckInventory results from the issued action's exact snapshot and
canonical actor. Saved literal name/FormID filter (or all captured rows), separate
stacks and observed quantity totals are retained through 512 rows. Partial means
lower bound; unavailable is not zero. Failed/cancelled actions remain failures.
No native/wire change: this replaces the 1 KB client preview only inside the
owned server follow-up. Normal prompt and bystander restrictions remain.
PHP 202/2959, real PostgreSQL/binder with later conflicting snapshot, release
audit and protocol 29/131/148 pass. Installed PHP hashes/health200 verified;
config/14 voices and client fc2a484 unchanged. Fixtures rolled back; no game or
provider conversation invoked. Actual action choice/follow-up, large provider
context limits, arbitrary actor/category queries and gameplay/VR acceptance
remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Turn-owned inventory query selection (2026-09-06)

LOCALLY DEPLOYED / GAME ACCEPTANCE PENDING: server 4b7b1d0 selects up to 12
query-relevant principal rows from the same captured inventory (maximum 512).
Typed text and completed voice transcripts use the same exact-context binder.
Stable ranking preserves separate stacks/counts and capture order for ties.
Actor/playthrough and equipped-only bystander boundaries remain; omitted rows
do not imply absence. Native v2 legacy biography inventory is suppressed.
PHP 200/2910 plus inventory 5/11, release audit and unchanged protocol 29/131/148
pass. Actual helper->encoder->installed PHP selects row 512 intact; the old
installed implementation failed the same probe. Runtime PHP hashes and health
200 verified; config/14 voices preserved. Client fc2a484 unchanged; no game launch.
Broad/category/count questions, more than 12 relevant rows, capture limits and
flat/VR gameplay acceptance remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Bounded native extended inventory (2026-09-06)

LOCALLY DEPLOYED / GAME ACCEPTANCE PENDING: flat capture512 rows,1024 entries,64 stacks/item; shared500us
structure and2ms metadata work-admission deadlines. One inventory try-lease;
metadata/sorting outside it; failed/incomplete observations remain truthful.
No cross-frame pointer cache or mixed-frame inventory assembly. VR remains32 rows.
Both DLLs/all17 suites, actual helper183415 checks in MSVC/GCCASAN/UBSAN,
old-helper33-row negative control and512-distinct-form capture->encoder->installed
PHP probe passed. Static34/packaging11 and protocol29/131/148 pass.
Clientfc2a484 deployed to SYNTH_dev with verified DLL/INI/PEX hashes and prior-build
backup; server runtime317f212 unchanged, health200. Both audited packages passed.
Game/editor process count0 before/after; nothing launched. Real native game timing
and save/load/message/headset proof remain unverified.
The principal prompt still exposes only12 rows: relevance and broader inventory
coverage remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for limits and evidence.

## Extended inventory transport foundation (2026-09-06)

LOCALLY DEPLOYED / GAME ACCEPTANCE PENDING: v2 flat `context.inventory_512` and generation-owned init
acknowledgement support512 rows per actor; legacy and VR fall back to32, explicitly
partial when trimmed. The worker observes the actual1MiB encoded-byte budget without
altering immutable captures. Server ingress requires the initialized capability.
Both DLLs/all17 suites, fake-server500 with GCCASAN/UBSAN, PHP198/2855 and protocol
29 schemas/131 fixture cases/148 mirrored files pass. Native capture is STILL32;
prompt relevance/full inventory coverage and actual game acceptance are NOT complete.
Client5a1a1e9/server317f212 deployed; hashes, health200, config/voice/INI/PEX preservation
and runtime ingress/ACK probe verified. Large encoding no longer holds the readiness
state lock; synthetic worst-readiness delay215045us before versus46us after.
No game/editor launched. Full evidence and next steps:
the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Inventory stack correctness (2026-09-06)

BUILT / NOT DEPLOYED: client 055ca63 keeps each stack's count, equipped state,
name, weight and value together. Different stacks sharing a base form no longer
borrow the first stack's metadata. Source bounds and try-lock scope remain;
equipped stacks have priority before the 32-row wire cap. Server 674f774 verifies
strict ingress/principal preservation and equipped-only bystander selection.
Both DLLs/all 17 suites, 2915 instrumented checks per compiler with GCC sanitizers,
old-helper negative control, real capture-to-PHP prompt probe, PHP 197/2821 and
protocol/package audits pass. Installed actor-host test build is unchanged.
Full flat-player inventory and dirty/reconcile delivery remain unimplemented;
the frozen Dialectic equip callbacks request inventory refresh, not generic AI
reactions. See the paired goal ledger for the evidence and next boundary.

## Flat actor host deployment (2026-09-06)

LOCALLY DEPLOYED / GAME ACCEPTANCE PENDING: client e247632 and server ed9fa08
connect exact guarded native capture, negotiated delivery and owned source history.
Fresh event-only captures, nonblocking exact actor resolution and immediate load
epochs protect scene ownership. Pip-Boy capture is separate from paused HTTP.
Both builds/all 17 suites, adapter 369, PHP 197/2816, 22 database checks,
protocol 28/129/145 and audited packages pass. Server schema 20260906004 and
HTTP 200 are verified; no game events received yet. Full deployment preserved
config/voices/job state and fixed dangerous operational-state/Git copy exclusions.
No in-game freeze, audible response or observer coexistence proof is implied.
Grounded reactions, NPC witnesses, complete inventory/action coverage and separate
VR bindings/acceptance remain open. See the paired goal ledger for exact artifacts.

## Negotiated actor-event delivery (2026-09-06)

SOURCE VALIDATED / HOST INACTIVE: explicit actor-support ACK, four-capture queue,
ordered fragments within the 16-state limit, immutable retry ownership, exact
retained actor resolution and PluginSession background delivery are implemented.
Neither proximity nor later visibility establishes witnesses. Actor contexts
require context_accepted before advancing; stale/ambiguous completions preserve
the correct fragment. Flat/VR builds and all 17 suites, context 418, fake-server
435, PHP 197/2815 and real queued C++-to-PHP binding pass. Protocol 28/129/145.
The host still uses the false default availability gate. No native observer
activation, local deployment or in-game acceptance is claimed. Broader gameplay,
inventory, reactions, NPC knowledge and separate VR runtime parity remain open.

## Actor-event contract and source history (2026-09-06)

SOURCE VALIDATED / INACTIVE: typed v2 actor batches, strict ingress and transactional
raw source retention now accompany timestamped scene binding. Only player self-
equipment becomes owned history. Raw NPC evidence is excluded from prompt
snapshots and is not witnessed memory. Strict retry comparison, conflict rollback,
save-frontier and active-generation isolation pass real PostgreSQL probes.
Both DLLs/all 17 native suites, context 287, PHP 197/2807, 22 database checks and
real C++-to-PHP binding pass. Protocol 28/125/143 remains mirrored; v1 unchanged.
No deployment or native activation. Negotiation, bounded delivery, exact actor
selection, gameplay reactions, inventory refresh, NPC knowledge and independent
flat/VR gameplay acceptance remain open; see the paired goal checkpoint.

## Timestamped actor-event scene binding (2026-09-06)

SOURCE VALIDATED / INACTIVE: client 59f1188 adds original callback timestamps,
immutable owned-scene binding and bounded nonblocking base-form metadata capture.
Session/generation/native epoch, player/playthrough, advancing capture frontier,
original two-second age and exact metadata scene are checked. Source evidence is
separate from player knowledge; nearby or later-visible NPCs are not event witnesses.
Missing secondary identity does not erase a nonzero native killer ID.
Both builds/all 17 suites, context 275, observer probes 170 per compiler, metadata
probes 20 per compiler, eight-million-attempt timestamped stress, static 34,
packaging 11 and protocol 27/116/133 pass. No host activation or deployment.
Negotiated delivery/history, inventory refresh, grounded reactions, NPC knowledge,
broader death sources, independent VR bindings and full manual acceptance remain.

## Guarded actor-event observer foundation (2026-09-06)

SOURCE VALIDATED / INACTIVE / NOT DEPLOYED: client e7392d1 compiles exact-flat
death/equipment/policy observers and a bounded deferred-payload mailbox. Original
native forwarding, nested order, contended completion, exceptional unwind and
load invalidation pass actual-header probes on MSVC/GCC (168 checks each).
Eight million pressure attempts are accounted; no torn payloads or stranded queue.
Both builds/all 17 suites pass (lifecycle 489), static 34, packaging 11 and protocol
27/116/133. The host never installs/arms/drains or advertises these observers.
Typed delivery/history, canonical identity, player-knowledge eligibility and
grounded reactions are still required before activation. Non-actor secondary
death sources and independent VR native bindings remain unresolved. No runtime
deployment or game acceptance claim; full parity remains open.

## Actor-event scalar identity foundation (2026-09-06)

FOUNDATION ONLY / NOT ACTIVE: client74a4ba1 adds an engine-free callback-scoped
identity collector using numeric results from existing native handle-policy calls.
Exact1.11.240 binary audit identifies policy slot7 and plain actor handle encoding;
instance/non-actor/invalid handles are rejected. New lifecycle coverage checks
nested callbacks, exception unwind, load epoch, conflicts and thread isolation.
Both builds/all17 suites pass (lifecycle428). DLL hashes are unchanged.
Death/equipment observers, typed delivery/history, knowledge eligibility and
activation are still required. No new deployment or gameplay claim this turn.


## Acknowledged player reactions (2026-09-06)

IMPLEMENTED / PAIRED LOCAL DEPLOYMENT / GAME ACCEPTANCE OPEN: client b75b45f and
server f74d8d0 connect scalar event history ACKs to fresh one-shot level-up/combat-end
responses. Explicit flat-only negotiation, independent bounded queue, original-age
expiry, current active speaker/player checks, original history/frontier ownership and
cross-context duplicate suppression are enforced. Native combat cues cannot assume
defeated enemies or witnesses. Existing RPG settings/chance/cooldown remain authoritative.
Both DLL builds/all17 suites, PHP196/2749, source+installed SQL51 plus cleanup,
quest25 and combat20, protocol27/116/133, static34 and package11 checks pass.
Server HTTP200 and schema20260906003 verified; flat installed hash and preservation
evidence are in the goal ledger. No game was launched. Manual flat and independent
VR gameplay gates remain open; this is not full feature parity.


## Native player-event delivery/history (2026-09-06)

SOURCE VALIDATED / NOT DEPLOYED: client 7849223 and server fe63680 connect
negotiated scalar events to fresh-context delivery and immutable owned history.
Strict ACK/serial receipts, retry identity, transactional duplicate/conflict handling,
save frontiers and no invented NPC hearing are covered by native/PHP/SQL checks.
Both builds/all17 suites, PHP195/2681, SQL23 plus cleanup, protocol27/107/124,
static33 and package audits pass. Schema20260906003 is prepared but not live.
Automatic reactions remain unfinished; sampled combat-end must not inherit legacy
defeated-enemy prompt assumptions. No in-game/VR proof or deployment is claimed.

## Native player transition capture (2026-09-06)

CAPTURE ONLY / NOT DEPLOYED: client 31af694 adds one-second scalar level/combat
sampling and a bounded 16-item FIFO independent of response work. Quiet baseline,
ordered immutable evidence, exact ACK identity, overflow and discontinuity handling
are covered by 154 context checks and 32 static gates. Both builds/all 17 suites and
audited packages pass. The queue has no network consumer yet; negotiated transport,
owned history/deduplication and AI reaction dispatch remain mandatory before deploying
this stage. Installed runtime remains the preceding combat-cancellation fix.
VR capture activation and all game acceptance remain open. See the goal ledger.

## Combat-entry lifecycle checkpoint (2026-09-06)

IMPLEMENTED / FLAT LOCAL DEPLOYED / GAME ACCEPTANCE OPEN: client 2d55b61
cancels only the current dialogue on combat entry, independently of combat dialogue
enablement. It preserves the session, active NPCs and observations; manual Hard Halt
is unchanged. Flat/VR builds and 17 suites per lane, fake-server 365, static 31,
packaging 11, protocol 26/98/114 and source/installed SQL cancellation 20 plus cleanup
pass. See the goal ledger for artifact hashes and manual tests. Combat-end/level-up
delivery is not yet implemented; this is not full gameplay parity.

## Previous regression-gate checkpoint (2026-09-06)

The three stale static failures reported by the quest-reaction checkpoint are now
repaired in the client test suite. All30 build gates pass and13 in-memory safety
mutations are rejected. Native flat17/VR17 suites, packaging11 and both archive
audits pass; no runtime behavior or installed artifacts changed. See the paired
goal ledger for exact scope, Claude Opus5 chatbox review and remaining acceptance.

## Owned automatic quest reactions (2026-09-06)

IMPLEMENTED / PAIRED LOCAL DEPLOYED / GAME ACCEPTANCE OPEN: v2 flat
negotiated reactions compare fresh owned tracked objectives, select an active eligible
NPC, and use the existing cancellable dialogue pipeline. Server rechecks both journals,
actor ownership/availability and duplicate admission before existing RPG policy/chance
and atomic cooldown. No new settings or schema migration. See QUEST-REACTIONS.md.
Both DLLs/all17 suites, PHP194/2630, protocol26/98/114, SQL25, history33, events27
and real PHP handshake11 pass. VR reaction activation remains disabled.

## Quest tracking evidence (2026-09-06, superseded by reaction checkpoint)

PAIRED LOCAL DEPLOYED / GAME ACCEPTANCE OPEN / REACTIONS INCOMPLETE: negotiated v2 flat
quest tracking now distinguishes true, false and unavailable throughout capture,
context, immutable history, prompt context and read_quests. Legacy/older-server
fallback and VR omission remain truthful. Both DLL builds/all17 suites,
PHP192/2580, protocol26/90/106, SQL33 plus native quest-events27 and init7 pass.
See the goal ledger for paired deployment and the remaining reaction dispatch.


## RPG reaction cooldown isolation (2026-09-06)

IMPLEMENTED / SERVER DEPLOYED / QUEST DISPATCH STILL OPEN: the existing RPG pipeline now claims its
60-second cooldown atomically. Native v2 scopes it to exact active context owner
(session/generation/playthrough/player); legacy/v1 retains its shared key. Query
failure or missing native context cannot allow a reaction. PHPUnit191/2542,
release audit and disposable SQL26 checks pass, including12 synchronized claims
with exactly1 winner against both source and installed code; HTTP200 and exact
installed hashes verified. No new schema, settings or wire surface. See the goal
ledger for remaining selected-quest, speaker-selection and dispatch work.

## Herika-style server file layout (2026-09-06)

VERIFIED / AUDIT GUARDED: already matches the feature-folder organization from
LorkhanServer layout commit 82fc0ab. Existing CI audit now rejects duplicate
legacy roots and requires canonical folders/files. Twelve installed folders,
thirteen exact source/runtime file comparisons and HTTP 200 verified. No code
move or runtime redeploy required. See SERVER-FILE-LAYOUT.md; this is not a
claim of complete cross-product feature parity or gameplay acceptance.

## Negotiated flat quest capture (2026-09-06)

IMPLEMENTED / GAME ACCEPTANCE OPEN: exact1.11.240 scalar hooks are now connected
through guarded game-pump capture to owned history delivery, only after explicit
server init support. Older-server dialogue continues with capture off. Load/session
epoch checks, bounded drain/backpressure and separate health counters are wired.
Both builds/all17 suites, PHP189/2526, observer74 on MSVC/GCC, protocol25/85/100 and
packages pass. Actual in-game callback delivery/save-load stability and quest AI
reactions remain unproven/incomplete. See the paired goal ledger for deployment.

## Bounded client quest delivery (2026-09-06)

PARTIAL / ADAPTER ACTIVATION OFF: typed v2 client batches, four captured-batch
slots,128 owned acknowledged visibility witnesses, immutable retry retention and
background cancellation-aware publication are implemented. Both native builds/
all17 suites, context65, fake-server311, C++-to-deployed-PHP/SQL27 and Windows/Linux
200K-attempt stress checks pass. No current host enables the capability or invokes
the observer/delivery pump. Epoch/session binding, server-support negotiation,
native adapter activation, reactions and gameplay acceptance remain open.

## Native quest event server contract (2026-09-06)

PARTIAL: strict optional v2 flat batches now persist ordered native transitions,
with fresh displayed-objective visibility witnesses, exact save/player ownership,
batch dedup and transactional history/frontier updates. Source SQL27 plus existing
journal23/world53 checks, paired protocols25/82/99, both builds/all17 suites and
packages pass. Client native observers remain inactive; encoding/delivery/witness
retention/negotiation/reactions and gameplay acceptance are not implemented/proven
by this contract. See the latest paired goal ledger for deployment evidence.

## Ordered quest observer foundation (2026-09-06)

CLIENT FOUNDATION ONLY:8306197 is locally deployed with native quest observers
inactive. Ordered scalar queue/load/exception boundaries pass both native builds,
all17 suites, MSVC/GCC observer74 and2M concurrent attempts per platform. Protocol
and packages pass. Server runtimef26fcf1 unchanged/HTTP200. Native event transport,
player-visible eligibility, canonical/save-bound history and reactions remain open.
See SYNTH docs/FLAT-SEMANTIC-EVENT-AUDIT.md and the paired goal ledgers; no game proof.

## Native semantic-event binding audit (2026-09-06)

AUDITED / NOT IMPLEMENTED: client exact-runtime audit identifies6 concrete native
sinks with13 instruction checks. Scalar quest stage/start-stop events are the next
paired boundary. Current strict v2 context has no native-event batch; ordered
transport, canonical/save-owned history, hidden-quest eligibility and permission-
gated reactions remain incomplete. Details: SYNTH docs/FLAT-SEMANTIC-EVENT-AUDIT.md.
Installed d454b3e/f26fcf1 unchanged; client hash/HTTP200 and protocol24/77/93 pass.
No new product code, deployment or gameplay proof; full goal remains active.

## Fresh quest journal history (2026-09-06)

PARTIAL / PAIRED DEPLOYED: client d454b3e and server f26fcf1 publish fresh quest-only
changes through the existing idle context lane, then retain changed journals as
immutable player-owned history included in the same acknowledged save frontier.
Cached/unavailable state does not trigger new observations; partial absence does
not imply completion or abandonment, and no NPC hearing or AI reaction is invented.
A scoped journal index is installed by schema migration 20260906001.
Both native builds/all 17 suites, context 32, passive seams 90, source/installed
quest SQL 23 and world SQL 53 checks pass. PHP 187 tests/2,461 assertions,
protocol/release/package checks pass; installed artifacts and live data preservation
verified. Exact native quest events/reactions, actual gameplay and VR acceptance
remain open. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for hashes and full limitations.

## Historical: worker-side visual encoding (2026-09-06)

PARTIAL / CLIENT DEPLOYED: fc4e1ef moves JPEG encoding, hashing and upload preparation
off the game pump in both lanes. One visual job per session; original copied pixels,
context and actor ownership stay bound. Counter lifetimes cover cancelled/discarded
jobs, and cancelled encoding results do not upload. GDI synchronization, COM cleanup
order and exact JPEG stream length are corrected.
Both native builds/all 17 suites, native encoder 21 checks, exact-method seam 47 checks,
protocol and both package audits pass. Installed DLL hash and unchanged INI/PEX
verified. Server fe85d83 remains unchanged and healthy.
GDI readback/copy is still synchronous; game latency, real imagery, VR eye-view
accuracy and gameplay acceptance are unproven. This is not completion of the visual
capture architecture or the full parity goal. See the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)).

## Historical: bounded flat discovery (2026-09-06)

PARTIAL / CLIENT DEPLOYED: client9a089f2 caps flat process-list discovery at256
handle reads plus at most64 fresh cached-handle resolutions per capture. Numeric
cursors/aged handles only; no stale actor snapshots or retained engine references.
Partial scans never attest a complete audience. Exact manual picking stays separate;
automatic discovery can converge over successive captures. VR remains exhaustive.
Both DLLs/all17 suites, adapter358, extracted-code flat37705/VR34961 checks,
protocol and package audits pass; installed hashes and unchanged INI/PEX verified.
Server runtimefe85d83 unchanged/HTTP200. This is an operation-count bound, not
measured game latency or freeze-resolution proof. Full parity and manual acceptance
remain open; see the retired parity ledger ([handoff](HANDOFF.md#retired-ledgers)) for evidence and remaining work.

## Historical: native flat picked-reference selection (2026-09-06)

PARTIAL / CLIENT DEPLOYED: c6a6372 observes the exact-runtime native activation
pick through a guarded forwarding hook and revision/epoch-fenced atomic mailbox.
Game-thread resolution reserves the exact actor independently of nearest64 discovery.
Manual flat requests have no nearest fallback; automatic activation and VR pointing
retain their separate behavior. Both DLL builds/all17 suites, targeting110,
adapter285, actual-header seam23, protocol and package audits pass. Installed hash,
unchanged INI/PEX and server HTTP200 verified. Live event delivery, stationary and
obstructed picks, menu/save/load, text/voice and long-session acceptance remain
unproven. Discovery scanning is still unbounded; the full parity goal remains open.

## Historical: same-observation save history (2026-09-06)

PARTIAL / SERVER DEPLOYED: fe85d83 closes the passive checkpoint gap by including
the exact capture's own derived observations before admission commits. Older
acknowledged checkpoints never advance from later writers. Source and installed
SQL53 cover loaded-save ancestry, concurrent-producer exclusion, rollback and
failure; existing ownership/memory and saved-audience38 checks pass. PHP186/2446,
protocol, release audit, installed hash and HTTP200 pass. Checked live state and
client artifacts/config are unchanged. No backfill or new schema. Client runtime
remains 3c01cb1; native builds were not repeated for this server-only change.
Actual engine save/ACK timing, flat/VR dialogue and sustained gameplay acceptance
remain unproven. See the goal ledger; the complete parity goal remains active.

## Historical: passive world observations and native history (2026-09-06)

PARTIAL / PAIRED DEPLOYED: client 3c01cb1 publishes changed observed world labels
without requiring NPC activation or dialogue, using one idle-only background task.
Acknowledgement, cancellation and frame gates avoid duplicate or superseded passive
writes. Server 73d92b9 atomically archives v2 world/item/POI records with their exact
saved-context owner. Player-history retrieval works; no Narrator speaker or NPC
visibility is invented. Old server reproduced missing history; source/installed
SQL30, actual-method75, existing ownership/memory and saved-audience38 checks pass.
Both DLL builds/all 17 suites, PHP186/2446, protocol and release/package audits pass.
Local paired deployment/hashes/HTTP200 and unchanged checked live data/config/PEX
verified. This is sampled observation, not complete semantic event or gameplay
acceptance. Broader events, bounded scanning, targeting and the full roadmap remain.
See the goal ledger for evidence, preserved data and manual test boundaries.

## Historical: scoped per-pump scene capture reuse (2026-09-06)

PARTIAL / CLIENT DEPLOYED: a96c5da shares one immutable base scene among coincident
consumers within a serialized flat/VR pump. Requested actors and detailed inventory
remain per-request copies. Scope exit, load and actions clear the cache; bootstrap
cannot be reused and background cannot replace dialogue refresh. VR pose timestamps
are not renewed. Both DLL builds/all 17 suites, 275 adapter assertions per lane,
protocol and both package audits pass. Flat deployment/hash/unchanged INI/PEX verified;
server 8b2fd44 unchanged with HTTP200. The source scan itself is still unbounded:
this removes repeated captures, not total scan cost or the need for manual freeze/
gameplay acceptance. Full targeting/discovery separation and all roadmap gates remain.
See the paired goal ledger for operation-count evidence and the next architecture gate.

## Historical: captured audience completeness (2026-09-06)

PARTIAL / PAIRED DEPLOYED: client d019c35 and server 8b2fd44 carry complete/partial/
unavailable captured-audience metadata through both protocol versions, exact saved
text/audio context and the native prompt. Overflow, capture failures and export
filtering cannot claim complete observation. Old payload defaults remain conservative.
All four process tiers are still scanned; this is NOT bounded discovery, a measured
performance improvement or a freeze-resolution claim. Both DLL builds/all 17 suites,
34,961 selection checks per lane, protocol/schema checks, 185 PHPUnit tests/2443
assertions, source/installed 38 saved-context checks and both archive audits pass.
Server-first local deploy, hashes, HTTP200 and unchanged live data/config verified.
The custom INI and original PEX are unchanged. Manual flat/VM/VR acceptance and the
broader roadmap remain open. See the paired goal ledger for evidence and limits.

## Historical: optional embedding failure preserves accepted text (2026-09-06)

PARTIAL / SERVER DEPLOYED: df96a86 preserves generated summary text when optional
embedding fails, with the same shared boundary for diary enrichment. Cancellation
and final lease/load/source fences remain authoritative; generator errors still
retry. The previous worker reproduces lost text and retry in actual PostgreSQL.
Source/installed code pass 67 targeted checks; existing diary fixture passes 739;
selected PHPUnit passes 184 tests/2403 assertions. Both native builds/all 17 suites,
protocol and package/release audits pass. Server-only deployment/hash/HTTP200 and
unchanged live counts/config verified; no client deployment. Model identity/backfill,
script VM and flat/VR gameplay proof remain open. See the goal ledger for limits.

## Historical: owned Papyrus packaging and flat deployment (2026-09-06)

PARTIAL / FLAT ARTIFACT DEPLOYED: client 4d592be adds the strict all-or-none
source/flags/provenance/PEX bundle. Both lane packages pass; the deployment helper
re-audits staging and refuses removal of an installed bridge by a missing build.
The original 372-byte PEX and matching PSC are installed in SYNTH_dev; installed
binary verification and hashes pass. DLL/custom INI unchanged; server health 200,
no server deployment. Packaging 11 tests, 28 scratch bundle cases, 380 binary
negative cases, helper guards, both DLL builds/all 17 suites and protocol pass.
Actual VM calls, script consumer integration and flat/VR gameplay remain unproven.
This is not full parity; see the paired goal ledger for exact evidence and limits.

## Historical: reproducible native-only Papyrus artifact (2026-09-06)

PARTIAL / BUILD VERIFIED, NOT DEPLOYED: client 9836043 adds a pinned Caprica
v0.1.5 build and strict complete-binary verifier for the original native bridge.
Explicit ScriptObject inheritance and real local F4SE/vanilla binary imports
produce a deterministic 372-byte PEX with six global native methods and no
executable instructions. Two isolated builds match; 380 negative binary cases,
compiler/input rejection, both DLL builds/all 17 suites and protocol/package
audits passed. The existing packages still contain no PEX, and no game script was
installed. Next is the owned-script packaging/deployment allowance, followed by
manual VM/flat/VR acceptance. This is not a script-runtime or full-parity claim.

## Memory request identity and provenance (2026-09-06)

PARTIAL / SERVER DEPLOYED: af6c526 fixes queryMemory's native diary/legacy ID
collision, preserves exact saved revisions and stops inventing native text-only
vector distances. Legacy behavior and actual prompt injection remain unchanged.
Selected PHPUnit suites passed 183 tests/2392 assertions; actual helper/request
probe passed 52 checks against source and installed code, with the prior installed
helper failing. Synthetic archive/correction probes, both DLL builds/all 17 suites,
protocol and package/release audits passed. Server-only deployment, HTTP 200 and
hashes verified; client DLL/INI/configuration unchanged. Model identity/backfill
and full game/VR acceptance remain open. See the paired goal checkpoint.

## Corrected text recall alongside vector search (2026-09-06)

PARTIAL / SERVER DEPLOYED: server 350ecae keeps saved corrected dialogue and diary
text eligible when vector search succeeds, without reusing invalidated embeddings.
Independent semantic/text pools retain at most 50 each; lexical rank precedes
recency across sources, and final native selection still returns at most one.
Text-only distances remain null. The original correction probe failed before the
fix and passes now. Selected PHPUnit suites passed 183 tests/2370 assertions;
synthetic profile/diary fixture passed 739 checks; SQL pool probe passed 16 checks
against source and installed helpers. Both DLL builds/all 17 suites, protocol,
server release and flat/VR package audits passed. Server-only deployment, HTTP 200,
installed file hashes and unchanged DLL/INI/configuration verified.
Embedding-model identity/backfill, native_diary legacy-ID mapping and gameplay
acceptance remain unresolved. Client implementation remains a1d4cad. See the
paired goal checkpoint for evidence scope and remaining full-parity gates.

## Inventory lock scope and item lifetime (2026-09-06)

PARTIAL / CLIENT DEPLOYED: client a1d4cad copies bounded inventory structure and
retains first-stack ownership under the list try-lock, then releases it before
name/instance/form metadata calls and sorting. Custom instance information remains
supported; unavailable/partial observations remain explicit. Both DLL builds/all
17 suites, the exact-function instrumented lifetime/lock/bounds probe, its sanitizer
run and protocol/package audits passed. The prior helper fails the probe's lock
check. Installed DLL and unchanged INI verified; server unchanged, health 200.
This removes SYNTH's enclosing list lock but does not prove engine metadata calls
cannot block, freeze resolution, full inventory coverage or flat/VR gameplay.
Flat player deep capture remains disabled. See the paired goal checkpoint.

## Actor-bound OpenPrompt native bridge (2026-09-06)

PARTIAL / FLAT NATIVE BRIDGE DEPLOYED: client fd4ab9f adds OpenPrompt kind 5
and a sixth original Papyrus declaration. Exact actor identity and session
cancellation travel with a revision-bound UI draft; Send takes a fresh exact-actor
snapshot and rechecks identity, eligibility and work ownership. No crosshair fallback.
The render bridge rejects stale receipts and preserves one-shot handoff. Both DLL
builds/all 17 suites, 489 config assertions, 77 substituted prompt-method checks,
six source signatures and protocol/package audits passed. Client-only deployment
verified the installed DLL and unchanged custom INI; server unchanged, health 200.
Claude UI delegation failed without edits; Codex performed the bounded fallback.
VR prompt presentation, PEX compilation, native/script runtime calls, flat visual/
input/save-load behavior and full parity remain unproven. See the paired goal
checkpoint and SYNTH docs/NATIVE-EVENT-API.md for exact limits.

## Value-only Papyrus native bridge (2026-09-05)

PARTIAL / NATIVE BRIDGE DEPLOYED, SCRIPT OUTPUT PENDING: client 5a84022 adds five
global primitive-only SYNTHNative bindings and original PSC declarations. Calls
copy into the existing epoch-bound queue; no game reference crosses the callback.
Both real DLL builds/all 17 suites, focused input tests, 38 substituted-VM checks,
positive/five-negative signature audits and protocol/package audits passed.
The deployment skill verified the flat DLL and preserved custom INI; server
unchanged, health 200. No compiler was found in the checked install/PATH; location
was requested. No PEX/ESP was added. Reproducible Papyrus compilation, runtime VM
registration, script calls and separate flat/VR lifecycle acceptance remain open.
This is not a claim of usable or complete script integration.

## Same-owner external Ask admission (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: client a27b5be separates persistent
conversation ownership from outstanding actor work. Same-owner Ask can use the
ordinary turn-cancellation path while busy; another actor/narrator owner or
ambiguous work cannot be taken over. One-shot calls remain idle-only. Direct player
input establishes ownership; matching end-conversation releases it; continuations
retain their turn's owner. Both DLL builds/all 17 suites, focused owner tests,
56 exact admission-method substitute checks and protocol/package audits passed.
Installed DLL hash and unchanged custom INI verified through synth-full-deploy;
server code/runtime unchanged, health 200. Actual interruption timing, complete
conversation lifetime, third-party/flat/VR acceptance and full API parity remain
unproven. See the paired goal checkpoint and SYNTH docs/NATIVE-EVENT-API.md.

## Exact API actors outside nearest discovery (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: client 2dfce6f captures the caller-named
loaded actor directly through IFalloutRuntime and reserves a slot in the same
bounded scene, even when nearest-64 discovery omitted it. Canonical identity,
playthrough, generation/frame and loaded cell/worldspace guards remain; ordinary
snapshots and downstream exact actor ownership are preserved. Both DLL builds/all
17 native suites, 212 adapter assertions, 45 exact-function substitute checks and
protocol/package audits passed. The deployment skill verified the installed DLL
and preserved INI; unchanged server health is 200. Actual crowded-scene native
integration, pointer lifetime, sustained flat/VR gameplay and full public API
parity are not yet proven. Total process-list scan/frame-time bounds remain open.

## Native actor dialogue integration API (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: client cb85761 adds the version-1 native
SpeakExact/Comment/React/Ask API to both DLLs. Bounded copied requests enter the
safe game pump with freshness/epoch/busy and exact actor eligibility checks.
One-shot speech/comment/reaction cannot arm rechat or execute generated actions;
Ask retains normal conversation policy. Both Windows builds/all 17 native suites,
301 fake-server checks, 77 targeting checks, concurrent queue tests, 32 exact
response-method substitute checks, C ABI and PE exports, protocol/package audits
and 183 PHP tests passed. Whole-client GCC probe exposed an unchanged LP64 codec
ambiguity; not claimed passing. Client DLL hash and preserved INI verified through
the deployment skill; server runtime unchanged, health 200. Papyrus, OpenPrompt,
followers, same-actor Ask preemption, crowded-scene exact targeting, SDK packaging
and actual third-party/flat/VR gameplay acceptance remain open. See
docs/NATIVE-EVENT-API.md in SYNTH and the paired goal checkpoint for the contract.

## Faction extra-data traversal bound (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: client42cfb90 replaces flat's unbounded
extra-data lookup with a512-entry walk under the existing nonblocking read lease.
Capped/cyclic or inconsistent flagged lookup reports unavailable/empty. The pinned
flat head-pointer representation is copied with static layout guards; VR remains
base-only with no imported flat layout. Both builds/all17 native suites, exact
substitute probes flat4594/VR735, three non-PIE sanitizer runs per lane,359 isolated
SQL checks and protocol/package audits passed. Initial PIE sanitizer signal-loop
failure is documented separately, not claimed resolved. Client DLL hash verified
after the known copy fallback; custom INI and server runtime unchanged, health200.
Actual engine pointer safety, package semantics, remaining discovery bounds and
full flat/VR gameplay/parity acceptance are still unfinished.

## Nearby-actor selection storage (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: client628d587 uses a64-entry nearest-actor
heap and bounded identity set instead of retaining/sorting every candidate.
Finite stable-input nearest ordering matches the prior function in210 randomized
crowds up to10000 actors;34822 substitute checks per lane, both builds/all17 native
suites and protocol/package audits passed. No cross-frame game pointers added.
Client installed with hash verification after an explicit DLL-copy fallback for
the deployment helper's same-size Modified-file skip; custom INI unchanged.
Server code/runtime unchanged, health200. All process handles are still scanned:
retained storage is bounded, not total engine work or frame time. Remaining native
bounds, target/completeness design and actual flat/VR gameplay gates stay open.

## Actor-value observation availability (2026-09-05)

PARTIAL / AUTOMATED + BOTH DEPLOYED: clientcdc7ebd/serverd371317 carry independent
health/AP percentage availability instead of treating missing/invalid native reads
as100%. Old snapshots remain immutable; missing flags produce null in AI prompts,
and inspect-actor detail likewise uses null for unavailable readings. Observed0%
remains distinct. Both builds/all17 native suites,183 PHP tests/2356 assertions,
275 isolated SQL checks,801 broader checks and protocol/package audits passed.
Server-first rollout verified; live checks and custom INI unchanged.
Permanent-value denominator semantics, real engine reads, remaining observation
and full flat/VR gameplay parity acceptance remain unfinished.


## Faction completeness through capture, snapshots and prompts (2026-09-05)

PARTIAL / AUTOMATED + BOTH DEPLOYED: client4a685c1/server44216de carry separate
source mode and optional list completeness. Native omissions/output caps and
server prompt/description caps cannot retain complete status. Immutable snapshots,
current actor/NPC JSON projections, legacy labels and strict schema validation
preserve the distinction; missing older fields stay conservative without backfill.
Both builds/all17 native suites,182 PHPUnit tests/2242 assertions,359 isolated SQL
checks,801 wider regressions, protocol and both package audits passed. Server-first
local rollout verified; live data checks and custom INI unchanged. Actual game
faction semantics, traversal bounds, actor-value/package availability, full parity
and flat/VR gameplay acceptance remain unfinished.


## Nonblocking faction rank capture (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: client5cbff46 replaces waiting faction lookup
with a nonblocking read lease retained through rank copying. Removed per-base
IsInFaction engine calls; copied flat changes override/remove base memberships;
VR stays base-only.512-entry checks per source array precede iteration; failed
capture reports unavailable. Both native builds/all17 suites and both package
audits passed; exact-function substitute probes flat131/VR52 checks passed.
Client deployed; server unchanged. Engine extra-data-chain traversal, faction
output/prompt completeness and actual game behavior remain unproven/unfinished.
This is readiness evidence, not sustained freeze-free game or full parity proof.


## Protocol transaction commit integrity (2026-09-05)

PARTIAL / AUTOMATED + SERVER DEPLOYED: server70d03e7 rejects failed faction
metadata writes and verifies PostgreSQL's actual COMMIT command tag for request
acceptance, pending/terminal replay and guarded action intents. Real aborted
transactions no longer masquerade as successful persistence.59 isolated SQL checks
passed on source and installed code;181 PHPUnit tests/2047 assertions and801
profile/diary/environment checks passed. Live data digests and client DLL/INI
unchanged. Historical snapshot evidence remains separate from current factions.
Windows protocol peer check passed; WSL verifier has an existing order-only manifest
mismatch with identical hashes, recorded separately. Native/game/VR proof not newly
established. Full parity and remaining capture/gameplay acceptance stay unfinished.


## Selected NPC detail ownership (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: final selected NPC now receives same-frame,
game-thread inventory/faction/package enrichment before text/voice/activation/
bored/combat/PipVision worker admission. Nearest-NPC deep capture removed.
Frame/generation/identity and immutable merge guards pass both builds and137 adapter
assertions; all17 native suites and protocol/package checks pass. Client26db78f
deployed, server unchanged. This supersedes the prior nearest-detail-source gap
in source, not gameplay proof. Player capture, actor property semantics, discovery
bounds and real flat/headset behavior remain separate unfinished requirements.


## Selected-actor packet coverage (2026-09-05)

PARTIAL / AUTOMATED + CLIENT DEPLOYED: the exact selected NPC now receives the
first NPC context-state slot before bounded bystanders. Crowd cap, same-name,
missing/dead/wrong-identity and immutable ordering regressions pass in both native
toolchains. Client883c86f deployed; server unchanged. Deep inventory/faction capture
still enriches the nearest actor, not necessarily this selected NPC. That separate
capture-ownership gap, actor property semantics and actual flat/headset response
and save-load acceptance remain unfinished. See the paired goal ledger for hashes.


## Nearby reference observation integrity (2026-09-05)

PARTIAL / AUTOMATED + LOCAL DEPLOYED: nearby items and points of interest now preserve
fresh/partial/cached/unavailable evidence through bounded capture, immutable context,
derived events, prompts and inspect_surroundings. Caps downgrade completeness.
Flat/VR builds,17 native suites,179 PHP tests and801 synthetic checks pass; paired
flat/WSL deployment and18 installed-code cases verified. See the goal ledger for
commits and hashes. Current-cell-only scope, per-object property semantics, actor
coverage and real flat/VR timing/save-load acceptance remain separate requirements.
Full behavioral/architectural parity is not complete.


## Quest observation integrity checkpoint (2026-09-05)

PARTIAL / AUTOMATED + LOCAL DEPLOYED: bounded nonblocking quest capture carries
collection and objective quality through immutable context, prompt and read_quests.
Cached/unavailable reads cannot clear the projection or claim fresh action success.
Partial lists update observed identities only. Flat/VR builds, native/protocol/PHP,
779 SQL/loopback checks, and installed-code probes pass. Flat client and WSL server
deployed; live quest records and custom INI preserved. See the paired goal ledger
for exact commits, artifact hashes and evidence. VR objective completeness remains
unsupported by pinned headers. Manual flat/headset response and save-load stability
are not proven; full feature/architecture parity remains active.


The current DialecticServer runtime is the implementation baseline; this matrix records the
Fallout 4 adaptation and remaining proof boundary. `AUTOMATED` means no-game tests pass;
`WSL PROVEN` requires the exact Apache/WSL artifact; flat
`IN-GAME PROVEN` and `VR IN-GAME PROVEN` require independent SYNTH/Fallout evidence. A connector
can be automated with a fake while real provider validation remains separately recorded.

2026-09-05 diary vector checkpoint: new embeddings carry an exact accepted-content checksum
through worker recovery/publication. Unknown or mismatched source bindings exclude the vector,
not valid published text. Old checkpoints retain their original data and can publish text-only.
This is source provenance only; model-version compatibility and historical backfill remain open.
See NATIVE-DIARY-INSPECTION.md and the paired goal ledger for validation/deployment evidence.

2026-09-05 diary scale checkpoint: shared source visibility is validated once per query.
The same301-journal snapshot returned identical results in6405ms before /129ms after the fix.
All301 entries exported;483 latest/recall/HTTP reads passed a60-second synthetic soak.
Server-only deployed71918ed; production deadlines and save/actor integrity checks unchanged.
This does not prove larger, many-author/control-heavy histories or manual gameplay stability.

2026-09-05 inventory quality checkpoint: paired client 49d8446 / server 07daff0 are locally
deployed. Complete, partial and unavailable observations survive saved contexts, prompt filtering
and action results; old missing-quality empty arrays are unavailable, not observed empty.
Seven saved-context cases and installed-code probes pass. Deep player capture, other observation
domains and actual flat/VR gameplay behavior remain separate open gates.

| Area | Synthserver baseline | Synthserver requirement | Initial proof |
| --- | --- | --- | --- |
| Bootstrap/config | PHP app, configuration and installer | typed defaults/overrides, ignored secrets, startup validation | clean checkout/bootstrap tests |
| Discovery/init flow | launcher-selected server plus plugin init | reachable loopback endpoint, exact client/runtime compatibility, capability negotiation, persisted loaded-plugin indices and truthful readiness | flat/VR fake-client init plus WSL readback |
| HTTP protocol | JSON/NDJSON game pipeline | strict `synth.*.v1`, `flat|vr`, limits, IDs, generation, capabilities, errors | flat/VR schema and fake-client E2E |
| State ingestion flow | fast game events update server context | validated target/player/audience/world facts persist without invoking an LLM | fixture E2E plus event/UI readback |
| Runtime/capabilities | implicit game client | exact lane/versions, intersected input/presentation/context/action/VR capabilities | manifest/filter negative tests |
| Event log | PostgreSQL-backed events | ordered/correlated FO4 events with runtime evidence and retention | AUTOMATED: claimed v2 insertion evidence and bounded saved ancestry feed the main historic dialogue composer; legacy delivery cannot modify native rows. Typed delivery/revisions, other history/memory readers, raw/public/background writers and retention remain open |
| Prompt pipeline | context/prompt/model/response orchestration | bounded typed pipeline with deterministic fake | golden pipeline tests |
| Dialogue return flow | line-oriented dialogue/TTS output | request-correlated validated NDJSON, opaque media IDs and one terminal close/error | complete fake input-to-client-playback contract |
| Action-result flow | function call plus `funcret` follow-up | typed intent, persisted issue state, exactly one idempotent client result and optional result-aware continuation; `SheatheWeapon` is capability-gated to the canonical actor and the flat/VR game-thread implementation, while `EndConversation` closes only SYNTH-owned dialogue and applies the configured client cooldown | read-only, sheathe and end-conversation action protocol fixtures; native in-game proof pending |
| Long-term memory | persisted memory/embeddings | FO4 actor/world identity, vector metadata, retrieval limits | PARTIAL/AUTOMATED 2026-09-05: native recall, embeddings, automatic capture/dispatch and frozen prior-summary references are deployed. A 71-summary synthetic chain passes transitive visibility and broken-edge/cycle rejection. Vector model-version metadata/repair, native CRUD/retention and gameplay memory production remain open |
| Middle-term memory | conversation summaries/state | deterministic summarization lifecycle and invalidation | fixture tests |
| Relationships | actor/player relationship state | canonical actor identity, auditable bounded updates | PARTIAL/ISOLATION GAP 2026-09-05: native journal, inline/dedicated evaluation, providers, recovery and versioned notes/type policy deployed. Audited actor-lock API now fences pre-change work across unlock, cancels live leases and preserves accepted history. Actual SQL, concurrent admin writers, forced audit rollback, HTTP CSRF/revision checks and slow-provider interruption pass. Five HTTP drivers and worker restart/recovery pass. Manager remains explicit-only and legacy game hooks remain name-keyed. Native management screen/manual edits, legacy-lock migration, both NPC-to-NPC directions, coordinated native hook activation and gameplay proof remain required; see NATIVE-RELATIONSHIP-MIGRATION.md |
| Dynamic profiles | profile generation/update | Fallout 4 profile fields and controlled prompt input | PARTIAL / REAL-PROVIDER SYNTHETIC 2026-09-05: immutable save-visible NPC/player-owned Narrator revisions plus durable per-actor jobs, frozen pre-provider input, checkpoints, leases and recovery. 96 disposable checks include one actual configured OpenRouter GLM-4.7 request through the fresh-process task, atomic ready publication and saved-frontier read. Broader real-provider fields/Narrator/connector coverage, manual development semantics and flat/VR gameplay acceptance remain required |
| Diary | actor diary generation | typed entries, provenance and retention | PARTIAL / NATIVE PIPELINE CONNECTED 2026-09-05: manual NPC/Player/Narrator and flat sleep/wait batches, role policy/cooldowns, durable configured workers, polling and save checkpoints connected. Saved NPC/Narrator recall, checkpointed embeddings, author directory, archive/restore and immutable content corrections are locally deployed. Corrected text excludes old vectors and stale descendants; private Player journals remain separate. Full-text paginated export and a standalone printable book now have SQL/HTTP/browser evidence. Historical embedding backfill, sustained performance and manual flat/VR gameplay acceptance remain open; source/build/export proof is not game proof. Legacy rows remain separate. See NATIVE-DIARY-MIGRATION.md, NATIVE-DIARY-INSPECTION.md and the paired goal ledger. |
| Narrator | narrator prompt/update | Fallout 4 narrator context and explicit speaker identity | fixture tests |
| Rechat/unfinished | continuation behavior | correlated idempotent continuation with limits | contract E2E |
| Auto greeting/bored/combat bark | automatic turn triggers | capability-aware policies, cooldown/rate controls and live combat context | clock/policy tests |
| Active quest | quest context | FO4 client payload, bounded normalized storage/prompting | schema/fixture tests |
| World knowledge | knowledge management/retrieval | source/provenance, safe CRUD, FO4 defaults | retrieval/UI tests |
| Playthroughs | snapshots and rollback | versioned save/profile/runtime identity, safe flat/VR memory reuse, restore-to-copy, audit | DB integration tests |
| LLM connectors | OpenAI JSON, OpenRouter, Google, Groq, KoboldCpp, Player2 | typed adapters, deadlines/retries/redaction, optional boot | fake HTTP contract per adapter |
| STT connectors | Deepgram, Parakeet, Whisper, LocalWhisper, Gemini, Azure, Inworld, none | typed bounded upload/results, optional boot | fake media contracts |
| TTS connectors | OmniVoice, PocketTTS, Chatterbox, XTTS, Inworld, Cartesia, Piper, 11Labs, Kokoro | typed synthesis, opaque media, cache policy | fake media/cache tests |
| Local voice cloning | NPC voice samples and TTS Studio | generation-checked v2 imports, bounded FUZ/BA2 conversion, preserve manual choices, source-hash cache invalidation, audio.cpp voice_ref | WSL PROVEN 2026-09-04: native archive/upload probe and real local PocketTTS WAV output for Danse, Preston, Piper; voice likeness and flat/VR game playback pending; see VOICE-CLONING.md |
| Quickstart/config UI | guided setup and config | safe local setup, flat/VR version and capability tests, secret masking | browser/API tests |
| Profile/prompt/action UI | editors and management | validated structured changes, revision/audit support | CRUD/security tests |
| Logs/queue UI | request logs and work status | redacted bounded views, cancellation/status | API/UI tests |
| Memory/world/relationship UI | domain management pages | safe filters, pagination, provenance, destructive confirmations | PARTIAL/WSL 2026-09-05: inspector, Archive/Restore, immutable corrections, automatic rebuilds and explicit one-input manual regeneration approval deployed. Corrected history is retained; approval is audited and cleared by changed edits/archive transitions. SQL/dispatcher/browser checks pass. Per-query visible-ID caching and grouped source checks now avoid repeated audience resolution: synthetic 71-memory reads about 4 ms, 301-memory read 48 ms, 70 replacements/71 active memories 12 ms. Keep-correction reconciliation, larger-history/service soak, embedding provenance/repair, retention, broader legacy administration and gameplay management remain open |
| Playthrough/stats/audit UI | operations pages | truthful flat/VR health/evidence, safe restore workflow | browser/API tests |
| Workers | background queue processing | typed bounded jobs, leases/idempotency/recovery, heartbeat | PARTIAL/AUTOMATED: native dispatch, deduplication and actual killed-process lease recovery pass synthetic probes. Manager PostgreSQL sockets are now isolated before fork; 90 concurrent queries, transaction refusal and failure status pass. Real manager/native child idle completion verified. Full multi-task restart/fairness/soak and gameplay production remain open |
| Post-turn handoff | request-path memory/profile/relationship post-processing | enqueue only after commit; provider/job failure never holds or corrupts the active turn | transaction and worker fault-injection tests |
| Backups | backup/restore tooling | external target, version metadata, restore-to-new verification | isolated DB tests |
| Health | service diagnostics | core/dependency/degraded split, redacted details | fault-injection E2E |
| Release audit | packaging checks | no secrets/runtime/FO4 data; attribution/dependency inventory | CI artifact audit |
| Apache/WSL | server deployment | loopback `8087`, least privilege, supervised workers | exact WSL acceptance run |
| Native client contract | SYNTH game integration | identical schemas/fixtures for both RANGROO/SYNTH artifacts | cross-repo hash and flat/VR E2E |
| Flat Fallout 4 behavior | not applicable to server alone | real SYNTH turn, media, action/result, save/load cancellation | later flat in-game matrix |
| Fallout 4 VR behavior | not applicable to server alone | real SYNTHVR HMD/input/media/action/capability and recovery behavior | later in-headset/profile matrix |

## Implementation evidence (2026-08-11)

Evidence below is intentionally narrower than the target rows above. `AUTOMATED` does not imply
PostgreSQL deployment, WSL runtime, flat-game, or VR-headset proof.

| Area | Status | Evidence | Remaining proof |
| --- | --- | --- | --- |
| Bootstrap/config | AUTOMATED (source) | unified PHP bootstrap/config, PHP 8.2 lint across 303 tracked PHP files | isolated PostgreSQL bootstrap and WSL install |
| HTTP protocol | AUTOMATED | 14 closed schemas, 18 positive/negative fixture cases, 30 byte-identical cross-repo files | Apache request E2E |
| Prompt/dialogue/action/media domains | AUTOMATED (source) | current DialecticServer pipeline plus strict ingress, correlated NDJSON, drained rechat, authorized greeting/bored/combat-bark triggers, deferred TTS, opaque binary media and idempotent action state | isolated PostgreSQL/PHP integration and live providers |
| Context/memory/relationships/profiles/narrator/world/playthrough | AUTOMATED (source) | current persisted DialecticServer implementation adapted under `synth` / `synth_meta`; canonical nearby actor, inventory, faction, activity/package, active-quest, item/POI and native FO4 world state plus exact loaded-plugin manifest are persisted; faction observation quality is retained and linked NPC profile rules receive stable form/plugin keys | isolated PostgreSQL integration, native companion affinity and in-game evidence |
| Local management UI | AUTOMATED (source) | one shared persisted DialecticServer UI adapted to SYNTH terminology; every user-facing PHP page consumes the canonical head/footer shell, Gothic821/Monofonto assets and shared SYNTH theme, including TTS Studio, setup, queues, caches and audits | browser smoke test against isolated database |
| Release audit | AUTOMATED | server tracked-tree audit passed; flat and VR client packages passed deterministic release audits | audit a built WSL server artifact |
| Apache/WSL/PostgreSQL | PLANNED | configuration/templates and dry-run-safe scripts are present | exact WSL execution, `apache2ctl configtest`, migrations, roles, restart and restore-to-copy |
| Native client contract | AUTOMATED | mirrored fixture bytes and SHA-256 manifest agree; 17/17 engine-independent CTest suites pass | live Apache flat/VR E2E |
| Flat Fallout 4 behavior | SOURCE BUILD | `SYNTH.dll` builds through pinned CommonLibF4/xmake lane | F4SE load and flat in-game matrix |
| Fallout 4 VR behavior | SOURCE BUILD | `SYNTHVR.dll` builds through independent CommonLibF4VR/CMake lane | F4SEVR load and in-headset matrix |
| PostgreSQL event/session/context persistence | AUTOMATED (source/static) | prepared repository, idempotent requests, lane/generation sessions, context migration and bootstrap selection | live PostgreSQL concurrency/migration/role proof |
| Deterministic dialogue/media/action orchestration | AUTOMATED (source/static) | canonical input_text/action_result controller, fake LLM/TTS, opaque media, one action and continuation | PHP execution and native HTTP cross-repo E2E |
| Provider contracts | AUTOMATED (source/static) | fake HTTP LLM/STT/TTS, limits, deadlines, retry, URL policy, redaction and readiness | PHP execution; live providers optional |
| Conversation policies/workers | AUTOMATED (source/static) | summaries, cooldowns, quest/diary/narrator/world/playthrough policies; leased retries/reclaim/post-commit handoff | PHP execution and durable PostgreSQL queue |

## Definition of parity

Linked legacy control checkpoint `593281c` (2026-09-05): exact actor-linked profile
preview and confirmed one-time native lock import, audited source IDs, native-decision
precedence and job fencing are locally deployed (schema 20260905018). SQL/HTTP and
PHPUnit 173/1287 pass. No live controls imported; management presentation, manual pair
correction and coordinated gameplay activation remain unfinished.

Native prompt/explicit dispatch checkpoint `1ac1255` (2026-09-05): one saved relationship
graph query supplies exact-context tier/numeric prompt text with explicit unknown state.
The explicit CLI uses separated player and paired lanes. SQL prompt/discovery/isolation
checks and PHPUnit 172/1280 pass; locally deployed. Actual gameplay hooks and automatic
scheduling remain gated pending coherent completion/control/legacy-lock integration.

Player-only contract checkpoint `8ccede9` (2026-09-05): versioned canonical Player-only
inputs, selected-line prompts, pending/saved validation and nonoverlapping paired publication
are locally deployed. SQL proves both execution orders and saved independent scores;
PHPUnit 171/1274. Existing scheduler capture defaults are unchanged pending coordinated
native prompt/inline/dedicated/scheduler/management cutover. No game acceptance is claimed.

Paired dispatch checkpoint `243104e` (2026-09-05): canonical completed-response/listener
discovery, original-owner frozen recovery, independent lane cooldowns and shared actor caps
are implemented and locally deployed (schema 20260905017). SQL two-process/killed-worker,
retry/deduplication/invalid-input liveness and independent actor-lock checks pass; PHPUnit
170/1267. The dispatcher is not yet connected to automatic gameplay scheduling. Separate
player-only evaluations and coordinated native gameplay/management cutover remain required.
Earlier checkpoint remaining-work descriptions are historical.

Saved paired-reader checkpoint `26132ce` (2026-09-05): paired state now participates
in exact saved-history reads with whole-result and transitive cross-owner dependency
validation. SQL save forks, malformed inputs, no-change results and privacy checks pass;
PHPUnit 169/1263 and server deployment are verified. Paired dispatch/recovery and
coordinated native gameplay cutover remain open. Older reader-exclusion notes are historical.

Paired publication checkpoint `d423b9b` (2026-09-05): original-owner configured providers,
atomic independent revisions/result/dependencies and final lease fencing are locally
deployed (schema 20260905016). Five loopback HTTP adapters, forced rollback/cancellation
and PHPUnit 168/1259 pass. Paired saved-history reads, dispatcher recovery and automatic
native cutover remain unfinished. New paired rows are excluded from the old reader.

Paired queue checkpoint `13f9f6e` (2026-09-05): terminal-stable paired jobs, shared
two-slot per-actor limits, bounded retries/deadlines and both-actor cancellation are locally
deployed (schema 20260905015). SQL/two-process checks and PHPUnit 167/1253 pass.
Paired provider execution, dual-owner publication/visibility and native cutover remain open.

NPC interaction checkpoint `67232d5` (2026-09-05): frozen canonical pair inputs,
independent saved priors, selected prompt/connector and both actor controls are locally
deployed with bounded independent model-result parsing. Selected PHPUnit 166/1240 and
isolated SQL checks pass. Paired queue/provider execution, atomic dual-owner publication
and coordinated native gameplay cutover remain unfinished; this is not full parity.

Relationship participant checkpoint `2a26188` (2026-09-05): response.v3 freezes each
accepted line's final canonical listener/utterance ID after actual strict/rechat routing.
Unknown/ambiguous listeners remain unavailable; V2 evidence is not backfilled. Dedicated
inputs/prompts preserve intended addressing without claiming hearing. Actual returnLines
and SQL/provider checks pass; bidirectional listener-owned evaluation/publication and
native hook cutover remain open. This is not complete NPC-to-NPC parity.
Read-layer checkpoint `50c63f5`: a routed-evidence facade now reads independent saved
speaker/listener scores through the same dependency graph, without raw-history output or
request-owner changes. SQL proves +11/-7, dedicated notes, invalid sources and saved forks;
default memory/history regressions pass. This internal reader is not yet connected to
bidirectional model execution, publication or the gameplay hooks.

Parity is behavioral and evidence-based, not a blind file copy. A row may be deliberately changed
for Fallout 4, VR, security, current provider behavior, or the native F4SE/F4SEVR architecture, but
the matrix must record the difference and test. No applicable row is complete solely because the
UI route/class exists, and flat proof does not satisfy a VR row.

Management checkpoint `49f6515` (2026-09-05): native actor/playthrough relationship
lock/unlock and explicit exact-linked legacy lock import are available in management.
Desktop/narrow browser fixture and actual API/disposable SQL checks pass; deployed
page/health HTTP 200 and source bytes match. No live controls were changed. This is
administrative UI readiness, not native gameplay activation or full relationship parity.
Manual pair correction, coherent hook/scheduling cutover and flat/VR acceptance remain.

Correction backend checkpoint `75a7524`: canonical pair replacement/custom types,
explicit administrative provenance and new-snapshot visibility locally deployed. SQL
proves saved forks, concurrent conflict, worker fencing and later inline inheritance;
HTTP API and 174/1300 selected PHPUnit pass. No live correction. Editor/remove/reset,
coherent hook/scheduling activation and actual flat/VR acceptance remain unfinished.

Editor checkpoint `ff69d7c`: directed replacement, details and custom types are now
exposed in management, browser-verified at desktop/390px and deployed byte-for-byte.
Unknown remains distinct from zero, and new publications do not rewrite selected old
snapshots. Draft/conflict/uncertainty checks pass; no live changes. Reset-to-unknown,
coordinated gameplay activation and flat/VR acceptance remain open.

Reset checkpoint `431ee4c`: explicit unknown revisions, API/editor reset control,
saved-fork isolation, no-change dependencies and later inline/player/paired updates
are implemented and locally deployed at schema 20260905020. SQL/API/browser checks,
175/1306 selected PHPUnit and existing native/protocol/archive audits pass. No live
reset or client binary change. Coordinated gameplay activation and independent manual
flat/VR acceptance remain open; see the goal ledger for the timing-test rerun caveat.

Runtime integration `bda8c09` / service isolation `f8e45c9`: canonical native prompt,
post-completion inline publication and scheduled player/pair evaluation are deployed.
SYNTH runs on its own loopback 12348, not Dialectic's 12347. Real finalizer and fresh
service/loopback tests pass; installed service produced one ready player result/state
from accepted evidence. Selected tests 179/1319. Manual flat/VR and sustained service/
larger-history reliability remain; initial startup transient is recorded in the ledger.

## Native PipVision checkpoint (2026-09-05)

PARTIAL / AUTOMATED: exact v2 snapshot, turn and capture-ID ownership now spans capture,
provider completion and the reaction prompt. Cancellation/save/supersession and native
ambient isolation pass no-game SQL/native tests. Legacy gallery rows are not native
knowledge. Saved-timeline visual history and versioned corrections are now implemented;
flat spoken-image and independent VR acceptance remain unproven. See the goal ledger.

### Accepted saved visual observations (2026-09-05)

PARTIAL / AUTOMATED: immutable native visual results now advance ordered history; ambient
prompts restore only accepted actor-scoped observations within the exact saved frontier. A post-response client
acknowledgement makes completion eligible for subsequent co-saves. The independent cancel
lane now covers ITT itself. PostgreSQL save/fork/replay/isolation checks pass. Native visual
management now records CSRF-protected, optimistic description/reset revisions bound to the
selected actor and snapshot. Current-generation and ancestral readers share immutable result
and correction evidence; old snapshots cannot import future completion or edits. The gallery
keeps active/delete/lock as global policy and routes native text edits to scoped controls.
Automated: 184 selected PHPUnit tests / 1387 assertions, 76 synthetic SQL/HTTP checks, browser
save/reset/conflict/unsaved/keyboard checks at 1440/390/320 pixels. Real flat/headset capture,
correction, acknowledgment, save-load and spoken-image behavior remain unproven.

## Conversation lifecycle: exact continuation scenes (2026-09-09)

STAGED / AUTOMATED: continuation listener routing and completed-ancestor history now
consume the validated scene named by that request, while retaining the original
chain context and parent hash. Historical reads keep the real caller's native lock;
they do not replace request globals with an ancestor or consult the latest scene.

An isolated PostgreSQL probe using the production advisory-lock adapter passed 30
checks: owned admission; stale/future capture; cancelled request, generation and
turn replacement; lane, parent completion and canonical-body conflicts; physical
cell/worldspace, player, target and audience availability; newly arrived speaker
emission/listener routing; historical speaker membership; later-scene isolation;
action-scene inheritance, exact accepted receipt ownership, action-response history,
and advancing (not reusing) the inherited scene after an action.
The expanded probe also verifies atomic scene provenance writes, later witnessed
history, legacy audience isolation, saved-frontier exclusion, inherited memory
scope, repeatable migration without rewriting populated history, and preparation
locking the responder rather than the root target. The last check intentionally
uses zero game time; it proves actor binding, not generated summary publication.
Only the freshly created probe database was removed. Runtime synth and existing
testdb were not reset. Focused PHPUnit passed 165 tests / 4450 assertions, including
the fresh-capture inventory boundary and post-capture mutation.

This is not runtime activation: preparation still rejects scene-bearing rechat.
Action-result scene inheritance is implemented for context, routes and ancestor
history. Exact accepted receipts and emitted-action ownership are required.
Inventory overlays stop at that capture rather than resurrecting older receipts.
Schema 20260909001 adds nullable history_events.scene_context_sequence with a
same-session/generation context foreign key. The conversation writer records the
validated scene while preserving root request ownership; shared witnessed-history
readers use that scene and retain the existing saved cutoff. Memory preparation
uses the actual responder. Context observations and administrative/derived writers
retain their explicitly owned context. No historical rows are backfilled.

Before migration the local history table held 496 rows / 1264 kB. The scene column
has no default or data rewrite; the exact-reference join retains the existing
context primary key. No large-history latency improvement is claimed. For code
rollback retain the additive column and its provenance; do not drop populated
scene evidence or downgrade the recorded schema version.

The actual preparation coordinator now validates the scene before cached policy,
selection or reservation work. A disposable PostgreSQL probe passes 34 checks,
including real preparation of a fresh arrival, exact replay, stale cached-scene
rejection and one-child admission without duplicate budget use. The focused PHP
suites pass 165 tests / 4450 assertions. No provider or game was exercised.

Client defaults now offer fresh-scene support in both lanes. Server init explicitly
acknowledges it only with v2 plus turn, parent and scene ownership capabilities;
existing flat feature status codes are preserved. Memory capture, dispatch capacity, rebuild locking and persisted
execution now share the proven response identity rather than the root target.
Job claim/publication also requires its batch actor to match the job actor.
The expanded disposable PostgreSQL probe passes 37 checks: actual capture, enqueue,
persisted execution and summary publication for the new responder retain root
sequence 7, with the summary callback outside SQL locks. Wrong-actor and retired-
generation jobs never invoke that callback. This uses a deterministic summary
callback, not a live model or embedding provider. The optional relationship-derived
evidence path remains root-target scoped; its post-completion metadata cannot
alter emitted dialogue, but its fresh-scene ownership still requires adaptation.
No relationship setting or excluded companion affinity feature was added.
The acknowledgement regression and existing PHP suites pass 166 tests / 4480 assertions.
Safe generation overlap remains open. Flat in-game and VR headset acceptance
remain user-run gates.
