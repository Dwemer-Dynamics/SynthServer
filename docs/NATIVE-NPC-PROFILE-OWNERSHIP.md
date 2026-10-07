# Native NPC profile ownership cutover

## Rechat dependency audit (2026-09-07)

See NATIVE-RECHAT-OWNERSHIP.md for the reproduced continuation contract defects
and coordinated next implementation. Actual SQL/binder/parser probe: 17
defect-detection checks, all fixtures rolled back. It confirms the loss of
captured speaker/audience/identity, non-target rejection and cross-session
legacy budget-key collisions. Parent response IDs and unconditional canonical
listener routing are missing from the current path. No product fix this audit.
The source-only profile migration still must not be deployed before those
ownership and complete main/provider/voice gates pass.

## Action reply and speech follow-up (2026-09-06)

SOURCE FIX / NOT DEPLOYED: d9fd4cd. The response owner now comes from the
captured target or the actor in the exact persisted action continuation.
Action/idempotency/session/turn/generation/runtime/origin-context checks are
shared by native profile resolution, speech and ordinary history readership.
No mutable speaker global or stale profile row can select that actor.
Native funcret uses the main early profile body after the action adapter binds
its captured identity. Original immutable context is not rewritten.

Ordinary same-name target speech is now resolvable: the exact target is proof
of the response owner, unlike an ambiguous name among non-owner participants.
The existing regression expectation was updated to distinguish these cases.
Action speech/profile/manual voice/history reader tests select actor A while
the original target is same-name actor B. Missing provenance yields no owner
and no history, not a fallback to B. This is reader-selection proof, not full
provider or memory-content generation proof.

PHP 210/3135; owned SQL probe 91 checks; cooldown 58; ancestry 42; inventory
binder still returns 512 stacks/1024 units with exact saved filter and original
snapshot. All SQL fixture rows/DDL roll back. Audit 697/399/143/12 and protocol
29/131/148 pass. No runtime, schema, provider or game change.
Current owned-probe SHA256:
8600AB1B189A13288D1DCB6AEA1189C04512E82F9A91B14E943B097E1E7AC73F.

Next ownership gap: native trigger binding starts with the context target, but
main_synth_pipeline's rechat block calls synthResolveServerSideRechatTarget
then synthSwitchActiveNpcProfile using a selected display name. That can differ
from the original owner. Trace the client continuation and persist/validate
the server-selected identity; do not grant authority from SYNTH_NAME or rewrite
the captured context. Full worker, management/TTS/cache, browser, populated
upgrade/concurrency and main/provider/text/voice deployment gates remain.

## Writer-boundary follow-up (2026-09-06)

SOURCE FIX / NOT DEPLOYED: b9f2cc5. The expanded SQL probe first reproduced
an update to actor B by row ID during actor A's native turn. The shared row
writer now re-reads actual state and checks the current canonical owner under
the native request lock. This applies to direct/generic updates, resolved
voice and refresh metadata, metadata edits, delete and per-profile backup.
Caller snapshots cannot select protected fields. Partial native edits retain
custom profile assignment; generic empty voice updates retain manual voice.

Game reads no longer write JSON repairs. Native calls cannot run legacy
global history backup/restore/name merges, truncate or unrestricted upsert.
Legacy bulk backups exclude native rows. Explicit row backups preserve npc_id
and omit actor_identity_id because the history table has no such column.
Captured native renames remain supported by owned upserts; this does not add
a native RenameNPC game capability.

Validation: PHP 210 tests / 3134 assertions (existing storage method expanded
to 24 assertions), audit 697 tracked / 399 PHP / 143 JSON / 12 canonical
directories; unchanged protocol 29 schemas / 131 fixtures / 148 peer files.
The owned SQL probe now passes 66 checks. It covers all affected foreign-ID
writers, stale snapshots, wrong lane, cancelled generation, caller transaction
preservation and old native name-token collisions with unbound legacy rows.
Native/v1 reads resolve their own row; an ambiguous management bare token
returns null while explicit row-ID management remains possible. All fixture
rows/DDL roll back. This is not full main/provider/worker/browser/game proof.

Current scratch profile-binding-owned-sql-probe.php SHA256:
F0CAE1E7665A4C48B09F2DE9CC435654A12F2113580F29022C7005E680160D6F.

Remaining deployment gates: action-result/rechat speaker ownership, full
same-name worker preparation/generation/publication, management/TTS callers
and cache/file keys, browser accessibility/responsiveness, populated synthetic
upgrade and concurrent creation, actual main entry and configured text/voice
routing. Scoped cancellation was tested; concurrent lifecycle acceptance is
still open. Installed product/schema remain 0ade54e/20260906005.

## Source implementation checkpoint (2026-09-06)

PARTIAL / NOT DEPLOYED. The storage/runtime and batch-admission cutover is
implemented in source; the full caller/integration/management gates below
remain open. Do not deploy this checkpoint before those gates pass.

- Schema version 20260906006 removes only the global name constraint and adds
  a partial legacy-name index. Native actor uniqueness remains. The change
  preserves rows, current actor links, history keys and existing tokens.
