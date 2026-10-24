# Synthserver developer handoff

Updated: 2026-10-16. This is the current entry point for Synthserver development. Older
migration and parity documents under `docs/` are kept as feature evidence.

## Flat alpha update candidate

The October 24 flat gameplay run completed an addressed Codsworth/Jun exchange,
released both temporary holds, retained both exact directed relationship records
through a new manual save/reload, and excluded those later records from an older
save's ancestry. Earlier NPC-owned quest-plan teaching, natural title-free update,
later recall, save/load and uninformed-NPC/save isolation evidence remains accepted.
Separate VR gameplay is unverified and is not included in this flat alpha update.

The prepared ChatGPT startup repair discovers the updated desktop executable,
rechecks isolation, requires a real primary reply and refuses alternate LLM
fallback. The Windows launcher waits for this check before exposing game launch.
Startup gameplay acceptance remains open until the reviewed runtime files are
installed and the normal launcher is tested. See [CODEX-LOCAL-PROVIDER.md](CODEX-LOCAL-PROVIDER.md).

## Historical feature baseline

| Item | Value |
|---|---|
| Server source | `RANGROO/Synthserver` `codex/chim-queue-parity` at `bcb0c06` (includes `daf4885` and two later live-input/rechat fixes) |
| Paired client | `RANGROO/SYNTH` `codex/chim-conversation-targeting` at `f34da88` |
| Server version | `.version_number.txt` (unchanged by this cleanup) |
| Protocol | Versions 1 and 2 under `protocol/`, mirrored byte-for-byte in the client. The client sends version 2. |
| Latest migration | `synth_protocol_schema` `20260909001` (`debug/db_updates.php`) |

The public repositories are `Dwemer-Dynamics/SYNTH` and `Dwemer-Dynamics/Synthserver`.
`synth` is the default branch; every development PR targets `alpha`. `synth` is the main/release
branch; `dev` and `unstable` are reserved lanes. See [CONTRIBUTING.md](../CONTRIBUTING.md).
Older branch names and hashes below identify historical private development checkpoints.

Temporary flat conversation holds and active-dialogue wait pumping are prepared in the testing candidate.
See [CONVERSATION-HOLDS.md](CONVERSATION-HOLDS.md). Gameplay and installation remain open.

## Current cleanup source

The flat enemy-awareness candidate adds a separately acknowledged, speaker-owned
visible-hostile list and a bounded 30-second recent-observation cache. It covers
visible threats before they attack and enemies attacking a native teammate,
without expanding audience/history readership or attributing kills. Exact
current/recent identification uses fresh canonical frame evidence; normal and
prepared replies receive separate current/recent prompt sections. The previous
combat-target and incoming-contact contracts remain intact. See
[COMBAT-TARGETS.md](COMBAT-TARGETS.md) for capture limits and open gameplay checks.

The testing candidate repairs audio request binding by passing a completed STT
transcript separately from the immutable canonical wire event. Owned readers,
cancellation and worker leases retain their checks. A successful LocalWhisper HTTP
response with a valid but empty text field ends as speech not recognized, without
dialogue, actions or TTS. Transport, HTTP and invalid-response failures remain errors;
an empty result does not establish that the player was silent. Explicit player
text/audio Wait Here and Release Wait
are capability gated and reuse the flat client's existing controller; a spoken
promise alone is not an action. Current combat facts are repeated after historical
context so older species guesses cannot establish an unidentified current enemy.
The return checks in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md) remain open until
the reviewed candidate is installed and tested in the game.

The same candidate adds acknowledged public idle NPC exchanges and removes
candidate-less random continuation choices. The existing configured round limits,
private-mode boundaries and exact completed-parent proof remain authoritative.
See [NATIVE-RECHAT-OWNERSHIP.md](NATIVE-RECHAT-OWNERSHIP.md) for current scope and
capture limits; historical checkpoint sections do not establish game acceptance.

The public server source comes from the completed private cleanup at `c12fb1b`, including
the inspector retirement, runtime-helper cleanup and blended scrolling banner.
The paired client source comes from `618e924`. Current work belongs on public `alpha`.
The baseline table above records the earlier feature snapshot; it is not the public source head.
This follow-up retires the management-only native inspector APIs and their exclusive helpers.
It does not change the client, protocol, database schema, providers or active workers.

