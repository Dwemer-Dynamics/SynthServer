# Synthserver open-alpha acceptance

## Auto Chat

See [AUTO-CHAT.md](AUTO-CHAT.md#automated-checks).

- [ ] Automated: the listed database-free PHPUnit cases, the disposable-PostgreSQL history case and
  the paired `testCompiledClientAutoChat` loopback, on the exact client and server commits.
- [ ] Fake provider: exactly one rewrite call per Auto Chat root; ordinary routes keep their counts.
- [ ] Older-save load after an Auto Chat turn: the generated row follows the saved-ancestry cutoffs.
- [ ] Flat in game and VR in headset, separately: selection, typed and voice turns, the generated
  player line and the NPC reply.
- [ ] Physical microphone input and heard player voice, flat and VR independently.

## Schema migration owner

See [SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md).

- [x] `php -l` on the changed PHP files; `git diff --check`.
- [x] Database-free wiring probe against Doctrine Migrations 3.9.8 and DBAL 4.5.0: configuration,
      `synth_meta` version table, all-or-nothing, and discovery of the transactional baseline class.
- [x] `composer.lock` adds only `doctrine/migrations` 3.9.8 and `symfony/stopwatch` v7.4.8; every other
      locked package and reference is unchanged.
- [ ] `composer validate`, `install --no-dev` and `check-platform-reqs --no-dev` under the PHP 8.2.35
      platform pin (hosted CI).
- [x] `SchemaMigrationOwnerTest` (18 tests) on disposable PostgreSQL 15.19 with pgvector 0.7.0 and PHP
      8.4.27. It covers populated legacy recognition with unchanged schema and rows, rerun, and
      fresh-install convergence. Ten structural drift cases are refused without legacy repair, and extra
      objects are allowed. Damage after the recorded baseline is refused and left unrepaired. A partial
      schema is refused, then recognized once repaired. It also covers baseline revert, unknown-record
      refusal before legacy work on both entries, the direct `db_updates.php` lock wait and its
      open-transaction refusal, lock wait and bounded wait, two concurrent fresh bootstraps, and a killed
      fresh bootstrap followed by a rerun. `NamedGiftUpgradeTest`, `FalloutDlcContentPackTest`,
      `SettingsRepositoryTest`, `FalloutPronunciationDatabaseTest` and `RequestStreamingTest` pass in the
      same run.
- [x] Primary: `SchemaMigrationOwnerTest` (19 tests, 560 assertions) and `NamedGiftUpgradeTest` on disposable
      PostgreSQL 17.11 with pgvector 0.8.7 and PHP 8.4.27, then the full `tests` directory in the same run
      (run `final-pg17-r3`: 1368 tests, 27765 assertions, 0 failures, 0 risky, 5 deprecations;
      `NamedGiftUpgradeTest` 1 test, 17 assertions; source unchanged since) with the paired client source mounted
      read-only. The PostgreSQL 15 runs above remain compatibility evidence. The added case shows a later
      transactional migration runs after the baseline while the runtime gate it satisfies is still unmet,
      and that its failure rolls back its DDL and leaves no version record.
- [x] Release-tree audit of the tracked files passes; the six new untracked files were scanned separately.
- [x] `composer validate` and `check-platform-reqs --no-dev` pass against the locked vendor (PHP 8.4.27).
- [ ] The same under PHP 8.2 and hosted CI with the new lock.
- [ ] Upgrade of a real installed database copy (not the fixture) through the deploy script.
- [ ] Deploy and in-game play: none claimed.
- [x] Legacy playthrough snapshots gated (automated check only): the manager page is read-only and refuses
      every non-GET/HEAD request before loading anything; the clone/drop/recreate helpers and the Timeline
      Break autosnapshot refuse without touching the database; readiness only reads. Legacy rollback pruning
      therefore proceeds without an automatic snapshot. On disposable PostgreSQL 17.11 with pgvector 0.8.7
      and PHP 8.4.27: `SchemaCloneIdentityTest` (3 tests, 42 assertions, no risky), `SchemaMigrationOwnerTest`
      (20 tests, 637 assertions), a disposable HTTP check (1 test, 291 assertions) that GET on an empty and a
      populated install and forged create/switch/delete/unknown requests leave schemas, profiles, data, both
      ledgers and `vector` columns unchanged, then the full `tests` directory (1371 tests, 27883 assertions,
      0 failures, 0 risky, 5 deprecations). The server runs used a snapshot identical at runtime to the final
      source, which adds only one tooltip CSS declaration and these docs. The HTTP check was then rerun on the
      final source, and its captured pages were rendered at 1440x1000 and 390x844 with 0 failures (keyboard
      focus and Escape, no horizontal overflow, injected names rendered as text). Those were captured pages
      with fixture stats and external assets blocked, not the deployed UI. Real install, deploy, gameplay and
      hosted CI (Actions disabled) are not verified.
- [ ] Before snapshots are re-enabled: snapshots record the latest migration version and restore refuses a
      mismatch with the `synth_meta` ledger. Recovery of an install already switched by the old UI uses a
      verified backup or reviewed repair, approved separately; deleting the ledger or `revertTo` is not
      recovery.
- [x] Schema drift check (`php tools/schema-check.php`, read-only) on disposable PostgreSQL 17.11 with pgvector
      0.8.7 and PHP 8.4.27, in a private network namespace: a fresh Doctrine-baseline install matches (0
      differences, ledger current); an extra table and column are allowed and counted; an unknown ledger record
      is drift; a hand-made drift (changed column default, renamed column, dropped index, dropped table) is
      reported by object name and status only (exit 1); a wrong password, an unreachable server, a stale
      manifest, a missing manifest and bad usage exit 2 or 3 with fixed messages and no secret. Each tool run
      sent 11 statements (BEGIN READ ONLY, SET LOCAL, SHOW, SELECT, ROLLBACK), none a write or an advisory lock,
      and a `pg_dump` digest of `synth` and `synth_meta` was identical before and after both the clean and the
      drifted run. The full `tests` directory passes on the same source (1380 tests, 32388 assertions, 0
      failures, 0 risky, 5 deprecations). Not tested: an installed database, a PostgreSQL 15 server, hosted CI.
- [ ] Open for review: the meaning of "CHIM 4.0 planning format", the PostgreSQL major the manifest targets, and
      acceptance of the D2 table naming ([SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md#open-questions-for-review)).

## Doctrine settings read pilot

- [x] Measured on Debian PHP 8.2.35 (`pgsql`, `pdo_pgsql`, `ctype`, `mbstring`), Debian
      Composer 2.5.5 and PostgreSQL 17.11.
- [x] Lock checked with that PHP and Composer: `validate` passes (exit 2 on a stale lock) and
      `check-platform-reqs --lock --no-dev` passes.
- [x] Actual install as `nobody` from the lock: 23 packages, zero dev packages.
- [x] Parity with the legacy reads: quoted, empty, nonexistent and SQL `NULL` values; direct
      writes visible within a transaction and gone after rollback.
- [x] A backend terminated mid-request ends in the same `SQL: bad connection.` stop as a plain
      legacy `execQuery()`. This is existing `re_connect()` behavior, not a regression.
- [x] Full PHPUnit suite on disposable PostgreSQL, on upstream base `3181ed18`: 1322 tests,
      26848 assertions, zero skips or failures, including `SettingsRepositoryTest`.
- [x] Native loopback, server snapshot `b38d2c57` with client `59faa806`: 4 tests, 1291
      assertions, including Auto Chat. Real native server `main.php`, the compiled client driver
      and a fake provider.
- [x] Migration: a clean bootstrap gives 54 settings and a legacy seeded upgrade gives 55, with
      byte-identical `synth` schema dumps; a rollback to the legacy build reads the sentinel
      setting.
- [x] PostgreSQL 15.20 and 17.11 parity, and a cold 65536-key `values()` read that succeeds.
- [ ] Hosted CI with the new root `composer install` step.
- [ ] Deploy and upgrade of the WSL install from an earlier tree (vendor staging, Apache serving
      `/talk` and the speech language read).
- [ ] In-game play and live providers.

## Everyone audience

See [EVERYONE-AUDIENCE.md](EVERYONE-AUDIENCE.md#evidence-boundary). These are off-game results on exact
commit archives of the combined pair with vanilla dialogue witnesses (server `d288852`, client `ed4b155`),
run on Linux with disposable PostgreSQL and the source hash unchanged after each run. Synthetic actors over
real HTTP are not `PluginSession`, game, microphone, headset, performance or deployment proof.

- [x] Full PHPUnit on PostgreSQL 17: 1313 tests, 26641 assertions, 0 failures, 0 errors, 0 skipped.
- [x] Migration: a fresh install, an upgrade from a pre-Everyone install that keeps seeded UTF-8 rows
      byte-identical, and an idempotent re-run of `synth_protocol_schema` 20261113002.
- [x] Paired run (3 tests, 1239 assertions; the count varies with an unforced concurrency case): synthetic
      actors went through the compiled client driver and codec over real loopback HTTP. A duplicate while the
      provider is held returns `request_pending` and writes nothing. Delivered and undelivered native replies
      stay distinct in heard history and in the witnesses' next prompts. Ordinary emitted history is visible
      without a receipt. Continuations stay bounded.
- [x] Quest knowledge on PostgreSQL 15 with pgvector (original 6 tests, 198 assertions; Everyone probe 2 tests,
      346 assertions): an ordinary Everyone report reaches only the frozen exact responder on both consumers;
      bystander Nick gets no quest block; older anchors cut the full prompt off; native-origin reports stay
      excluded as an inherited producer gap outside this change. Cancelled-source exclusion is proven for the
      quest block only, not the generic full prompt.
- [x] Private-history control (1 test, 757 assertions, hand-posted v2 events): Whisper reaches only its
      listener; a Close continuation selects Nick from the original group and not the newcomer Deacon. Across
      ordinary and native Everyone Standard and Shout turns, Piper keeps her own private and witness rows, Nick
      keeps only his Close rows, and Deacon gets none of the private or vanilla markers. Early, mid and late
      saved anchors hold. Native readers keep the inherited 12-row budget and heard-only rows, so this does not
      show every private row persisting beyond that budget; unreceipted ordinary replies reach only the
      ordinary reader.
- [x] The shared global mode writer resets active sessions to Normal and advances the epoch. Standard and an
      invalid mode leave sessions unchanged, and a held gate times out without writing.
- [ ] The legacy `setconf`, requested-mode and Director reset call sites run at runtime. So far they are
      confirmed only by reading the source.
- [ ] Dashboard Audience fieldset, primary visual check: Apply saves Normal or Everyone, a restarted
      generation is refused, and a timed-out save reports unknown. This is blocked by browser URL policy, and
      the 14 automated headless checks (GET only) do not replace it.
- [ ] Flat in game, then VR in headset, separately.
- [x] Combined with vanilla dialogue witnesses: the results above and the witness loopback check below were
      rerun on the combined pair. Independent exact-source and runtime-evidence reviews found no new privacy
      blocker.
- [ ] Hosted Actions: unavailable and not passed for this pair. The local Debian 12 workflow equivalent is not
      the hosted Ubuntu image.

## Vanilla dialogue witnesses

- [x] Disposable loopback prompt check (fake provider, synthetic state): the line reaches the
  granted witness's ordinary and `/talk` prompts once each, and never a non-witness, a same-name
  actor or a save loaded from before the observation. A retry keeps one history row. Rerun on the
  combined Everyone pair (3 tests, 772 assertions). `first_observed` is dialogue-only, not
  physical-perception proof.
- [x] Earlier witness-only slice, full existing suite on a private disposable database: 1310 tests,
  0 failures, 1 skip; the skipped client-wiring test passed separately against the paired client.
  The combined Everyone pair's full suite (1313 tests, 26641 assertions) had 0 skips; see above.
- [ ] Model answers: ordinary and `/talk` answers from witnesses reflect the observed line, and
  answers from non-witnesses do not.
- [ ] Flat in game with the paired client; VR is not offered.

See [VANILLA-DIALOGUE-WITNESSES.md](VANILLA-DIALOGUE-WITNESSES.md). Disposable checks only.

## Native conversation World Knowledge

See [WORLDKNOWLEDGE-PARITY-V1.md](WORLDKNOWLEDGE-PARITY-V1.md). Disposable loopback and suite checks only.

- [x] Disposable loopback: for the same selected topic, an eligible advanced-tier NPC receives its advanced
  text and not its basic text, and a basic-tier NPC the reverse. The separate vocabulary article stays basic.
  Each turn makes exactly one provider call.
- [x] Controls: a test-authored prewar tag and profile `WORLDKNOWLEDGE_INFINIUM=false` give neither tier;
  the disabled turn records a `disabled` audit row without catalogue data. Unmatched input selects no
  topic article and makes no extra call; the vocabulary article may remain.
- [x] Existing World Knowledge, quest-access, everyday-lore and native dialogue suites pass unchanged.
- [ ] Flat in game, then VR independently: a lore question to an eligible NPC reflects the article.

## Native conversation quest knowledge

See [QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md). Disposable loopback and suite checks only.

- [x] Disposable loopback: after a named When Freedom Calls report to Piper, ordinary and native prompts
  carry the same attributed block; Nick receives no report, and no route sends the objective, editor ID or stage.
- [x] Preston receives the same authored background on both routes; a same-name actor receives none.
  Each turn makes one provider call.
- [x] The explicit-owner reader keeps recipient, earlier-save, runtime-lane and completed-input checks.
- [x] Disposable loopback: a report typed on a native turn in each of the four modes reaches Piper on both
  routes without an origin-policy row; Nick and a same-name actor receive none.
- [x] Disposable loopback: completed, cancelled-while-claimed and completed-then-cancelled native sources,
  earlier/later saved anchors, a VR session and a private Whisper with captured unaddressed actors.
- [ ] Everyone native source: declined by this reader; merging #36 alone does not enable it. Support
  needs coordinated #36 reader integration/validation under the existing hold, then runtime evidence
  for the addressed responder only.
- [ ] Disposable fixture target Piper (Wright): ordinary asks return `backend_request_failed` with no
  provider call at both 7950230 and this candidate; native turns succeed.
- [ ] Flat in game, then VR independently: an informed NPC recalls the report on a native turn; another does not.

## NPC biography and actor stats

- [ ] Flat in game: compare all seven SPECIAL base/current values with the next provider request, including an older-save load.
- [ ] VR in headset: independently verify the same capture and prompt behavior.
- [ ] Existing installation: review the biography repair dry run and back up the database before an explicitly authorized apply.

## Private narrator

- [ ] Reviewed installation upgrade and paired client negotiation (no deployment is part of this change).
- [ ] Flat and VR game acceptance independently: saved revisions/selection/transcript, cancellation and next-NPC privacy.
- [ ] Live configured model, speech recognition and voice-provider acceptance. Disposable tone fixtures prove no voice quality.

## Optional companion combat comments

- [ ] Verify the paired flat client's participant, cancellation and delayed-output
  cases in game; check that ordinary history contains no invented player line.
- [ ] Verify existing RPG enable/chance and Control mode priorities.
- [ ] VR: keep the capability unavailable until its separate capture/admission work
  and headset acceptance pass.

See [the feature note](COMPANION-COMBAT-COMMENTS.md). No deployment or gameplay proof.

## Maintainer biography extractor

- [ ] Review named-cast classification, source policies and original draft field
  evidence from the [external extractor workflow](FALLOUT-BIOGRAPHY-EXTRACTOR.md)
  before importing any candidates. Structural exports and synthetic CLI probes
  do not establish full cast coverage or gameplay acceptance.

## Stage 1 native typed turn

- [x] Apply schema 20261109001 on a disposable PostgreSQL 15 database (reapply is idempotent).
- [ ] Apply schema 20261109001 on the reviewed install.
- [x] HTTP + disposable database: registered placed NPC `/talk` turn streams lines, enqueues
  profile speech and stores generated utterances; receipts move only completed audio
  to spoken history at the receipt-time frontier (fake loopback provider).
- [x] HTTP + disposable database: duplicate receipts replay; conflicting, stale-generation,
  unknown-line and wrong-character receipts are rejected; >12 lines, >1000-character
  lines, truncated and malformed streams end the turn with an error and no fallback.
- [x] Provider cancellation and status (candidate `32d741e`): owner cancels stop a fake provider
  stream in about 250 ms or before connecting; non-2xx bodies that are valid SSE give
  `provider_failed` with no text, dialogue, jobs or error-body history. Recording-provider and compiled-client
  loopback checks pass. See HANDOFF.md.
- [ ] Agree and verify client/server deadline and disconnect behaviour: defaults are 30 seconds
  for the client and 60 seconds for the provider; only durable owner cancellation is honoured.
- [ ] Playback de-duplication of a replayed line through a real automatic identical-request retry.
- [ ] HTTP + disposable database: a `/talk` prompt uses the save-visible dynamic profile revision
  for the exact identity. The compiled-client loopback check covers this with revisions that the
  production profile worker publishes. The first `/talk` and the older load see the revision
  accepted before the older anchor. The newer load sees the revision accepted between the
  anchors. No prompt sees the revision accepted after the newer anchor. A same-name sibling
  identity reads none. The native strict read, the ordinary route's projection and the prompt
  agree. Each test-sent refresh is admitted by real `main.php`, which queues exactly one
  `actor:<id>` batch; the production worker drains it. A private-clone run passed; the
  exact-commit run is pending. Still open: a cancelled worker result and a sibling save branch
  that has its own acknowledged revision.
- [ ] HTTP + disposable database: a failed saved-profile read ends the native `/talk` with the
  `backend_request_failed` terminal. It writes no input, history or utterance and makes no
  provider call. A disposable probe passed over real `main.php`. Unit coverage is in
  `NativeDialogueTurnTest`. The probe is not tracked.
- [ ] Flat and VR in game, separately: a typed native `/talk` after the player and the responder
  are injured reflects each one's condition, and a later turn after healing reflects recovery.
  Unit coverage (snapshot only, fake provider) is in `NativeDialogueTurnTest`.
- [x] Stage A increment 1: the same HTTP + PostgreSQL 15 probe passes unchanged against the
  namespaced `DialogueManager` services behind the transitional edge.
- [x] Stage A increment 2: `/talk` and receipts dispatched to `api/v2/` before
  `main_synth_pipeline.php`; transitional edge removed; owner bound to the output before liveness;
  top-level architecture layout (see HANDOFF.md). Exact-head source review; the dispatch is also
  traced over HTTP below.
- [x] Stage A increment 2 over HTTP + disposable database (loopback PHP CLI server, four workers,
  fake provider): a mid-stream cancel from a second connection closes the turn and refuses replay.
  At the terminal commit a cancel queued first on the lock wins; a completion that wins is stored
  byte-exact and replays without a second provider call until a later cancel revokes it, after
  which replay is refused (5 tests / 384 assertions; startup candidate `d41e1fc` plus the
  `main.php` change later committed as `3e637c6`). Production Apache streaming is not covered.
- [ ] Stage A increment 2 over HTTP + disposable database: cancel/supersede contending with the
  initial canonical claim. The terminal-commit concurrency probe does not isolate it.
- [x] Stage A increment 2 over HTTP + disposable database: a receipt's first write requires an
  unrevoked origin turn and the line's emitted route. Cancelled, in-flight-superseded and
  completed-then-cancelled origins and generated-but-unemitted lines are rejected
  (`receipt_owner_mismatch`). An emitted line of an earlier unrevoked turn stays receiptable at
  the receipt-time frontier with its original provenance. A v2 database boot failure answers the
  sanitized HTTP 500 `backend_request_failed`; the legacy pipeline keeps its constructor exit
  (5 tests / 237 assertions; same startup candidate).
- [x] Stage A increment 2 over HTTP + disposable database: v2 route inclusion. An include trace
  shows only `/talk`, receipts and their replays reach `api/v2/dispatch.php`; `init`, `context`,
  cancel and unmarked typed text load `main_synth_pipeline.php`.
- [ ] Legacy parity of every other event, including unmarked typed text. So far only coarse HTTP
  route/output for unmarked text and nine legacy action/rechat unit suites (118 tests / 1706
  assertions at `768d87c`, equal to the pre-cutover baseline); no every-event or full semantic comparison.
- [ ] Full PHPUnit suite green on the Stage A head (two broad grouped reruns were stopped after
  more than 10 minutes and are not counted).
- [x] Stage A over HTTP + disposable database: two placed NPCs sharing a display name keep separate
  models, queued TTS inputs, cache seeds and heard history across older/newer saved-anchor
  reloads (1 test / 688 assertions with pinned voice samples, 1 / 641 with the shared fallback;
  at `d41e1fc`, with a no-op TTS worker).
  Each audience was only the addressed NPC; a shared same-name audience is not covered.
- [x] Startup boundary over HTTP + disposable database with a stand-in start script: explicit
  `/talk` and receipts, including a v2 database boot failure, never load or probe the background
  processor helper; legacy `init`, `context` and unmarked typed text reach the helper's on-demand
  check. With the processor missing, `init` and, separately isolated, unmarked typed text each
  recorded a launch; `context` reached the check with no launch independently isolated. A healthy
  processor is probed without a relaunch (3 tests / 91 assertions; same startup candidate).
- [ ] Real background supervisor and TTS worker pickup, compaction, and `/talk` streaming through
  production Apache.
- [ ] Real configured provider and TTS synthesis with audible playback, flat in-game and VR
  in-headset `/talk` evidence. Fake loopback provider output, queued TTS inputs and hand-posted
  receipts do not count.

## Generic gift inventory exchange

- [ ] Flat rebuilt package: verify that “I have a gift for you, Jun” opens
  the exchange, a named offer transfers only the requested item and quantity,
  and View Diary opens the selected NPC's native entries.
- [x] Install the reviewed coordinated gift-menu DLL/PEX/backend update; installed hashes and server health verified.
- [ ] Flat: "I have a gift for you" opens the exact addressed NPC's normal free
  inventory exchange; the NPC can decline the offer in character.
- [ ] Give a weapon and ammunition, close the menu, and verify both inventories,
  the paired receipt, and personality-based gift-batch evidence.
- [ ] Opening/closing without donating, cancellation/load, a busy NPC and wrong
  menu ownership produce no received-gift credit.
- [ ] Named offers including "I have a gift for you, X" continue to use the direct
  exact observed Player-to-NPC transfer without opening the generic gift screen.


## Player-pack follow-up wording

- [x] Install the reviewed one-file backend wording correction; installed hash and server health verified.
- [ ] Marcy's “grab the submachine gun” selects TakeItemFromPlayer and physically
  transfers the exact player stack with a successful paired receipt.
- [ ] Complete and save personal gift valuation despite the separately observed
  cancelled post-transfer continuation.


## Player-pack gifts

- [x] Disposable PostgreSQL: clean database bootstrap and populated upgrade from
  the October 24 flat alpha RC3 register the same named-gift action. Existing
  profiles, NPC profile links, memories, summaries and action preferences are
  preserved. A failed insert leaves the migration pending and retry succeeds.
- [x] Install the coordinated client/backend candidate after reviewing the concrete plan; all installed hashes and server health verified.
- [ ] Flat: Jun takes the exact requested unequipped weapon from the player pack;
  paired inventory counts and receipt agree with physical gameplay.
- [ ] Compare different NPC reactions to gifts using their personalities/goals;
  confirm lasting relationship evidence follows the verified handoff.
- [ ] A failed/busy/quest-bound transfer produces no received gift; retrying the
  same receipt produces no second relationship reward.


## Manual diary shortcut and reader

- [x] Flat: user confirms F6 requests the crosshair NPC's diary; Sturges' entry
  completed on the first attempt in about 33 seconds, with valid published
  ownership/hashes and dashboard visibility (4 November 2026).
- [ ] VR: verify a controller-accessible diary request and saved entry in-headset.
- [ ] Native `/talk` latest-diary context: loopback evidence only (fake provider, synthetic
  dialogue source). Flat in-game and VR use are not verified. The ordinary-route omission remains open.

## Location ancestry

See [LOCATION-ANCESTRY.md](LOCATION-ANCESTRY.md). Ingress tests and fixtures only so far.

- [ ] Flat, with the paired client: init returns `location_ancestry: true`, and a context
  from a named interior stores `world.location_ancestry` in its snapshot unchanged.
- [ ] In game, the next eligible prompt names the interior and its
  containing location, and says nothing about parents past an unresolved end.
- [ ] An older client (no capability) and a client without the acknowledgement keep
  working with `location_ref` only.


## Jev companion command candidate

- [ ] Compare typed and voiced Wait Here / Stop Waiting on the exact calm follower:
  one typed intent, verified physical receipt, owned consequence and later recall.
- [ ] Confirm the action completes before its result-based acknowledgement; record
  classifier, input-to-dispatch and intent-to-receipt timings separately.
- [ ] Confirm ordinary NPCs, unknown/stale state, quoted/negated/multiple requests,
  cancelled turns and provider abstention retain the documented normal path.
- [ ] Test Stop and both newer/older saves; selected JEV choice and dispatched
  intent alone cannot establish successful execution or enduring waiting.

This candidate extends existing routing and diagnostics. It adds no new native
executor, collector, background planner or provider route.

## Flat return feedback and saved follower tasks candidate

The user reports that followers now keep moving and rechat no longer freezes
distant NPCs across a settlement. Ordinary NPC approach still failed in the
tested installation. Attack remained unconfirmed; diagnostic evidence found no
Attack intent and an empty captured enemy-awareness list in those attempts.
The observed rechat failure was a quest-context SQL timeout before generation.

- [ ] Verify the repaired repeating approach package and robot hearing in game;
  a human NPC's reported eligibility rejection is not yet reproduced.
- [ ] Prove a player Attack order using an exact client terminal result and
  observed behavior, separately from enemies initiating combat.
- [ ] Recheck input captions and full reply latency after the quest query fix.
- [ ] Verify a follower physically visits the named NPC, delivers the requested
  topic and receives a reply before normal follow resumes.
- [ ] Verify an instruction given away from the destination waits for return;
  ambiguous or unknown actors/locations cannot start movement.
- [ ] Check actor-owned task notes and post-acknowledgment save checkpoints:
  older saves exclude later tasks; a save before completion restores pending
  work; a new save after completion retains completion.
- [ ] Exercise Stop, combat, unload, failed speech and interrupted requests;
  release only the task's temporary navigation and do not claim undelivered work.

Focused native/PHP tests and protocol fixtures remain automated evidence. The
candidate migration and paired installation have not established these gameplay
checks, and this work does not add VR acceptance.

## Flat gameplay evidence — 24 October 2026

- Codsworth and Jun completed a public NPC exchange; both movement holds released.
- Both directional affinity-3 updates were readable before saving and after
  reloading the new manual save. Loading an older save excluded the later updates;
  the newer records remained intact. Jun was absent from that older scene, so his
  exclusion was checked through saved ancestry rather than a live Jun reader.
- Earlier quest-plan testing covered teaching Codsworth, a follow-up without the
  quest title, revised recall after save/load, an uninformed Jun and an older save.
  Formal whisper, cloned actors and overhearing still need separate coverage.
- The normal launcher opened after the startup repair and Codsworth replied.
  Server records confirmed the ChatGPT route and `gpt-6.1-sol` for that reply.
  Other configured models and the existing server profile fallback remain available.

This is flat alpha evidence. VR compiles but has no headset acceptance from this
session. Broader scene/action transitions and sustained sessions remain open.
Earlier unchecked scenarios below remain separate unless explicitly covered here.


Status: **OPEN**. Unchecked items remain open. The historical feature baseline was `bcb0c06`
with client `f34da88`; see [HANDOFF.md](HANDOFF.md#current-cleanup-source) for current cleanup
branches. HTTP tests, PHPUnit and the release audit are prerequisites. They are not game
evidence. In-game flat and VR checks are listed in the SYNTH client's `docs/ALPHA-ACCEPTANCE.md`.

## Current dialogue return fixes

- [ ] Optional Jev actions: separately configure and compare off/on behaviour
      for existing typed/spoken actions, Talk/refusal, ambiguous requests and
      missing arguments. Check actual action results, Stop/load cancellation,
      provider fallback and first-speech/decision timing. Record real provider,
      flat gameplay and VR headset evidence separately. This optional candidate
      does not supersede accepted quest/relationship checks. See [JEV-ACTIONS.md](JEV-ACTIONS.md).

- [ ] Install the reviewed combined candidate and complete a new text/audio return
      check. Automated tests and a sealed archive do not establish game acceptance.
- [ ] A spoken command completes STT, keeps its canonical admitted event unchanged
      and reaches dialogue/action processing. A valid recognized-empty LocalWhisper
      success ends without dialogue/actions and shows speech not recognized; HTTP,
      transport and invalid responses remain failures. Check both quiet listening
      and actual speech without treating an empty result as proof of silence.
- [ ] Typed and spoken Wait Here dispatch a canonical zero-argument action to the
      selected NPC; Release Wait releases that hold. Catalog disable settings and
      flat/VR capability boundaries remain authoritative.
- [ ] Current captured combat identification overrides older species guesses.
      When the replying NPC has no identified opponent, it admits uncertainty.
- [ ] Flat enemy awareness: visible hostiles before an attack and enemies attacking
      a companion use the replying NPC's own visual observations. Recent witnessed
      identities expire within 30 seconds and on Stop/load, remain separate from
      current attackers, and never attribute a kill or victory. Enemy identities
      do not expand audience or private history readership.
- [ ] Public idle exchanges use two currently eligible NPCs and a real root reply;
      later rechat requires that reply's completed parent proof. Private input and
      interrupted or changed scenes cannot authorize continued speech.

## Managed ChatGPT dialogue route

- [ ] Bridge settings recovery: after the reviewed combined bridge update, make a
  harmless Codex settings edit between game turns. The next primary request must
  recheck isolation and recover without using profile fallback, while preserving
  the user's setting and private proof/config files. Verify failed isolation and
  executable/source replacement remain explicit failures. Source fixtures and a
  synthetic current-executable recovery do not establish this gameplay result.

- [ ] Bridge latency return check: after the reviewed bridge update, compare cold
  and warm response timing with the same configured model and real gameplay.
  Confirm fresh NPC/save contexts, rapid Stop/load cancellation, bounded idle
  retirement and correct speech/actions. Synthetic transport timings do not prove
  a game latency improvement; memory retrieval remains a separate cost.

- [x] Startup regression checks: desktop executable discovery, zero-tool isolation,
  primary reply validation, preserved configuration on failure and explicit errors
  without another LLM. Windows wrapper refuses unverified/old/wrong-model health.
- [x] Flat startup return check through the normal launcher after installing the
  reviewed startup update. Synthetic readiness is separate from game acceptance.

- [x] Focused adapter tests cover text-only boundaries, JSON validation, optional
  action amounts, provider fallback, contention and fresh request ownership.
- [x] Current-binary stub proves tool/context isolation; synthetic live checks
  exercise Codex, forced OpenRouter fallback and SYNTH driver parsing.
- [x] Native `/talk` ignores the selected model slot (primary connector only); Home, runtime status and one
  `[talk]` log line per slot change now say so. Routing is unchanged; disposable-stack evidence only.
- [ ] Comparative in-game model-slot, latency, character quality, cancellation,
  fallback and sustained-load evidence. Backend/provider proof does not satisfy
  this gate. See [CODEX-LOCAL-PROVIDER.md](CODEX-LOCAL-PROVIDER.md).

## Quest-aware knowledge

- [x] Backend integration validation: owned direct reports, saved ancestry,
      actual action/rechat responder, narrator journal role, malformed evidence,
      exact participant bindings, protected article replacements, and preserved
      prewar/vocabulary/equipment behavior. See [QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md).
- [x] Quest query performance validation: ordinary and materialized ownership
      projections return identical evidence fields, exclusions, order and bounds.
      Only the quest reader opts in; lock and statement timeouts remain unchanged.
- [x] Focused natural follow-up validation: limited English plan/settlers
      references use a unique earlier direct player report, preserving source,
      actor, save, cancellation, audio and responder boundaries. Ambiguity,
      disavowal, quotation and unrelated topics cannot infer quest identity.
- [x] Direct exact-title noun assignment repair: explicit qualified
      seeds and recovery from unnamed noun barriers while retaining named-quest
      ambiguity and all source/save/private-reader exclusions.
- [x] Direct title/category validation: one generic quest/mission category can
      end the asserted exact-title binding; unknown suffixes, coordinated topics,
      named ambiguity and private reader/save/lane boundaries remain fenced.
- [ ] Flat natural follow-up return check: teach one displayed quest once, then
      discuss its plan without repeating the title; uninformed NPCs and earlier
      saves must not acquire the association. General first-time aliases remain open.
- [x] Cancellation repair validation: preserve already-completed player text/audio
      learning while rejecting replay and late work; cancelled interrupted inputs,
      context anchors, nonplayer descendants and actor/save isolation remain fenced.
- [ ] Flat return checks: teach one NPC by naming a freshly observed quest;
      another NPC and a same-name clone must not acquire the report. Load an
      earlier save and confirm the later report is absent. Review Preston's
      authored background without private objectives or confirmed outcomes.
- [ ] Complete a new quest-teaching conversation, then begin a later turn that
      cancels the previous context. Confirm that only the taught NPC retains the
      attributed report. Earlier erased receipts are not restored by the repair.
- [ ] VR journal/report ownership and headset acceptance. Flat evidence is separate.

## Native relationship dispatch

- [x] Focused automatic-dispatch validation: terminal and non-NPC rechat markers
      cannot mask completed dialogue in either evaluation lane. Provider stubs
      cover unchanged results, addressed NPC-pair directions and deduplication,
      distinct-responder private-source refusal, cancellation, lease replacement,
      retry limits and saved-publication boundaries. See
      [NATIVE-RELATIONSHIP-MIGRATION.md](NATIVE-RELATIONSHIP-MIGRATION.md).
- [x] Flat appreciation publication: a completed Codsworth exchange produced one
      accepted positive evaluation and one directed player relationship revision.
- [x] Historical response repair validation: a completed text/audio receipt must
      remain saved-visible after normal replay-cache clearing, while unfinished
      or unrelated failed requests, malformed receipts and earlier saves decline.
- [ ] Flat friendship visibility: compare a saved-visible baseline with the next
      completed evaluation after a natural appreciation conversation. A score
      change is optional. The initial publication check exposed lost visibility
      on the following turn; disposable repair validation is complete and
      saved-reader gameplay remains open. Broader gameplay and separate VR
      acceptance remain open.
- Historical NPC-pair regression coverage binds an accepted ready result to its
      flat prepared public direct-parent rechat with independent parent and responder proof,
      immutable response and exact effective scene. Both directed saved readers
      must retain published evidence after
      normal cancellation or a later chain closure, including loaded ancestors.
      Earlier saves, malformed or changed receipts, private/unprepared sources and
      actor/save/runtime-lane mismatches must decline. Shared history and live
      replay guards retain their existing behavior.
- [x] Prepared-responder regression validation: public A-to-B speech followed by
      prepared B-to-A speech must capture B's receipt, preserve the canonical
      request/context, publish both directed revisions once and retain them after
      Stop and a later reload, including a prepared rechat parent and a distinct
      later scene target. An earlier save must exclude them; private captured
      target inputs and malformed or unprepared responder evidence must decline.
- [x] Flat public NPC-pair return check: observe an addressed public NPC exchange,
      then close its conversation and reload a later save. Check both directed
      relationship readers against the same accepted publication. Scene movement,
      action-result ancestry and inventory overlays need separate evidence.
      The October 24 flat run retained both exact directed affinity-3 revisions
      across Stop, a new manual save and actual reload. An older save's ancestry
      excluded both later revisions while the original records remained intact.
      Codsworth's older-save reader was available; Jun was outside that scene.
- [ ] Separate VR public NPC-pair gameplay and saved-relationship acceptance.

## Install and upgrade

- [x] Selected content-source checks: official plugin/local identity separation,
      populated upgrade preservation, atomic bundle failure rollback and regional
      lore isolation. See [FALLOUT-CONTENT.md](FALLOUT-CONTENT.md). The roster remains
      incomplete; backend checks do not establish paired game behavior.
- [x] Listed character backlog researched and authored; suitable exact bindings
      prepared, shared Curie/Virgil physical-form aliases and Deacon cover profiles
      covered by disposable tests. Four special identity drafts stay withheld.
      This is source acceptance, not a claim of full cast or game acceptance.
- [x] Everyday Fallout vocabulary and 125-article catalog validated: tatos, caps,
      common chems and prewar public culture; ordinary dialogue receives bounded
      basic vocabulary with era and custom-article access respected. Speech-only
      radroach/radroaches hints preserve subtitle text and existing dictionary rows.
      See [LORE-ACCURACY.md](LORE-ACCURACY.md).
- [ ] Lore and pronunciation return checks: ordinary conversation uses tatos and
      caps naturally; characters distinguish familiar chems without acquiring
      specialist knowledge; selected TTS voices pronounce radroaches correctly.
      Subtitles, custom knowledge and prewar character limits remain intact.
- [ ] Character form return checks: Curie remembers her robotic life after the
      synth transfer, uses her selected robot/synth voice and retains the same
      companion history; Deacon's recurring costumes retain memory; mutant and
      human Virgil acknowledge their own past. Confirm effective Curie race/voice
      capture, preserved custom voices, and no accidental cross-reference history merge.
- [ ] Canon speech return checks: English prompts and direct acknowledgements
      leave Takahashi speaking only his Japanese phrase. Tony, Sparks, Ahab, Grun
      and the Vault 88 ferals retain nonverbal game behavior.
- [ ] Provider voice coverage audit: distinguish captured native voice labels,
      available XTTS samples, cached Cartesia clones/IDs and assigned ElevenLabs
      IDs. New templates do not create or validate hosted voices. Audible
      acceptance and absent-voice fallback remain open.
- [ ] Selected DLC return checks: actual newly encountered NPC receives its own
      template, local knowledge and assigned voice; no hidden identity or future
      quest outcome is revealed through static content. Commonwealth strangers
      retain regional limits, and existing authored sheets and memories persist.

- [x] Windows launcher source: offline build and deterministic ownership,
      saved-data mount/network/port, ordered start, readiness, cancellation and
      configuration checks. See [WINDOWS-LAUNCHER.md](WINDOWS-LAUNCHER.md).
- [ ] Windows `SYNTH.exe` user acceptance: starts the configured backend and required
      services, checks readiness, opens the web dashboard automatically, and
      provides a **Launch Fallout 4** button through F4SE. Existing configuration
      and runtime data survive normal startup; failures show an actionable message.
- [ ] Clean machine: following the README on a fresh WSL install gives `health.php` with
      a healthy database, schema and background processor on `127.0.0.1:8087`.
- [x] `health.php` from the exact source on a disposable PostgreSQL 17.11 (pgvector 0.8.7, PHP 8.4.27)
      on port 55432 set through configuration, with a synthetic custom config loaded once (marker): 200
      when current, including while both advisory locks are held (~0.27 s); 503 with the matching
      `schema_migrations` for an empty database and a true pre-ledger install (`missing`), an unknown
      record (`unknown`), a missing `vendor/`, an unreachable server and `SQL_ASCII`; `pending` only
      as an owner-run synthetic fixture. Fresh install and an idempotent rerun pass; no health
      request writes the log or database. `tools/bootstrap-database.php` exits 1 when unreachable.
- [x] Quickstart refusal: an HTTP fixture gives GET 503 and a refused POST with no writes. In a
      browser, the healthy screen showed, then with an injected unknown ledger record a real Save
      and Continue displayed the refusal. The GET recovery page was checked visually at 1280 and
      390 px with no narrow overflow. A browser check with the database unreachable was not run.
- [x] Focused PHP tests: 35 tests, 5,001 assertions, no failures, errors or skips; the release
      audit covers 548 PHP files.
- [ ] Hosted CI on the PostgreSQL 17 PGDG cluster (Actions are disabled).
- [ ] Re-running the database and deploy scripts is idempotent. Configuration, voices,
      uploads and the database stay intact without `-Reset`.
- [ ] Upgrading from an earlier SYNTH install applies only the pending migrations. Existing
      profiles and memories remain.
- [ ] The optional `start-dev.ps1` launcher serves the UI on its port, and a client with a
      matching `BaseUrl` connects.

## Configuration and UI

- [x] Disposable rendered-page checks: installed biography/DLC lore visibility in
      the canonical schema, distinct library/encountered-profile home counters,
      effective custom overrides, alias/tag search and lossless article editing.
      Paired gameplay and visual layout acceptance remain separate.
- [ ] Dashboard content-pack import: separate reusable character/lore files,
      stable identity metadata, versioned validation, conflict preview and atomic
      installation. The named biography CSV now matches the existing fifteen-column
      custom-biography importer; that path does not transfer factory identity mappings.
- [x] Manual character review: the user approved the 204-template reading copy on
      9 October 2026, requesting supported appearance expansion. Eighteen stock
      descriptions were refined; uncertain or dynamic traits remain conservative.
- [x] Manual lore review: the user approved the 125 articles on 9 October 2026;
      the article CSV remains byte-for-byte unchanged.
- [ ] Equipment additions: review 201 importable physical descriptions and 43
      named-variant reference entries. Check physical descriptions and exact item
      identity matches with the paired game. Source and disposable tests do not
      establish gameplay or per-instance mod evidence. Recognition controls remain
      separate development work.
- [ ] Paired gameplay: newly encountered actors receive the correct stable template;
      custom/locked profiles retain their edits. Check tatos, caps, common chems and
      radroach pronunciation with the selected voice provider.

- [x] Quickstart saves the LLM connector, TTS and STT. Missing optional keys fail cleanly,
      without PHP warnings. Disposable PostgreSQL 17.11 and PHP 8.4 with `error_reporting=-1`, mock
      provider only, a keyless LLM connector and no TTS, STT or ITT connector: every dashboard and
      connector page, a legacy game turn, `/talk`, the Quickstart saves (blank API keys, TTS `none`
      and a keyless TTS and STT choice) and the service manager tasks. The run logged 6 warnings
      and deprecations from web requests and 25 from the CLI before the fix, and 0 and 0 after.
      `/talk` without a usable TTS connector ends with `native_turn_ineligible` /
      `tts_profile_unsupported` and one `[native-turn]` warn line per request. Not covered: live
      providers, a real recall service, and a native turn that completes with speech.
- [ ] The STT diagnostic shows a clear message when no local sample is present and
      transcribes a locally supplied WAV.
- [ ] Theme: headings and monospace text render with the system fallback fonts.
      Desktop and narrow layouts, keyboard focus and contrast are unchanged.

## Paired runtime (with the client)

- [ ] Sourced named profiles and world/object descriptions behave in the paired game:
      identity, family history, expertise, current equipment and pre-war access.
      See [FALLOUT-CONTENT.md](FALLOUT-CONTENT.md) for installed scope and return checks.

- [ ] Equipment switches: pistol, shotgun and laser musket answers follow each
      request's current capture; stowed gear and Vault 111 clothing are described
      accurately. See [OBSERVED-EQUIPMENT.md](OBSERVED-EQUIPMENT.md). Populated
      sheets, Lucy's family and Quincy expertise passed the reported return test;
      equipment answers require retesting after the request-local observation
      and runtime tool-scope corrections. The first compact-prompt retest reached
      the provider correctly but still produced stale gear answers/refusals.
- [ ] Save labels distinguish separate save-owned copies of a named NPC; latest
      follows accepted context, and profiles and histories remain separate.

- [ ] Dialogue, rechat ordering, actions, STT and the five in-scope TTS providers work with
      real providers. Record provider names, never keys.
- [ ] Cartesia prosody candidate: Sonic 3 requests return usable audio; the assigned voice
      persists, speed choices work and English emotion guidance is natural with the
      connector option enabled. Repeat with guidance disabled, queued settings changes
      and cancellation. See [CARTESIA-PROSODY.md](CARTESIA-PROSODY.md).
- [ ] Relationship, memory and diary workers process the queue without log loops.
- [ ] Current NPC combat targets: the actual response speaker identifies its own
      captured opponent when it differs from the scene focus/player. Target
      changes and unresolved observations remain truthful; enemy observations
      never expand audience/readership. See [COMBAT-TARGETS.md](COMBAT-TARGETS.md).
- [x] Native memory accepted-text recovery: disposable interruption, lease,
      checkpoint integrity, source/save visibility and immutable publication checks.
      See [NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md). Game production and
      sustained worker recovery remain separate open gates.
- [x] Native history game-clock projection: disposable UTC/game-clock settlement,
      owned five-event batching, frozen clock consistency and actor/save isolation.
      Completed-input retention remains covered. See [NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md).
- [x] Paired flat game return check: automatic capture and immutable publication
      after game-clock settlement, followed by reported correct natural recall.
- [x] Memory survives a new manual save/reload with the same NPC; the recorded
      prompt contains stored-memory content and the player reports correct recall.
- [x] Stored-memory NPC isolation: two uninvolved NPCs receive their own saved
      histories; the private plan summary is absent from both recorded prompts,
      and the player reports that neither NPC knew the plan. STANDARD mode only.
- [ ] Earlier-save memory isolation, formal whisper mode, long-session worker
      stability and VR remain separate open checks.
      The observed session has no suitable pre-conversation save for the earlier-save check.
- [x] Automatic memory dispatch validation: terminal and non-NPC continuations
      cannot mask completed NPC dialogue in the same owned turn; publication,
      replay prevention and prepared NPC responder checks use disposable fixtures.
- [ ] **Optional voice enhancement (user-added):** opt-in emotional delivery for
      Cartesia and ElevenLabs, preserving assigned voices and captured queued
      settings. Reuse supported per-line controls; optionally derive bounded mood
      from current observed events, disposition, relationships and the NPC's own
      accepted diary. Existing Cartesia line-mood guidance and ElevenLabs audio-tag
      configuration are groundwork, not context-derived emotional acceptance.
      Verify enabled/disabled behavior, neutral fallback, control-tag separation
      from displayed dialogue, audible tone, latency, cancellation and save/load.
      Keep disabled by default. ElevenLabs is an optional addition to the previous
      five-provider scope, not a required provider change.
- [ ] Stale-generation and cancelled requests leave no late speech or actions.
- [ ] Sustained session: stable queue depth and no runaway worker processes.

## Publication gates

The human already authorized mechanical content hygiene (workstation paths, scratch locations and
private backup destinations in tracked docs), and that pass is done. The project license is
chosen. The remaining history and publication decisions below need human direction.

- [x] **License:** the human chose the GNU GPL v3.0 (2026-10-06). The root `LICENSE` is the
      unmodified GPLv3 text. `MIT-LICENSE` and the CC BY-SA 4.0 and OFL notices are kept.
- [ ] **History:** history inherits HerikaServer/DialecticServer commits and the removed
      binaries, and it exposes author email addresses. Choose between publishing the reviewed
      history and a fresh snapshot. That choice needs human direction.
- [ ] **Assets without recorded provenance:** the provider logos in `ui/images/core/icons/`;
      `ui/images/paper.jpg`; the GIFs in `ui/css/`; and the screenshots in
      `ui/addons/pmstt/`. The unused `ui/images/DwemerDynamics.png` has been removed.
      The favicon and navbar
      now use resized copies of the human-supplied SYNTH icon `ui/images/synth.png`
      (`synth-navbar.png`, `favicon.ico`), recorded in
      `THIRD_PARTY_NOTICES.md`. The inherited portraits, book icon, navbar strip and unused
      wordmarks were removed (see `THIRD_PARTY_NOTICES.md`).
- [ ] **UI review:** desktop and narrow-width keyboard review of the plain navbar, the SVG
      default portraits, the World Knowledge icon and the STT "sample unavailable" state.
- [ ] **Seed data:** confirm the sources of the item-description and biography SQL seeds
      in `data/`.
- [x] **Docs:** workstation paths, scratch probe paths and private backup destinations in
      `FEATURE-PARITY-MATRIX`, `INWORLD-VOICE-REPAIR`, `SERVER-FILE-LAYOUT` and the `NATIVE-*`
      docs are replaced with placeholders. Dated receipts are kept as historical evidence
      (see [HANDOFF.md](HANDOFF.md#historical-receipts-and-placeholders)). Git history still
      contains the original paths.
- [ ] **CDN requests:** the UI loads fonts and scripts from third-party CDNs. Disclose this or
      vendor them with their notices.
- [ ] **Secrets:** a baseline Gitleaks 8.30.1 all-ref scan found only reviewed
      non-credential matches. A scanner only covers its own rules. Re-run on the final head.

## Temporary conversation movement

- [ ] Flat addressed participants remain still while a reply or NPC exchange is pending; bystanders continue normal AI.
- [ ] Temporary holds release at completion, cancellation, Stop, combat and before an action; explicit waits and save/load remain correct.
- [ ] NPC-to-NPC reply and fresh saved relationship publication pass after the exact paired update is installed.

## Ordinary dialogue delivery receipts

The optional protocol v2 grant requires turn ownership. Ordinary NPC lines with pending
or ready speech can be enrolled. Check the stored exact line and confirm that only
an accepted heard receipt adds its history. Known text-only responses keep their
existing history. If enrolled audio fails later, the line stays unheard without an
explicit accepted presentation receipt. A caption timer supplies no receipt.

Exercise cancellation both before and after receipt commit. An accepted heard row
survives cancellation. When cancellation revokes a pending line first, a late receipt
cannot promote it. Owned rechat waits for the exact enrolled parent to be accepted
as heard before reserving the child. For Whisper and Close, verify hearers against
the frozen origin capture.

## Dashboard request safety

See [HANDOFF.md](HANDOFF.md#dashboard-request-safety-candidate).

- [x] `php -l` on every changed PHP file; `git diff --check`.
- [x] `DashboardContentCatalogTest` (7 tests: guard decisions, index and Events/Memories stale links and
      token-less POSTs refused with rows unchanged, Events/Memories rendered without `conf/conf.php`) on a
      disposable PostgreSQL 17.11 with pgvector 0.8.7 in a private WSL network namespace, run as `nobody`
      against a snapshot of the candidate tree. Hosted CI not run.
- [x] HTTP probe through real Apache 2.4.69 with mod_php 8.4 and the shipped `Require local` /
      `AllowOverride FileInfo` policy, in the same namespace, on a fresh `synth` database from
      `tools/bootstrap-database.php`, with no `conf/conf.php`. Results:
      - Bare index GET and HEAD: `302` to Home. Configuration, logs, schema and the session store are unchanged.
      - Home GET and HEAD, and profile-loader pages: no log rotation (oversized sentinel logs kept), no
        worker start, and a deleted optional narrator row stays absent.
      - Without PHP's PostgreSQL extension (CLI `php -n`), Home renders the setup guidance.
      - Events/Memories, the index tables, the diary book, the adventure log and the event API: these render
        without the override.
      - Stale action links: 21 GET and HEAD pairs answer `400` and the vector pages answer `405`.
      - Refused before session or data: cross-origin, `null`, same-site-port, scheme-mismatch, cross-site
        fetch metadata, foreign-Referer and evidence-free browser POSTs, and cross-origin Quickstart and
        package POSTs.
      - Same-origin event deletes, hidden-type changes, memory delete and LLM connector create/delete
        succeed with the token. A wrong or missing token answers `403` with no change.
      - The MiniMe GET probe needs browser evidence; HEAD answers `405`.
      - Array action values (`delete[]`, `rowid[]`, `value[]`) answer `400` with no change.
      - Headerless native package `probe` and `packages` calls pass.
      - Connector views leave the schema unchanged.
      - Malicious audit dates fall back to today. Regular dates and ISO weeks are kept.
- [ ] Browser clicks of the converted controls (desktop and narrow, keyboard, confirm and cancel). The
      evidence above is HTTP only.
- [ ] Coverage is the scripts that include `lib/runtime_bootstrap.php`, `ui/profile_loader.php` or the guard.
      Not reached: redirects (`immersion.php`, `native-*.php`), `system_health.php`,
      `tools/server_version.php`, `synth_setup.php`, the disabled `cmd/action_ai_update_appearance.php`, and
      included helpers. The private narrator page keeps its own token.
- [ ] GET writes were converted only on the pages named in the handoff. Other `ui/` scripts were not audited
      for GET writes. Existing POST forms without a token rely on the origin check.
- [ ] Other bootstrapping pages (for example AI Response, Request Logs and the connector pages) keep the default
      log rotation on GET.
- [ ] Fresh empty database: the index now lands on Home rather than Quickstart. Not checked against an
      uninitialised database.
- [ ] DNS rebinding: `ui/` refuses a Host that is not loopback, an IP literal, this machine's name or listed in
      `SYNTH_ALLOWED_HOSTS` (HANDOFF, Dashboard request safety). Disposable HTTP evidence only; native endpoints
      are not covered and no real LAN or rebinding browser check has run.
- [ ] Deploy, installed Apache/PHP-FPM, and in-game plugin package flows: none claimed.

## Write-only credential forms

See [HANDOFF.md](HANDOFF.md#write-only-credential-forms-candidate).

- [x] `php -l` on the changed PHP files; `git diff --check`.
- [x] HTTP probe on a disposable PostgreSQL 17.11 with pgvector in a private network namespace, a fresh Doctrine
      install and a loopback mock provider, with synthetic keys only: 84 credential checks (no saved key in page
      HTML, scripts or redirects; blank keeps, Clear empties only its row; token, method and malformed-key
      refusals; saved-key Test resolved server-side) and 77 case-variant and stale-form checks (lowest-id card,
      distinct variant rows, stale bulk forms refused before writing, first save of a missing preset). No
      synthetic key appeared in application, PHP or request logs.
- [x] The 159 dashboard request-safety HTTP checks through real Apache and `DashboardContentCatalogTest`
      (7 tests, 328 assertions) on the merged tree.
- [x] Release-tree audit: 549 PHP files linted, 374 JSON files validated.
- [x] Disposable browser inspection at desktop width and 390 px: saved-key inputs empty; a saved case-variant
      Test returned HTTP 200 from the mock provider.
- [ ] A 5 px horizontal overflow remains on the API Keys page at 390 px.
- [ ] Browser keyboard, Save, Clear and confirm/cancel workflows. The evidence above is HTTP plus page inspection.
- [x] Custom-key Delete id: on disposable PostgreSQL 17.11 in the same namespace setup, a valid custom id deletes
      only that row; 15 malformed forms, a missing id and canonical or case-variant preset ids answer
      `err=not_found` with the table unchanged; a foreign-key-referenced row answers `err=save_failed` and is
      kept; token, array and GET refusals are unchanged; no PHP warnings. The same probe on the previous source
      deleted another row for 8 malformed forms. Browser click-through of Delete is not checked.
- [ ] Custom-key create computes its id in PHP (`max(100, highest id + 1)`); concurrent creates are unchecked.
- [ ] Real provider keys, the installed runtime, hosted CI (Actions disabled), deploy and in-game use: none claimed.
