# Synthserver developer handoff

## Native conversation World Knowledge candidate

Native `/talk` conversation turns previously sent no lore. The candidate adds deterministic,
request-owned selection through the existing catalogue, retriever, tier policy and renderer
(`managers/WorldKnowledgeManager.php`). It uses explicit configuration, profile and NPC settings,
the admitted actor and the bound snapshot. It adds no provider call, extractor fallback or forced
location/actor articles. Participant-only quest tiers use the native quest view below. The vocabulary
article can still appear on a turn with no matched topic. Disposable-database loopback checks and the
existing focused suites pass; no compiled client, game or VR check has run. No schema change,
deployment or game acceptance. See [WORLDKNOWLEDGE-PARITY-V1.md](WORLDKNOWLEDGE-PARITY-V1.md).

## Native conversation quest knowledge candidate

Native `/talk` turns previously omitted the quest block that ordinary dialogue sends. The candidate reads
the existing locked quest reader once per turn with the explicit claimed owner (no legacy claimed flag)
and the admitted NPC as the exact recipient. The same rendered `<quest_knowledge>` block reaches the
prompt only when it has attributed reports or authored participant background; World Knowledge
participant tiers reuse that view. The narrator role, journal privacy and all reader checks are
unchanged. Disposable loopback and focused suites only; no client, game or VR check. See
[QUEST-AWARE-KNOWLEDGE.md](QUEST-AWARE-KNOWLEDGE.md).

## NPC biography and SPECIAL candidate

Authored expertise is preserved once in Background. Skills come only from the exact actor’s copied SPECIAL base/current observations after a negotiated init acknowledgement. New imports use the layout; the server provides an operator-only, provenance-guarded dry-run/apply repair. No database schema change, deployment or game acceptance is implied. See [NPC-BIOGRAPHY-REPAIR.md](NPC-BIOGRAPHY-REPAIR.md) and [protocol/v2/README.md](../protocol/v2/README.md).

## Optional post-combat companion comments

The paired source candidate validates acknowledged encounter participants before
admitting a flat companion remark. Existing RPG controls remain authoritative.
See [the feature note](COMPANION-COMBAT-COMMENTS.md). No schema migration, deployment
or gameplay acceptance is claimed; VR remains unavailable.

## Plugin-local location ancestry

Flat v2 contexts that negotiate `context.location_identity` and `context.location_ancestry`
may carry `world.location_ancestry`: the current location and its parent chain as bounded
plugin-local IDs with an explicit end. Ingress validates it strictly (`lib/synth_location.php`),
init acknowledges it, and it is stored only inside the existing context snapshot; there is no
database schema change. `ContextManager` projects its bounded current-location sentence into the
next native prompt. Ordinary dialogue already includes the immutable world object. See LOCATION-ANCESTRY.md.

## Maintainer biography extractor

The additive [biography audit and draft workflow](FALLOUT-BIOGRAPHY-EXTRACTOR.md)
exports official actor metadata, reconciles catalog identities and prepares
evidence-backed drafts outside the checkout. It never installs profiles.
Structural exports do not certify named-cast completeness; source access,
identity review and original field evidence remain explicit gates.

## Private narrator candidate

The optional private narrator contract adds separate immutable definition revisions and save-branched private
transcripts. Existing context snapshots capture opaque private-state IDs; SCTX and ordinary NPC receipts remain
unchanged. Private data is excluded from NPC history and journal sources. See [PRIVATE-NARRATOR.md](PRIVATE-NARRATOR.md).
The additive migration is `private_narrator_schema` 20261113001. No deployment or gameplay acceptance is included.

## Installed gift screen and shared wording

The user approved general gifts opening the normal inventory exchange, while
named items transfer directly. The shared Marcy wording helper is installed for
all NPCs. The coordinated installed update adds owned, observed OpenInventory,
post-menu paired receipts and model-valued gift batches. Automated checks pass;
the reviewed DLL/PEX/backend are installed and hash verified. Next verify
the correct NPC's free exchange opens and actual donations become gift facts.
No fixed NPC bonus is used. See PROTOCOL.md. Menu gameplay remains unverified.


## Player-pack follow-up wording correction

Marcy's direct “grab the submachine gun” command hid TakeItemFromPlayer because
the guard demanded a repeated possessive/source phrase. It selected EquipItem
instead, which failed against her inventory. The one-file player-gift wording correction is installed and passes 128
backend tests. A Marcy gameplay retest remains pending. Jun's pistol transfer is gameplay/receipt confirmed
and recorded as a verified gift; its cancelled continuation saved no personal
valuation. That separate appraisal issue remains open.