## Runtime topology

The character catalog contains 204 templates, 125 world articles and 83 object
descriptions. The listed backlog is researched; 76 newly researched actors and
four Deacon cover templates have safe bindings. Four authored drafts remain
withheld for impersonator, activator, radio or dynamic-robot identity handling.
The additive content migration is `20261008003`, including transactional
reference-only mappings and bounded aliases. Curie uses one continuing biography
and two optional observed-form voice slots. Current native speech limits preserve
Takahashi's Japanese phrase and prevent fluent dialogue for selected nonverbal
actors. Existing custom content and learned state retain precedence.
See [CHARACTER-COMPLETION.md](CHARACTER-COMPLETION.md) and
[FALLOUT-CONTENT.md](FALLOUT-CONTENT.md) for flags and open game checks.

The lore accuracy pass adds 20 everyday food/currency/chem/culture topics and
corrects the farming article. Eligible World Knowledge dialogue gets one bounded
basic vocabulary article from the effective catalogue, preserving custom precedence
and prewar restrictions. Definition questions can retrieve short terms such as
tato and Jet. The pronunciation dictionary splits radroach/radroaches in spoken
text only; written subtitles/history and captured queued settings remain intact.
See [LORE-ACCURACY.md](LORE-ACCURACY.md) for scope and audible return checks.

Dashboard content views use the canonical application schema. Home separates
encountered sheets from character/lore library counts; World Knowledge lists
effective custom/factory articles and supports topic, alias and tag searches.
These management views do not create NPC histories or overwrite authored sheets.

The 204-row named biography CSV uses the dashboard's fifteen-column upload order.
Its five optional override fields are blank. It is a custom-template import, not a
factory identity bundle; see [FALLOUT-CONTENT.md](FALLOUT-CONTENT.md).

- Windows hosts Fallout 4 and the SYNTH client. A WSL Linux distribution runs Apache with PHP
  (cli and Apache module; `curl`, `mbstring`, `pgsql` and `xml` extensions) and PostgreSQL
  with `pgvector` and `pg_trgm`.
- Apache serves `/var/www/html/Synthserver` on **`127.0.0.1:8087`** with
  `Require local` (`tools/apache/synthserver.conf`). This matches the client default
  `BaseUrl=http://127.0.0.1:8087/Synthserver/`.
- The database is `synth` on loopback PostgreSQL. The local role defaults to
  `dwemer`/`dwemer` (`conf/conf.sample.php`, `lib/db_connection_settings.php`). These are
  loopback-only development defaults. Never expose PostgreSQL or Apache beyond loopback.
- The background processor (`service/start.sh`, `service/manager.php`) runs memory,
  relationship and TTS workers. `health.php` reports the database, schema and processor state.
- `start-dev.ps1` is an **optional** standalone PHP development server. It defaults to port
  **8085**, so a client that uses it needs a matching `BaseUrl`. It serves the checkout's parent
  directory, so the checkout folder must be named `Synthserver`.

## Fresh install

The Fallout catalogue now ships as reviewed SQL at `fallout4_catalogue_sql`
version `20261009002`: 204 character templates, 207 actor bindings, 201 equipment
descriptions and 125 lore articles. The migration adds missing factory records,
preserves custom edits, and does not create encountered NPC profiles. See
[FALLOUT-CATALOGUE-SQL.md](FALLOUT-CATALOGUE-SQL.md) for ownership, era-tag repair
and transaction rules. Eighteen guarded stock appearance refinements and 118
rare descriptions accompany 43 named-variant review records. Special properties,
find locations and unverified instance effects remain review-only; recognition
controls are held as separate development work. The JSON/CSV authoring files and attribution remain in
`data/`; `python3 tools/build-fallout-content-sql.py --check` verifies SQL parity.

