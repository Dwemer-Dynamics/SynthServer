# Synthserver open-alpha acceptance

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