## Installed player-pack gifts

Startup requires action catalog version 20261106001. The migration adds the
TakeItemFromPlayer builtin when missing and preserves existing action settings,
including a previously registered or disabled named-gift action. Use the normal
server bootstrap for clean installs and upgrades.

The release candidate binds each direct gift to the observed item and quantity
in the player's own offer. A trailing address to the captured NPC still opens
the exchange menu. The NPC editor opens the native diary archive scoped to the
selected actor and retains the legacy diary book for profiles without a binding.

The user explicitly requested player-to-NPC ordinary item transfers and personal
gift valuation. The coordinated candidate adds TakeItemFromPlayer with its own
flat v2 acknowledgement, exact Player donor/NPC recipient evidence and explicit
direct-input authorization. Accepted gifts feed the existing relationship model
with the recipient's saved biography/personality/goals; there are no fixed NPC
scores. Failed handoffs and receipt retries cannot multiply gift appreciation.
The coordinated flat client/backend update is installed and hash verified. Gameplay acceptance remains open. See PROTOCOL.md.


## Current handoff priorities

**Stage 1 native typed turn (source candidate).** `Synth\Managers\DialogueManager`
(`managers/`) owns admission, context, provider stream, durable utterance records,
explicit-input TTS jobs and player history for explicit `/talk` conversation-intent turns
(`payload.intent: "conversation"`); unmarked typed text keeps the legacy action route.
Dependencies: `SessionManager` (negotiation, mode, claimed snapshot via `Synth\Managers\SaveState`),
`CharacterManager` (placed key vs runtime binding, registry, profile via `CharacterRepository`;
the admitted event's save-visible dynamic profile revision for the exact identity, read through
`synth_profile_visible` without request globals, replaces only the five dynamic fields of the turn's
NPC copy; the repository opts in to the strict read, so a failed read ends the request as a backend
failure, never as the base profile, while legacy callers keep their `fetchOne` behaviour),
`ContextManager` (situation, 12 heard history rows), pure `PromptManager`, `SpeechManager`
over `Synth\Drivers\Tts\ProfileSpeechDriver`, `Synth\Drivers\Llm\OpenAiCompatibleDriver`,
`UtteranceRepository` and a `TurnOutput` port. Contracts are in `core/contracts/`, entities in
`core/entities/` and SQL only in `core/dbmanager/repositories/`; `bootstrap.php` is the composition
root. Legacy `lib/` and the existing schema update chain are unchanged by this layout.
Eligibility, identity and receipt rules are in `protocol/v2/README.md`. Limits: plain
`openaijson` connectors only; TTS uses the NPC profile's own TTS connector and resolved voice,
with no server-wide fallback; a missing or non-portable profile TTS makes the turn ineligible
with a reason. Schema `synth_protocol_schema` 20261109001 adds `synth_meta.dialogue_utterances`
and `synth_meta.json_object_has_unique_keys(text)`, which replaces the PostgreSQL 16-only
`IS JSON OBJECT WITH UNIQUE KEYS` predicate in the wait-proof checks. Before this,
every native history read failed on PostgreSQL 15. A scratch HTTP-plus-PostgreSQL 15
probe ran the full turn through real `main.php` against a loopback fake
OpenAI-compatible stream (before increment 2): TTS enqueue for the profile, receipt write,
receipt-time history in the next prompt, and the receipt negatives. It also checked the output
caps and malformed streams. No real provider or game check has run.

Provider cancellation and status (source `32d741e`).
`OpenAiCompatibleDriver` passes its durable owner check to `SynthProviderStream`, which runs it
before connecting and at its bounded waits as well as the global request guard. The stream records
the final HTTP status after interim and followed redirect responses; the native turn rejects a
non-2xx status, a missing status or a transport failure with the generic `provider_failed` before
any SSE line reaches the text callback. Other stream consumers, the connector subset, protocol and
storage are unchanged. Evidence on that commit: a fake provider saw owner cancels return in about
250 ms instead of about 2 s, no call for a cancel before connect, and no text from 401, 429 or 500
bodies that are valid SSE. A real-main, disposable-PostgreSQL recording-provider matrix covered
supported and declined connectors for one NPC, a 60-second provider timeout before output, a truncated
stream after one delivered line, a simulated receipt in the next provider request, exact-request
replay and HTTP failures with no dialogue, jobs or error-body history. The compiled-client loopback
check still passes with no client change. Limits: the fix honours the owner's cancellation only; a
client disconnect or the client's 30-second default deadline does not become a server cancel before
the 60-second provider timeout. Playback de-duplication is unproven: a naive replay simulation can
count one line twice, and no real automatic identical-request retry route was established. No real
provider, TTS synthesis, audio playback, game or deployment check has run.