- Native upserts resolve a captured actor under the request lock and read
  form/plugin/physical/voice/faction data from that snapshot, not caller text
  or mutable globals. They conflict by actor ID; legacy upserts stay unbound.
- Native name/refid/token facades resolve owned rows. Foreign tokens and wrong
  runtime lanes are refused; new native tokens are identity-derived and
  existing tokens survive rename. Ambiguous unscoped management tokens return
  no row rather than selecting one arbitrarily.
- The main pipeline no longer rebinds by name or resets the native token to
  md5(name) after profile loading. Profile switching uses the resolved token.
- Batch admission uses actor keys; transfer creates separate per-actor jobs
  with frozen readable labels. Existing singleton/name-keyed queued jobs are
  still checked against their canonical original binding. A row rename no
  longer invalidates that actor link.
- Legacy raw name writes and timestamp restore/relationship cleanup cannot
  mutate native rows. ID-based updates retain native actor IDs and tokens.
- Management cards add compact actor IDs with canonical identity labels;
  create-result lookup is restricted to unbound rows. Visual/browser tests
  remain pending. The required local Claude Opus 5 attempt failed with expired
  OAuth authentication (401), so Codex implemented the bounded changes.

Validation so far: selected PHPUnit 210 tests / 3122 assertions, including one
new existing-file storage regression (12 assertions). Release audit verifies
697 tracked files / 399 PHP / 143 JSON / 12 canonical directories. Unchanged
protocol: 29 schemas / 131 fixture cases / 148 byte-identical peer files.

The additional scratch profile-binding-owned-sql-probe.php passes 37 checks:
actual input ensure/read block, A/B/A, playthrough isolation, renamed stable
row/token, manual voice retention, foreign token refusal, legacy upsert
isolation, wrong lane, same-name batch admission and actual durable per-actor
job transfer/rebinding. Migration is applied twice inside its caller
transaction. All fixture rows and DDL are rolled back. It is not a full main
entry/provider/worker-completion/browser/game test.

Scratch SHA256:
CB2406BF615CDE53DB8EF0482FA44CCFD9410F4AB40CE73D76FFBAFFFE41B02C.

Before deployment, specifically verify action-result/rechat profile ownership,
same-name full worker preparation/completion, existing name-derived token
collisions with newly created legacy rows, management/TTS callers of getByName
and getByMD5, browser accessibility/responsiveness, actual main entry and
configured text/voice routing, upgrade of populated synthetic legacy/native
rows, concurrent creation and cancellation. Generic ID writers and stale
row snapshots also need review against the new ownership boundary.

The earlier inventory's ui/npc_upload.php name-conflict statements target
bio_templates_custom, NOT core_npc_master. They stay unchanged; template
name uniqueness is intentional. This was resolved by inspecting the actual
SQL before changing an unrelated constraint/caller.

## Status and evidence (2026-09-06)

REPRODUCED / NOT FIXED. This is a required part of the existing full parity
goal, not a new feature or alternative completion target. The preceding
layout request is complete; this audit changes the next implementation step
from a local name-binding patch to a coordinated storage/caller cutover.

Source: Synthserver codex/dialectic-parity-core at 030a359; runtime product
0ade54e. The same diagnostic passes its **defect-detection** assertions against
both source and installed PHP. Passing it does not mean correct ownership.

Scratch diagnostic:
`<scratch>/profile-binding-ownership-probe.php`

SHA256: 32C42A5D97C0851B5F5990C853198259F5755DC928FEABCFE50BBE9E3E1A8B62.

Run using the dedicated synthetic PostgreSQL database only:

~~~powershell
wsl.exe -d <wsl-distro> --exec sudo -u postgres php <scratch>/profile-binding-ownership-probe.php
wsl.exe -d <wsl-distro> --exec sudo -u postgres php <scratch>/profile-binding-ownership-probe.php /var/www/html/Synthserver
~~~

Each run: 21 checks. The diagnostic loads actual runtime/model classes,
extracts and executes the current input ensure/link/read block verbatim, and
uses real SQL. Synthetic profiles/templates/columns/indexes are transaction
local; final assertions verify rollback and fixture absence. No production
database, providers, voice files, game or credentials are used. The first
attempt needed explicit synthetic reference/base plugin fields to avoid a
missing fixture game_plugins table; this was fixture setup, not a product bug.

Confirmed failures:

- Two actors named Settler reuse the same core_npc_master row. The second
  actor changes its actor_identity_id, refid, gender and base, while inheriting
  the first actor's private personality, manual voice and extended data.
- lock_profile=1 does not prevent reassignment or mixed physical identity.
- The first actor loses its canonical profile link. Returning to it flips
  the link back but retains physical metadata written for the other actor.
- The same form/plugin in another playthrough reuses that same private row.
- All actors use the identical md5(display_name) active-profile token.
- Renaming a canonical actor creates another name row, then hits the unique
  actor_identity index when binding. In the diagnostic's caller transaction,
  the unchecked SQL error aborts subsequent reads until rollback. This is not
  evidence of the exact production error presentation or a game freeze.