The [README](../README.md#install) has the steps. Safeguards:

- `tools/create-synth-db-wsl.ps1` creates the database only if it is missing. It requires
  UTF-8, enables the extensions, imports `data/database_default.sql` only when the baseline
  schema is absent, then runs the idempotent migration chain. It drops data **only** when the
  explicit `-Reset` switch is passed. The default never resets.
- `tools/deploy-synthserver-wsl.ps1` refuses any target other than
  `/var/www/html/Synthserver`, then stops before any change unless the read-only
  `tools/check-php-runtime-security.sh` passes in the chosen distro (patched PHP CLI and Apache
  PHP runtime; floors in the [README](../README.md#install)). Its `rsync --delete` excludes configuration, voices, caches,
  uploads, logs and runtime state. It creates `conf/conf.php` from the sample only when
  `conf.php` is missing, protects it with mode `0640`, runs the database bootstrap, and installs
  the Apache site only after `apache2ctl configtest` passes.
- `tools/migrate-synth-db-utf8-wsl.sh` creates a safety dump and a rollback database, then
  compares row counts before it switches the database.
- The helper scripts assume the default local role password. If you change it, update
  `conf/conf.php` and run `tools/bootstrap-database.php` directly.

## Tests

The local Cartesia prosody candidate adds modern Sonic 3 request controls and an
explicit, default-off English emotion option. See [CARTESIA-PROSODY.md](CARTESIA-PROSODY.md)
for compatibility, isolated tests and pending provider/game acceptance. It changes no
protocol or database schema.

- `tools/audit-release-tree.ps1` lints every tracked PHP file, validates JSON, checks the
  World Knowledge checksums and the TTS/STT/ITT drivers, and rejects runtime files and
  unverified assets. It is a source safety audit, not packaging.
- CI policy (2026-10-06): hosted `server.yml` runs the source-tree audit, the protocol
  manifest check and PHPUnit on disposable PostgreSQL. It builds no release package. The
  last hosted run before this note passed at `d171ec7`.
- PHPUnit (`unittests/`, run `composer install` first) needs a **disposable** PostgreSQL with
  pgvector and the `dwemer` role, as in `.github/workflows/server.yml`. Never run it against a
  database that holds real data.
- `unittests/tests/AutoGreetingRuntimeTest.php` reads the client source through
  `SYNTH_CLIENT_ROOT` (default `../SYNTH`). If the client source is missing, the test is skipped.
- `ui/tests/stt-test.php` needs a locally supplied WAV (`debug/data/test.wav` or a CLI path).
  No sample or expected transcript ships. Without a sample it reports that the test is
  unavailable and makes no provider call.

## Quest-aware knowledge

Native protocol v2 NPC prompts use the responder's saved, directly addressed
player reports instead of exposing the player's journal. Reports remain attributed
statements; they do not prove completion. Eight reviewed quest rules add 16 exact
participant bindings and 25 personal background facts. Protected quest articles
use reviewed premises or participant facts, including when custom text or `knowall`
would otherwise expose a hidden outcome. See [QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md).

The quest report reader now materializes its actor-owned native history once per
query, preventing repeated ownership joins inside the bounded report projection.
Other readers keep their existing query mode. Disposable result-equivalence checks
preserve all fields, exclusions, ordering and evidence bounds; request and query
timeouts remain unchanged. Paired gameplay remains a separate acceptance check.

Conservative English follow-ups can reuse a plan or settlers reference from an
earlier eligible direct player report that named exactly one observed quest.
The original report must contain the referenced noun, and all eligible matches
must identify the same quest. Ambiguous, quoted, disavowed or unrelated-topic
references remain ordinary conversation. This does not introduce first-time
aliases from private journal objectives, NPC replies or generated summaries.
The same source, actor, saved ancestry and query bounds apply; general natural
quest recognition and paired game acceptance remain separate work.

The direct exact-title plan binding permits one generic `quest` or `mission`
category after the observed title. The category must terminate at the same
predicate or sentence boundary as the title; it cannot absorb another name,
possessive suffix or coordinated topic. This covers ordinary category wording
while retaining the exact quest identity and all original reader/source fences.

The flat teaching check exposed two bounded follow-up problems: an asserted
`my plan for <exact observed quest title>` qualifier was rejected, and older
unnamed plan references permanently blocked a later explicit assignment. A direct
noun-to-title assertion now permits only that exact qualification and clears only
its unnamed noun barrier. It retains distinct named-quest ambiguity, unrelated
qualifiers, quotations, disavowal and every source/reader/save fence. Ordinary
first-time natural aliases remain open; paired follow-up gameplay is pending.

The 2026-10-13 cancellation repair retains the completion receipt of an already
finished player text/audio input while clearing its replay body and cancelling
delivery and unfinished workers. The explicit cancellation error blocks late work
under the existing native request lock. Interrupted inputs and nonplayer context,
trigger and action-result requests remain cancelled. This preserves future quest
learning across a later turn cancellation; it does not restore receipts erased by
the previous implementation. A newly completed teaching conversation is required
for the paired game return check.

The integration preserves captured equipment ownership, prewar knowledge limits,
ordinary vocabulary injection, request-state cleanup and the existing narrator-only
native journal action gate. No database migration, catalogue import, history rewrite
or wire-schema change is required. The current flat radio-testing client supplies
the identities, fresh journal snapshots and saved anchors this reader uses.
Native actor-witness evidence, natural quest aliases, public overhearing and outcome
unlocking remain open, as do flat gameplay and separate VR acceptance.

## Native relationship dispatch

Automatic player and NPC-pair relationship evaluation now skips terminal,
narrator and unprepared rechat markers when selecting a completed origin in the
current turn. A prepared NPC continuation must retain its reserved state and
matching chain, generation, turn, context and runtime lane. The downstream
responder, private source, cancellation, lease and saved-publication checks remain
in force. Both evaluation lanes keep their independent cooldowns.

Disposable fixtures cover unchanged-result publication, addressed NPC-pair
directions and deduplication, distinct-responder private-input refusal, lease
replacement, retry limits and earlier-save visibility. A successful evaluation
may leave the score unchanged. A flat appreciation exchange produced one accepted
evaluation and positive publication, but the next conversation lost visibility
because cancellation cleared its replay body. Historical accepted text/audio
response receipts now survive that cache clearing only inside the saved cutoff
and with the existing immutable provenance checks. Delivery replay and late
capture remain blocked. Saved-reader gameplay after this repair, broader flat
relationship behavior and VR acceptance remain open. See
[NATIVE-RELATIONSHIP-MIGRATION.md](NATIVE-RELATIONSHIP-MIGRATION.md).

Historical NPC-pair evidence has a dedicated reader for already accepted paired
results. A ready hashed result binds its canonical owner, prepared public NPC
reservation, immutable response receipt and exact frozen context. Direct-parent
rechat can use its explicitly acknowledged later scene snapshot, including the
original claim window and physical scene identity. Normal cancellation can clear
delivery replay after publication while both saved relationship directions remain
eligible within their saved frontier. A later chain closure does not invalidate
that earlier accepted result. Shared history scopes and replay guards retain
their privacy and ownership boundaries. Live relationship capture, admission and
paired publication independently prove the prepared responder.
Small materialized provenance stages bound join planning while retaining the same
evidence checks. Existing query and lock timeouts remain in force.

The additional path covers flat direct-parent public NPC rechat with a proven
parent response receipt and an independently prepared responder. Relationship
capture, paired evaluation and saved publication use that responder's identity
while preserving the original request and effective context. The parent speaker
and captured target may differ from the responding NPC. Scene membership, public
policy, private reader scopes and immutable provenance remain required. Existing
misattributed receipts are not rewritten; a fresh exchange is needed for gameplay
acceptance after installation. Action-result ancestry, inventory overlays
and parents without that optional receipt retain the existing complete
original-context behavior.
Historical proof relies on the immutable reservation made after the original wire
line was verified. Cleared replay bytes cannot independently re-prove a later
well-formed substitution of that route's line hash. Synthetic regressions cover
scene and no-scene publications, both cancellation paths,
loaded ancestors, malformed or changed evidence and earlier-save exclusion.
Flat public NPC-pair gameplay and separate VR acceptance remain open.

## Native memory recovery

Automatic memory dispatch skips terminal and non-NPC rechat continuations when
choosing a completed request. A `no_candidate` marker can no longer hide settled
NPC dialogue from the same active turn. Prepared NPC continuations retain their
selected responder, and all origin, save, visibility and publication fences remain
in force. See [NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md) for validation
and the separate game acceptance gate.

Native memory workers checkpoint validated summary text in the existing job payload
before optional embedding and publication. An eligible retry reuses that accepted
text while retaining actor, request, lease, source and save-visibility checks. The
change requires no migration or client update and preserves the existing attempt
and deadline budgets. Diary checkpoints remain separate. See
[NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md) for the recovery boundary and
open gameplay/soak checks.

Native history now reads game age from the immutable event-bound source/scene
context, preserving raw UTC dialogue timestamps. Missing or invalid game clocks
cannot settle; the existing one-hour cutoff and five-event grouping remain in
force. Frozen batch clocks must agree with the source projection before capture
coverage, checkpoint recovery or prior-summary reuse. This backend-only repair
preserves completed-input cancellation retention and requires no migration or
client/protocol change. One flat game return check confirmed automatic publication
after settlement and correct natural recall. The same NPC retained the memory
through a manual save/reload, with recorded memory content in the new session's
prompt and reported correct recall. Two uninvolved NPCs did not receive the
stored plan in their own recorded prompts and did not claim to know it.
Earlier-save isolation, formal whisper mode, sustained worker stability and VR
remain open. See
[NATIVE-MEMORY-RECOVERY.md](NATIVE-MEMORY-RECOVERY.md).

## Deferred work

- Prepared current NPC combat-target identification uses one native target handle
  per bounded observed actor and an explicitly acknowledged v2 capability. Only
  the actual response speaker receives its target; opponents gain no audience or
  history readership. Older/unavailable states cannot imply an opponent.
  The current owned observation is repeated after history/cues; absent target
  identity explicitly requires uncertainty rather than an earlier species label.
  See [COMBAT-TARGETS.md](COMBAT-TARGETS.md). Installation and flat/VR gameplay remain
  separate open gates.

- **Windows launcher.** A local `SYNTH.exe` candidate resumes existing owned
  WSL/Docker database, backend and Whisper services, checks full readiness, opens
  the dashboard once, and offers a separate explicit F4SE button. It preserves
  installed source and data without bootstrap or migrations. Deterministic checks
  cover startup/failure boundaries; manual Windows interaction and stopped-service
  startup remain open. The optional Codex bridge keeps its separate startup path.
  See [WINDOWS-LAUNCHER.md](WINDOWS-LAUNCHER.md) and
  [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md).
- **Native embedding-space subsystem.** Not alpha-ready. It is preserved on
  `codex/dialectic-parity-core` at `3808838` (`lib/synth_embedding_*`,
  `service/embedding_dispatch.php`, the native embedding processor, rollback SQL). Its own
  documentation records it as not deployed and not at semantic-memory parity. Its six
  migrations, `20260908001` to `20260908006`, sort **below** the baseline's `20260909001` and
  must be re-evaluated and renumbered before any merge. The other commits on that branch have
  reworked equivalents in the baseline. No exhaustive per-hunk deletion reconciliation was
  performed.
- Scope amendment of 2026-09-08: companion affinity is excluded. TTS scope is XTTS,
  Chatterbox, PocketTTS, Cartesia and Inworld.
- Vision connectors are disabled.
- Client-side gaps (pickup disabled, VR-only gaps, in-game acceptance) are listed in the
  SYNTH client's `docs/HANDOFF.md`.

## Retired ledgers

The 2026-10-06 cleanup removed `docs/DIALECTIC-PARITY-GOAL.md` (the chronological parity goal
ledger) and two near-duplicate dead-code audits of an old snapshot
(`docs/synth-parity-dead-code-audit.md` and `docs/provenance/stale-snapshot-dead-code-audit.md`).
They remain in Git history at `bcb0c06` and in a private archive. The ledger held
workstation-specific deployment receipts, hashes and backup paths. It was not a current
specification. Documents that cite it for evidence or remaining gates now point here. Current
open requirements are listed above and in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md).

## Historical receipts and placeholders

Feature docs keep dated deployment and validation receipts as historical evidence. Their dates,
commit hashes, file hashes, migration IDs and the fixed install path `/var/www/html/Synthserver`
are original. Workstation-specific locations were replaced with these placeholders:

| Placeholder | Meaning |
|---|---|
| `<wsl-distro>` | The WSL distribution that hosted Synthserver |
| `<MO2-mods>` | The Mod Organizer 2 `mods` folder of the test modlist |
| `<Fallout4-install>` | The Fallout 4 game folder |
| `<scratch>` | Ad-hoc probe scripts and scratch output (Windows or WSL view), retained outside the repository |
| `<private-backup>`, `<server-backup>` | Pre-deployment backups (Windows and WSL), retained privately |

The probe scripts and backups named in those receipts are external evidence. They are not part
of the repository and are not needed to install or test Synthserver.

## Baseline evidence

These checks ran on the baseline commit, not on the cleanup head:

- The release audit passed: 428 PHP files, 206 JSON files and 3 World Knowledge checksums.
- Full PHPUnit stopped at about test 300 of 472 with a pre-existing fatal error: a repeated
  `_relGetConversationListener()` declaration when a test includes
  `ext/relationship_system/postrequest.php` a second time. The cleanup guards that file's
  helpers with `function_exists`, following the file's existing pattern. The hook still runs
  its per-request side effects.

## Cleanup working-tree evidence (2026-10-06)

These ran on the uncommitted cleanup working tree, before the final documentation-only pass:

- Full PHPUnit in an isolated, disposable PostgreSQL 15 cluster: 472 tests, 6605 assertions,
  all passing.

No in-game or live-provider evidence is implied.

## Roleplay page cleanup

Roleplay follows CHIM's standard Events, AI Responses, Adventure Log, Memories and Diaries
navigation, with PipVision and Active Quests retained for Fallout. The separate Native Memories,
Narratives, Relationships, Diaries and Visual Corrections inspectors have been retired, along
with their exclusive JavaScript and styles. Their old URLs redirect to the standard memory,
diary, relationship-log or gallery pages. The nine management-only `ui/api/native*.php`
endpoints and six exclusive inspector/control libraries are retired. The remaining dormant
inspector functions have also been removed. Active relationship locks
and write checks live in `synth_relationship_control.php`; regeneration scope validation lives
in `synth_middleterm_scope.php`. Snapshot data, processors and migrations remain intact;
this cleanup does not migrate
save-owned records into the standard memory tables. Historical feature docs describe the retired
management UI; they do not imply those APIs are available in the current tree.

## Optional local Codex provider

An optional text-only managed Codex bridge uses the existing `openaijson`
connector. See [CODEX-LOCAL-PROVIDER.md](CODEX-LOCAL-PROVIDER.md) for isolated
request ownership, schema conversion, fallback and validation boundaries.
Local synthetic provider/driver evidence is separate from gameplay acceptance.
No installed game client is changed by this adapter.

## Observed equipment and save identity

Present-tense equipment now has a compact projection from each dialogue request's
owned snapshot, with exact plugin-bound catalog descriptions. The character list
labels the latest captured save and other save-owned entries without merging their
histories. See [OBSERVED-EQUIPMENT.md](OBSERVED-EQUIPMENT.md) for bounds and return
checks. The content test reported correct populated sheets, Lucy's family history
and Quincy expertise. Equipment switching and client speech completion still need
paired retesting; an accurate capture alone does not prove a correct model answer.
The equipment retest confirmed correct observations reached the provider while
answers still repeated old claims. A later request-local observation and native
tool-scope clarification now address history/action anchoring. Authored profiles,
saved action definitions and histories are preserved. Paired acceptance remains
open until the corrected path is tested in game.

## Licensing and publication

Synthserver's own additions are licensed under the GNU GPL v3.0 (root `LICENSE`, the
unmodified GPLv3 text, chosen 2026-10-06). The root `MIT-LICENSE` is the unmodified inherited
HerikaServer/DialecticServer notice ("Augusto Beiro and others"). It names no SYNTH copyright
holder, and the GPLv3 does not narrow those MIT terms. Keep `MIT-LICENSE` and the bundled
CC BY-SA 4.0 and OFL notices. History and publication decisions remain open. See
[THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md) and the
[publication gates](ALPHA-ACCEPTANCE.md#publication-gates).