**Stage A increment 2 (source candidate).** `main.php` decodes and validates the body once and
dispatches `/talk` and `speech_receipt` to `api/v2/dispatch.php` before
`main_synth_pipeline.php` loads; the transitional `lib/synth_dialogue_turn.php`,
`lib/synth_dialogue_provider.php` and `lib/synth_dialogue_receipts.php` are removed.
`api/v2/ProtocolOutput.php` is the request-local `TurnOutput`: it applies the legacy streaming
output setup, writes lines through the shared encoders and route recorder, and commits the first
terminal outcome under the session advisory lock before sending it. The shared canonical claim
returns `SYNTHClaimedRequest`, the one explicit owner, for the exact event it inserted (canonical
bytes, hash, turn and generation) only after its COMMIT is attested, with no second lock or
liveness check. The dispatcher binds it to the output before checking liveness, so a cancellation or supersession in between is
recorded as that claim's cancelled outcome. The legacy writer keeps its order (route preparation,
replay buffer, echo, dispatch diagnostics, flush); `withNativeRequestLock` and the other legacy
APIs are unchanged. Beyond PHPUnit doubles, controlled probes run real `main.php` under a
loopback PHP CLI server (four workers) with a disposable PostgreSQL 15 database, a fake loopback
OpenAI-compatible stream and controlled jobs and receipts. The startup, race and receipt probes ran
the startup candidate `d41e1fc` plus the `main.php` change later committed as `3e637c6`; the
identity/cache/save probe ran `d41e1fc` with a no-op TTS worker; the legacy suite comparison ran
`768d87c`. Those evidence revisions are already recorded runs; this documentation-only pass did
not rerun those tests. An include trace shows `/talk`, receipts and their
replays reaching `api/v2/dispatch.php` without loading `main_synth_pipeline.php`; `init`,
`context`, cancel and unmarked typed text stay on the pipeline. A mid-stream cancel from a second
connection closes the turn cancelled after one provider call, cancels its TTS job and refuses
replay. At the terminal commit, a cancel queued first on the session lock wins; a completion that
wins is stored byte-equal to the wire and replays without a second provider call until a later
cancel revokes it, after which replay is refused. Cancel/supersede contending with the initial
canonical claim is not isolated by these probes. Two placed NPCs that share a display name keep
separate models, queued TTS inputs, cache seeds and heard history through older/newer saved-anchor
reloads. Each turn's audience was only the addressed NPC, so a shared same-name audience is
unexercised. Unmarked typed text matches the pre-cutover baseline by route and coarse output only,
and nine legacy action/rechat unit suites match it (118 tests). Neither is every-event or full
semantic parity, and no full PHPUnit run has completed green. Fake provider output, queued TTS
inputs and hand-posted receipts prove no synthesis or playback. Production Apache streaming,
real supervisor/worker pickup, compaction, real providers and game runs remain unverified.
A receipt's first write, under the receipt's claimed lock, also requires the line's own origin turn
unrevoked (not cancelled, superseded in flight or a completed input revoked by a later cancel) and
its exact emitted `response_routes` row; otherwise `receipt_owner_mismatch`. Exact repeats and
conflicts are unchanged, and an earlier unrevoked turn's emitted line stays receiptable after a later
turn. `sql` built with explicit configuration (the v2 bootstrap) throws on connection failure, so v2
answers the generic HTTP 500 `backend_request_failed`; the runtime-global constructor still exits.

Rangroo's handoff scope is bios, world knowledge, diaries, actions, and basic
rechat/profile setup. Preserve accepted work: flat NPC rechat and movement release
were already gameplay confirmed with Codsworth and Jun, and completed rechat is
also recorded in the current backend. All 11 stored actor sheets have valid
profile/connector assignments and populated biography/personality fields.

The 4 November read-only audit confirms all 204 reviewed biography templates are
populated, all 207 expected actor mappings match, and all 125 lore articles are
active with matching reviewed text and content hashes. Existing successful
requests show biography and lore text reaching the provider. This completes the
reviewed content pack, not an exhaustive Fallout cast/world census.