## Required storage contract

1. A native actor owns one profile by actor_identity_id (existing identity
   includes game/form/plugin/playthrough). Its row ID and profile token
   survive display-name changes. Two same-name actors are supported, not
   renamed to synthetic aliases or rejected as a permanent solution.
2. Keep actor_identity_id unique. Replace global npc_name uniqueness with
   uniqueness only for the unbound legacy/template lane. Update every affected
   ON CONFLICT clause in the same cutover; dropping the constraint alone is
   not deployable.
3. Preserve existing row IDs, linked actor IDs, manual fields, voice metadata,
   history foreign keys and existing profile tokens wherever valid. Allocate
   identity-derived stable tokens for new native rows; never recompute them
   from a renamed display name. Check token collisions before enforcing a
   new constraint; do not silently delete or merge rows.
4. Never automatically reassign an existing linked row by name/refid. Existing
   rows may already contain mixed history: no automatic backfill can prove
   their original owner. Retain current evidence and settings; expose any
   explicit future repair as a separately selected operation.
5. Seed new actors from deliberate factory/configuration templates, not a
   same-name native actor's private fields. Preserve current template and
   creature voice mapping without conflating it with actor ownership.
6. Native generation/profile revisions remain immutable and save-visible.
   Manual configuration and current snapshot observations are distinct from
   generated biography/history; do not revive the legacy global restore path.

## Coordinated caller inventory

| Surface | Current problem / required cutover |
| --- | --- |
| lib/synth_runtime.php:synth_ensure_npc | Name upsert; add atomic identity-keyed native creation, keep unbound legacy conflict handling distinct, check SQL failures |
| main_synth_pipeline.php input binding | Remove name reassignment; resolve acknowledged actor and return its exact row under existing request lock |
| lib/core/npc_master.class.php | Name/refid/token reads, create/update and metadata name writes need explicit native ownership or unbound legacy scope; ID management must retain ownership |
| lib/chat_helper_functions.php:synthSwitchActiveNpcProfile | Use resolved row token, not md5(name); preserve exact action-result/rechat owner |
| lib/data_functions.php:createProfile | Creation/overwrite must not reset native tokens or select another actor by name |
| lib/synth_profile_scope.php | Name-keyed bindings reject duplicate-name batches; key queued targets by stable identity/row and retain names as labels |
| lib/synth_profile_state.php and profile workers | Frozen inputs/results and guards need stable queued targets, preserving origin/hash/generation validation and save ancestry |
| gamedata.php nearby/profile ingestion | Legacy name upsert and base/coordinate writes must not alter native rows; native observations require captured ownership |
| lib/core/npc_master.class.php:restoreNPC | Legacy delete/reinsert omits actor_identity_id; ensure native rows never enter this restore lane |
| ui/core/npc_master.php backend handlers | Name-based create-result lookup and md5(name) edit/clone paths must respect new lanes and preserve native keys; ui/npc_upload.php edits separately name-unique bio templates |
| Profile selection/import/export/worker status UI | Distinguish same-name actors using canonical metadata and submit stable IDs; required local Claude Opus 5 delegation before UI work |

This inventory is based on current source. Inspect callers and tests before
editing; it is not a claim that every listed path is currently reached by v2.
Also audit direct name/refid/token consumers and profile assignment/voice
caches after key changes. Reject ambiguous name-only routing where there
is no canonical owner; direct target requests still need to support two
same-name contextual actors through their explicit owner.

## Implementation and acceptance order

1. Implement storage migration plus identity-aware creation/resolution in a
   transaction. Validate fresh install, repeated upgrade, rollback, existing
   IDs/voice/configuration preservation and duplicate-name rows with real SQL.
2. Cut over native input/profile switching and legacy writers that would
   collide with native rows. Verify cancellation and caller transaction
   ownership; no network/provider work inside the database transaction.
3. Cut over batch profile queues and worker inputs/results to stable targets,
   including two same-name actors in one batch and renames after admission.
   Preserve snapshot labels without invalidating the canonical owner.
4. Update bounded management/import/export surfaces and review delegated UI
   changes. Preserve manual voice overrides, row IDs and old valid profile
   links. Audit missing/ambiguous owner handling and legacy behavior.
5. Reverse the diagnostic's defect expectations into correctness checks. Add
   only focused regressions to existing tests, plus synthetic SQL probes:
   A/B/A, same form across playthroughs, different plugins, rename/case changes,
   same-name queued batch, forged/stale owner, save/fork/reload, concurrent
   creation, failed SQL rollback, legacy import isolation and exact TTS profile.
6. Exercise actual main entry -> profile -> prompt -> configured connector ->
   text/TTS routing with synthetic data before local server deployment.
   Run relevant PHP, release and protocol checks. Back up changed runtime
   files and preserve production configuration/voices/database during deploy.
7. Ask for manual flat acceptance and later independent VR acceptance. No
   successful fixture/build/deploy substitutes for those gameplay gates.

Do not deploy a schema-only or name-rejection-only workaround as the finished
fix. No runtime change or deployment was made in this audit checkpoint.
