# Synthserver open-alpha acceptance

## Stage 1 native typed turn

- [x] Apply schema 20261109001 on a disposable PostgreSQL 15 database (reapply is idempotent).
- [ ] Apply schema 20261109001 on the reviewed install.
- [x] HTTP + disposable database: registered placed NPC `/talk` turn streams lines, enqueues
  profile speech and stores generated utterances; receipts move only completed audio
  to spoken history at the receipt-time frontier (fake loopback provider).
- [x] HTTP + disposable database: duplicate receipts replay; conflicting, stale-generation,
  unknown-line and wrong-character receipts are rejected; >12 lines, >1000-character
  lines, truncated and malformed streams end the turn with an error and no fallback.
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

- [ ] Quickstart saves the LLM connector, TTS and STT. Missing optional keys fail cleanly,
      without PHP warnings.
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