One bounded deployment gap remains: Pearl, Maxwell, Bert Riggs, Gilda Broscoe,
Keith McKinney and Santiago Avida retain the old exclusive prewar access tag in the
installed pack/database. The reviewed candidate removes it for these live postwar
Vault 118 actors. Their biographies are present; the obsolete tag restricts lore
access. The installed JSON pack also predates other biography revisions already
in the database. No runtime change was made by the audit.

Manual NPC diary generation, the F6 shortcut and dashboard publication are
gameplay verified. Continue only outstanding supported-action cases and specific
reproduced failures; do not restart accepted biography, lore or basic rechat work.
The directed follower-to-NPC errand failed, with no accepted social-task request
found, and remains deferred separately from basic nearby rechat.

## Diary button and written-entry viewer

The paired candidate adds a selected-NPC Write Diary row to the flat game control
menu, using the existing owned background request. The dashboard Diaries section
reads accepted published reflections from their actual storage, filters by author
and playthrough, searches text and displays full original entries safely as text.
Home now includes those results in its diary count/latest preview and links to the
viewer. The main menu and Roleplay Diaries tab reach the same section. Legacy log
bookmarks remain available. This is an administrative archive; opening an entry
does not change NPC recall, saved history or diary generation policy.
The menu, reader and paired F6 shortcut are installed. Background calls now use
the connector's existing 30/90-second model allowance and preserve larger configured
values, correcting the old 15-second worker timeout. Flat gameplay on 4 November
confirms the shortcut: Sturges' new 1,429-character entry completed on the first
attempt in about 33 seconds. Published input/result hashes and ownership are valid,
and the entry appears in the dashboard. Older failed requests remain preserved.
VR shortcut gameplay remains unproven.

## Supported NPC action return checks

The 3 November action audit reproduced the profile connector warning when a
context negotiated no native actions: the base registry was read after an empty
population loop. The prepared one-line initialization preserves existing entries
and is covered by an isolated actionless-context regression. This backend change
is installed. Supported action/catalog, inventory, combat and social-task
checks passed 145 tests / 2423 assertions after the transfer fix; the paired native action and adapter
suites passed 3804 checks. Automated checks do not accept physical gameplay.

The Sturges Army Helmet transfer failed before native dispatch: the model selected
Give_Item_To with the correct recipient/item but amount was the JSON string "1".
The request log prepared GiveItemTo then returned zero commands; the durable response
contained dialogue only and no transfer intent existed. The candidate now normalizes
canonical positive decimal quantity strings at the shared speech boundary only,
with an exact empty optional GiveItemTo quantity treated as omitted. Native command
and intent validation remain integer-only; fractions, padded/signed/exponent strings,
extra arguments, ownership and paired receipt checks retain their strict rejection.
An actual OpenRouter processActions regression reproduced the zero-command failure
and passes with the fix. This shares the single functions/functions.php replacement
with the profile-warning correction. The Army Helmet transfer and both 10-cap
directions are now gameplay and receipt verified.

The flat client negotiates inspection, inventory query, quest reading, end
conversation, sheathing, Wait/Release, hostile-target attack, equipment,
consumption, and paired item/caps transfers. Conversation approach and saved NPC
visits use their separate ownership controllers. Catalog activation alone does
not add a native executor: sitting, barter, generic movement/recruitment and
pickup remain unadvertised. Keep those separate from fixes to supported paths.

Accepted flat checks include inventory query, user-observed helmet equip/unequip,
verified item/caps transfer, water consumption and owned conversation approach.
A follower attack on its current visible hostile and broader movement cleanup
remain separate checks. The directed NPC visit is failed/deferred.

## Saved follower conversation tasks candidate

Explicit typed or transcribed instructions such as "Go talk to Jun about the
water pump" and "When we return to Sanctuary, talk to Jun about repairs" can
admit a canonical, actor-owned task without an additional model call. The
performer's journal context includes a deterministic task note. Executable
progress uses separate append-only, save-scoped records; diary prose cannot
create a task. Unknown or ambiguous people and places are rejected.

The paired flat client navigates to the exact loaded recipient, proves arrival,
starts a topic-only opening line and awaits the recipient's delivered reply.
Completed progress needs an exact response route and a post-acknowledgment save
checkpoint. The migration, paired installation and new gameplay acceptance are
separate from the focused automated tests. VR remains deferred by the user.

The same candidate repairs the observed quest-context query timeout with an
equivalent, quest-only visibility projection, and closes failed response streams
without exposing exception text. Existing quest/relationship acceptance remains
valid; synthetic query timings do not establish gameplay turnaround.

## Optional Jev action candidate

The companion-command candidate reuses the existing Wait/Release executor and
physical receipts. Jev now receives compact selected-actor facts and emits
privacy-bounded decision metadata. Clear STANDARD-mode requests to a calm live
teammate can complete an action-only response before dialogue generation; the
existing verified-result continuation supplies the acknowledgement. Latest
capture/age checks are repeated under the native intent lock. Ordinary NPCs and
uncertain requests retain their existing dialogue/refusal path. No new planner,
client protocol, model route or provider attempt is introduced. Installation and
actual gameplay remain separate gates; see [JEV-ACTIONS.md](JEV-ACTIONS.md).

Optional Jev intent selection narrows existing NPC actions before dialogue
generation. It is disabled by default, uses a dedicated connector, has a 350 ms
default budget (750 ms ceiling) without retries, and falls back to ordinary
selection on uncertain, invalid, unavailable or late answers. Existing native
ownership, capability, argument and action-result validation remain authoritative.
This is a server-only candidate; live provider timing/accuracy, installation and
flat/VR gameplay acceptance remain open. See [JEV-ACTIONS.md](JEV-ACTIONS.md).

Updated: 2026-10-16. This is the current entry point for Synthserver development. Older
migration and parity documents under `docs/` are kept as feature evidence.

## Flat alpha update candidate

The local Codex bridge latency candidate reuses its app-server connection while
creating a fresh ephemeral thread for every reply. It caches executable isolation
hashing behind a checked runtime signature, bounds process reuse, detects provider
EOF and retires generation when its HTTP caller disconnects. Replies remain fully
validated before delivery. Synthetic timing and current-executable two-context
isolation checks are distinct from installation and gameplay latency acceptance.
See [CODEX-LOCAL-PROVIDER.md](CODEX-LOCAL-PROVIDER.md).

That bridge candidate now recovers from settings-only Codex changes by retiring
the old connection and rechecking two isolated synthetic scopes locally before
the next real inference. Verified recovery is cached in memory without private
file edits. Executable/code/proof changes and failed isolation remain guarded.
Focused tests and a current-executable synthetic primary recovery fixture pass;
the combined bridge install and game return check remain pending. The prior
latency-only install proposal is superseded by the combined recovery proposal.

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
`synth` is the default and main/release branch; every topic PR targets `unstable`. Promotions are
only same-repository `unstable` to `dev` and `dev` to `synth`; `alpha` is a legacy branch. See
[CONTRIBUTING.md](../CONTRIBUTING.md).
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
The paired client source comes from `618e924`. Current work belongs on public `unstable`.
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
  It can be supervised or started directly with `service/start.sh`. The SYNTH UI and legacy game
  traffic through `main.php` also start it on demand. Explicit v2 dialogue (`/talk` and speech
  receipts) is dispatched before that step and never loads or probes the processor helper; its
  queued speech keeps the per-line TTS job launch. A controlled HTTP probe with a stand-in start
  script confirms the split. Real supervisor pickup, compaction and production Apache are unverified.
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
- The opt-in compiled-client loopback dialogue check runs outside PHPUnit discovery and CI:
  `sudo bash SERVER/unittests/loopback/run-loopback-dialogue.sh CLIENT`. It builds the client's
  test driver and runs it against real `main.php`, a disposable PostgreSQL cluster and a
  loopback fake provider, all in private namespaces. It checks the duplicated receipt and the
  older and newer co-save loads, and that each `/talk` prompt carries the dynamic profile revision
  its save makes visible (published by the production profile worker against a loopback JSON
  provider). See [unittests/loopback/README.md](../unittests/loopback/README.md)
  for prerequisites and limits. It is not game evidence.
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

## Settings scope and inheritance

Settings resolve Global → Profile → NPC. The override editor shows what each override replaces and
the value Clear would inherit; for NPCs that includes NPC metadata and the Emote Moods and Prompt
Head fields, which the runtime applies before `extended_data`. Explicit Off and 0 apply; `null` and
`''` (and `[]` for NPCs) are skipped. Global-only settings are marked on the global page and cannot
be overridden. The unmarked ordinary dialogue pipeline applies profile and NPC overrides from its
next request. Native marked `/talk` uses only the profile's primary LLM and TTS connector; it ignores
metadata overrides and the other model slots. The existing fallback to the default NPC profile is
unchanged. Evidence is HTTP with a fake provider in a disposable database; there is no deployment,
game, flat or VR claim.

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
