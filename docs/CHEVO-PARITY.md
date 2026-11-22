# Chevo parity status

Where Synthserver stands against **chevo** (the CHIM 4.0 server). This revision describes the server source at
`unstable` `2bbe8c86794484afe5c692f8347350981430f2d4` (#225 merged, 2026-11-22). It replaces the parity handoff of
2026-11-20, whose figures are kept as the historical column in [Counts](#counts).

- Reference: chevo `origin/unstable` `e1f32e4`. The chevo figures were recorded on 2026-11-20 and were not re-measured
  for this revision.
- Layout and layering are in [SERVER-FILE-LAYOUT.md](SERVER-FILE-LAYOUT.md). Code rules are in [CODING-STANDARDS.md](CODING-STANDARDS.md).
- chevo is a reference for structure and design, not a source of code. SYNTH keeps its own protocol (`protocol/`, byte-identical with the SYNTH client), defaults and Fallout-specific features.
- This file records source structure only. It makes no claim about an installed server, live providers or the game; open
  runtime checks are in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md), feature evidence in
  [FEATURE-PARITY-MATRIX.md](FEATURE-PARITY-MATRIX.md).

**Status values**

| Value | Meaning |
| --- | --- |
| `parity` | SYNTH has an equivalent in the chevo-shaped location. |
| `partial` | The shape is in place, but some logic is still procedural or legacy. The row says what is left. |
| `not started` | chevo has it, SYNTH has no equivalent, and nobody is working on it. |
| `deferred` | Left out on purpose for now (see [Deferred](#deferred)). |
| `SYNTH-specific` | No chevo counterpart. It stays product-specific. |

## Summary

The structural parity pass is finished, and since the handoff the entity, DAO, globals and `require_once` passes have
landed (#158–#225). Every `core_*` table now has an entity or a table DAO, the globals pass is down to its justified
remainder, and the raw SQL outside `core/dbmanager` is down to a small, classified set: transaction and lock control on
the request connection, a few intentional adapters (installers, the catalogue release, extension migrations, startup
checks) and about 90 statements still to convert. See [Remaining work](#remaining-work).

### Counts

Counts cover tracked non-test PHP. They exclude `vendor/`, `unittests/` (including `unittests/vendor/`) and `tests/`.
Each figure counts occurrences of the token, not lines or behaviours; see [How the counts are measured](#how-the-counts-are-measured).
The `2bc3752` column is a re-measurement of the handoff commit with the same commands, not the handoff's own
figures; the Note column records where those differ.

| Measure | `2bc3752` (2026-11-20) | `2bbe8c8` (final) | chevo `e1f32e4` | Note |
| --- | --- | --- | --- | --- |
| Non-test PHP files | 889 | 1,226 | — | |
| Base entities (`core/entities/base/*.php`) | 20 | 117 | 57 | The handoff table recorded 91, 93 and 93 for these three rows. Those figures do not reproduce at `2bc3752`; they match the #169 entity branch head before it merged. |
| System entities (`core/entities/system/*.php`) | 22 | 119 | 66 | |
| DAO files (`core/dbmanager/dao/*.php`) | 22 | 124 | 52 | Final: 119 `*Dao` classes plus five helpers (`ConnectorReferences`, `FailSoftQueries`, `JsonbOperatorSql`, `TextRows`, `UsesEntityManager`). 118 DAOs are listed in `Database::DAOS`; `ApiBadgeDao` is not. |
| `$db->` uses | 1,333 | 305 | — | The handoff table recorded 1,320. Final by directory: managers 151, workers 78, lib 22, processor 20, core 13, root 8, drivers 6, tools 4, ui 3. [Raw SQL](#raw-sql) splits them by kind. |
| `pg_*(` tokens | 870 | 330 | — | Includes 29 PostgreSQL function names inside SQL text (`pg_advisory_xact_lock(` and similar). PHP extension calls: 301. |
| Global uses (`global $x`, `$GLOBALS[`) | 3,360 | 66 | — | 6,471 before the globals pass. Unchanged since `e2e85c3`. |
| `require_once` | 1,838 | 1,684 | — | Unchanged since `e2e85c3`. |
| `lib/**/*.php` | 189: 170 four-line hooks, `autoload.php`, 18 real files | 190: 170 four-line hooks, `autoload.php`, 19 real files | small final classes | See [lib/](#lib). |

## Deferred

These were left out on purpose (user decision, 2026-11-20). Revisit them as projects of their own.

| chevo area | Why it is deferred |
| --- | --- |
| Slim/Twig UI over `/api/v1/edit` | A large rewrite. SYNTH keeps its `ui/*.php` pages. |
| Background Life (`BglManager`, BglLife/BglTrack workers, `Bgl*` entities, `bgl/*` routes) | A large subsystem of its own. |
| `playthrough_id` as a guid | It is a free-form protocol field shared byte-for-byte with the client, so it stays `text`. A switch needs a protocol and client change. New tables use uuid only for ids the server mints. |

## Not started

chevo has these and SYNTH has no equivalent. Nobody is working on them, and they need a decision before anyone starts.

| chevo area | What SYNTH has today |
| --- | --- |
| Generic EntityEditor (create, read, update and delete over DB entities via `/api/v1/edit`) | `api/v1/editing` covers the `config` entity only: list, read and PATCH, with no create or delete. |
| Dialogue websocket listener | `bin/notify-server.php` sends opt-in wake-up hints and carries the GameRpc channel. It is not started by default. Dialogue stays HTTP. |
| `managers/RumorManager` | A placeholder in chevo too. No SYNTH code. |

`NpcRegistration`, `ReferenceResolver` and `WorldManager` were on this list at the handoff. They now exist and are
tracked as `partial` under [Managers](#managers-managersname-psr-4-synthmanagersname).

## Core and persistence

| chevo area | SYNTH today | Status |
| --- | --- | --- |
| `core/dbmanager` (Doctrine ORM, DAOs, migrations, as the only DB boundary) | Doctrine with the hash-pinned migration manifest and `SchemaCheck` (`schema-check --offline`). The DAOs follow `ConfigSettingDao` (see `core/dbmanager/dao/README.md`). Outside it there are still 292 `$db->` uses (13 more are inside) and 164 `pg_*` extension calls; [Raw SQL](#raw-sql) says which are statements still to convert. | partial |
| `core/entities/base`, `core/entities/system` | 117 base and 119 system entities. Every `public.core_*` table the migrations create (120) is mapped by an entity except `core_api_badge`, `core_extension_store` and `core_extension_migration`, which have table DAOs (`ApiBadgeDao`, `ExtensionDataDao`). The handoff's missing families (actions and world, `worker_job`, the eleven key-less tables) landed in #172 (actions, world, `worker_job`) and #174 (the key-less tables). | parity (mapping); callers partial |
| `core_*` table naming and fresh-install migration | The schema was rebuilt from scratch in chevo style, with no upgrade migrations from the old tables. Every table has a `core_*` name (for example `core_npc`, `core_action_issued`, `core_log`). | parity |
| API keys | The shared Dwemer `api_keys` store (#62). It is kept on purpose, rather than a server-private table. | SYNTH-specific |
| `core/contracts` | `ApiKeyRef` (PSR-4, `Synth\Core\Contracts`), plus `dialogue.php` and `narrator.php`, which are not PSR-4 yet. | partial |

## API (`api/index.php` -> `api/routes.php` -> `api/v1/*`)

Every chevo v1 route has a SYNTH adapter in `api/v1/`. Most adapters are thin and call into the existing root entry points (`main.php`, `gamedata.php`, `stt.php`, `itt.php`, `tts_status.php`). `api/v2/` is the internal native-dispatch layer behind `main.php`, not a public version.

| chevo area | SYNTH today | Status |
| --- | --- | --- |
| Router, versioned routes and request boundary | `.htaccess` allowlist read by `router.php`. `Synth\Lib\{LocalAccess,SameOriginRequest,RequestBody}` sit behind `lib/management_request_guard.php`. Requests without a length get 411 and oversized bodies get 413. See [REQUEST-BOUNDARY.md](REQUEST-BOUNDARY.md). | parity |
| `books/read`, `context/event`, `context/history`, `dialogue/{request,spoken,cancel,observe}`, `locations/save`, `messages`, `narrator/{checkpoint,transcribe}`, `npc/{add,view}`, `plugins/manifest`, `save/{created,loaded}`, `speech/transcribe`, `tts/{studio,voice-preview,voice-sample,voice-samples}`, `user/input`, `vision/describe`, `health`, `extensions` | Adapters in `api/v1/`. `context/history` and `npc/view` are SYNTH-shaped reads, through `SaveState/HistoryRead` and `CharacterManager/NpcViewRead`. `messages/<id>` serves both the durable queue (integer id) and the legacy cache key. | parity |
| `ext/<id>/...` | `api/v1/ext.php` answers routes declared by enabled extensions (#182). | parity |
| `config` (`index`, `player-tts`) | `ui/global_settings.php` over `ConfigManager`. | partial |
| `editing` (generic edit API) | The `config` entity only. | partial (see [Not started](#not-started)) |
| `bgl/*` | — | deferred |

## Managers (`managers/<Name>/`, PSR-4 `Synth\Managers\<Name>\*`)

Code moved from `lib/` lives under `managers/<Name>/Native/`. It was moved, not rewritten: most of it is still procedural and uses the request-scoped `$db`, now mostly through DAO calls. The old path keeps a thin hook.

| chevo manager | SYNTH today | Status |
| --- | --- | --- |
| ActionsManager | Folder with `TwoStepActions`, `TradeOffer`, `CompanionOrders` and `Native/` action code (combat, gifts, social, wait, script proxy). `synth_social_tasks.php` still holds the largest block of raw SQL. | partial (procedural) |
| BookManager | `ReadingEvent`, `ReadingHistory`. | partial (no book entity) |
| CharacterManager | `CharacterManager`, `NpcViewRead`, and `Native/` identity, biography and voice code. | parity (class); Native procedural |
| ConfigManager | `ConfigManager` over `ConfigSettingDao` (five scopes, `setEditable()`, `explain()`) and `ConfigFile`. | parity |
| ContextManager | `ContextManager`, `ConditionProjector`, `ActorConditionValidator`, and `Native/` context builders (history, nearby, location, world state, reactions). SQL moved to DAOs in #211; `data_history.php` keeps one history query. | partial (procedural) |
| ExtensionManager | API 1.1: manifest validation, enable and disable, failure state in `conf/extensions.json`, 15 hooks (10 filters, 5 events), isolation, auto-disable, deferred events, extension routes, workers, a key/value store and `ext_<id>_*` tables with checked migrations (#182). Handler time limits, uninstall with data removal and distribution are not built. | partial |
| GameRpc | `rpc.game` over the notify-server socket (#178): bridge, loopback, method list and closed error codes. Opt-in. No server code calls `GameRpc::forSession()` yet, and the README records that the client answers `method_unavailable` for game data methods it does not have. | partial |
| ListenerManager | `ListenerRouter` plus `Native/` ingress, request and runtime code. | partial |
| LocationManager | `LocationCatalog` over `LocationDao` / `LocationStateDao`. | partial |
| MemoryManager | `Native/` memory recall, ranking, vectors, diary, and middle-term, reading and writing through `MemoryDao` and the memory batch, summary, diary and middle-term DAOs (#207, #208, #210, #215). | partial (procedural) |
| MessageHandler | `core_message_queue` with `MessageQueueDao` (#175): admit, complete, exactly-once deliver (`UPDATE ... RETURNING`, later fetches get 409), cancel, expire, purge and announcement retry, used only when the server's `SYNTH_MESSAGE_QUEUE` is on (on when unset, `MessageHandler::DEFAULT_ENABLED = true`; `0` disables it) and the session listed the `message.queue` capability (the client source lists it by default; `[Server] MessageQueue=false` disables it). Speech messages only; no 202 acknowledgement for dialogue (a streamed reply). `MessageStatusReader` keeps the legacy cache-key path. | partial |
| Narrator | `PrivateNarratorManager`, `PrivateNarratorProtocol`, `NarratorObjectIdentity`. | parity |
| Notification | `NotifyServer`, `NotifyPublisher`, `NotifyConfig`, `NotifyHint` (opt-in). | parity |
| NpcRegistration | `NpcRegistration`, `RegistrationResult` and `ActorProfileRegistration` (#164). `gamedata.php` hands the `actor_profile` record to `ActorProfileRegistration`. The placed and runtime registration API is called only by tests: there is no `runtime_actor.register`, `.retire` or `.sync` wire message yet, and placed actors are not registered without a plugin manifest. | partial |
| PromptManager | `PromptManager` plus `Native/` prompt composition and injections. `core_prompt` goes through `PromptDao` (#177). | parity (class); Native procedural |
| ReferenceResolver | `PluginManifest`, `ReferenceResolver`, `Resolution` and `DeferredReferences` (#167). No endpoint calls it, and pending references are not persisted (`core_context_event` has no `context_metadata` column). | partial |
| SaveState | `SaveState`, `HistoryRead`, and `Native/` playthrough snapshot and rollback. `playthrough_schema.php` and `save_rollback.php` still use raw SQL. | partial (no save storage) |
| ServerStatus | `HealthCheckManager` plus `Native/` backup, log trim, auditing. | parity |
| TtsStudio | `VoiceSampleStore`, `VoicePreviewManager`, and `Native/` TTS jobs, pronunciation, voice catalog and cloning. | parity (class); Native procedural |
| Relationships | `AuthoredRelationships`, `Native/` evaluator, `Legacy/` relationship system. Statements moved to DAO methods in #206; the visible-history readers still query the caller's connection. | partial (procedural) |
| WorldManager | `WorldManager` (#171) over the `world_context` payload: Fallout date, time, location, worldspace and weather. `ContextManager/Native/data_location.php` delegates to it. No hold or region concept and no derived weekday or season; the canonical region lookup is still legacy code. | partial |
| RumorManager | — | not started |
| BglManager | — | deferred |
| OghmaManager | Skyrim knowledge. SYNTH has `WorldKnowledgeManager` (with `Fallout/` content packs). | SYNTH-specific |
| (no chevo equivalent) | `Conversation` (rechat, autochat), `QuestManager`, `VisualContext`, and the flat `SpeechManager`, `SessionManager`, `ReceiptManager`, `DialogueManager`. | SYNTH-specific |

## Drivers

| chevo area | SYNTH today | Status |
| --- | --- | --- |
| `drivers/llm` | `LlmDriverInterface`, `LlmWrapper`, and one driver per provider in `Drivers/` (OpenAI, OpenRouter, Google OpenAI, Groq, KoboldCpp, Player2). The six `connector/*json.php` files are 8-line hooks that keep the legacy class names. `connector/templates/` holds prompt templates. `Logging/` records requests to `core_llm_request_log` (#165). | parity |
| `drivers/tts` | `TtsDriverInterface`, `TtsWrapper`, and one driver per provider registered via `TtsProviderRegistry`, plus `SpeechAudio` and `SpeechStorage`. | parity (shape) |
| `drivers/stt`, `drivers/itt` | Wrapper plus one driver per provider. `stt/`, `itt/` are thin hooks. | parity |

## Workers and bin

| chevo item | SYNTH today | Status |
| --- | --- | --- |
| `WorkerInterface`, `WorkerRegistry`, `WorkerLimits`, `WorkerReport`, Supervisor, `bin/worker.php`, `bin/worker-supervisor.php` | `workers/Supervisor`. `service/start.sh` starts the supervisor with a `speech` lane and a `general` lane. The legacy manager runs only with `SYNTH_LEGACY_MANAGER=1`. Enabled extensions' declared workers join the `general` lane. | parity |
| Memory, MiddleTerm, Relationships, ExtensionEvents workers | `workers/{Memory,Relationships,ExtensionEvents}`. The logic was moved, not split into chevo's four memory workers. | partial |
| TTS, profile and Player2 workers | `workers/{Speech,Profiles,Player2}`. | SYNTH-specific |
| `bin/notify-server.php` | Opt-in wake-up hints and the GameRpc channel. | parity |
| `bin/message-maintenance.php` | Retention for the response log and the durable message queue: expiry, cancellation of cancelled speech work, announcement retry, and deletion of old terminal messages. History events are counted, never pruned. | parity |
| LLM request log tools | `bin/llm-request-log.php`: `list`, `show`, `replay` (dry run unless `--send`) and `prune` with retention settings. | parity |

## Config, extensions, tests, startup

| chevo item | SYNTH today | Status |
| --- | --- | --- |
| `config/config.inc.tmpl.php` | Read by `ConfigManager/ConfigFile`. `conf/conf.sample.php` stays as a compatibility shim. | parity |
| `extensions/` | `extensions/talk-awareness` is the reference extension, with its page in Config Hub. `ext/relationship_system` stays as a thin hook over `managers/Relationships`. | parity |
| `tests/` and root `phpunit.xml` | `tests/` and `unittests/` under one root `phpunit.xml`, run in CI. | parity |
| `lib/Logger` | `Synth\Lib\Logger` (classifier, level, caller, redaction, file sinks, a single error handler). | parity |
| Startup (one composition root, PSR-4) | `lib/autoload.php` is the single autoloader entry and `Synth\Bootstrap` is the composition root. Config, DB and engine-path access go through `Bootstrap` accessors (#150–#187); the legacy function files load through Composer `files` (#198). | partial (`require_once` remains) |

### lib/

`lib/` has 19 real files and `autoload.php`. The other 170 are four-line hooks kept at their old paths for legacy callers. [LIB-INVENTORY.md](LIB-INVENTORY.md) records where each moved file went.

| File | Planned home |
| --- | --- |
| `LocalAccess`, `SameOriginRequest`, `RequestBody`, `PlayerName`, `PronunciationDictionary`, `classes/Logger` | Stay in `lib/` (small final classes, as in chevo). |
| `postgresql.class.php` | The legacy `$db` wrapper. It goes away when the raw SQL is gone. |
| `settings.php`, `core/settings_presets.php`, `db_connection_settings.php` | `ConfigManager`. |
| `runtime_bootstrap.php` | `Synth\Bootstrap`. |
| `management_request_guard.php` | Stays as the request-boundary entry. |
| `legacy_core_functions.php` | The guarded functions from the old connector, narrator and NPC classes, loaded by Composer `files` (#198). Owning managers. |
| `chat_helper_functions.php`, `data_functions.php`, `misc_ui_functions.php`, `utils.php`, `logger.php`, `synth_private_narrator.php` | Owning managers or `ui/`. `logger.php` and `synth_private_narrator.php` are already near-hooks. |

## Remaining work

The long tail. Each item can be done in small slices, one draft PR each, with a full sandbox run on the exact head.

### Entities and DAOs (schema)

The schema needs a single writer, because the migration manifest is hash-pinned and the baseline statement count is fixed in a test. The generator and the hash tool are in [tools/schema/](../tools/schema/README.md); the design record is [SCHEMA-REDESIGN.md](SCHEMA-REDESIGN.md) (§13 records the owner decisions) and [SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md).

Done since the handoff: entities and DAOs for every family (#158–#172), primary keys for the eleven key-less tables
(#174), `core_llm_request_log` (#165), `core_message_queue` (#175), and SchemaMigrations step 10b, which deleted
`lib/core/database_schema/*.sql` (#183).

Schema steps still to do:

- Rename `core_npc.id` to `character_id` (the baseline still creates `core_npc.id`), then the remaining column renames.
- Replace the remaining views. The fresh baseline still creates six `synth.*` views (`combined_bio_templates`,
  `combined_core_action`, `combined_descriptions`, `memory_v`, `synthnpcs`, `worldknowledge_effective`).
  `public.synth_key_references` is the shared-key interface and is meant to stay.
- Add `context_metadata` to `core_context_event` so `ReferenceResolver` can persist pending references.
- Decide whether `core_api_badge` and the extension tables get entities, and register `ApiBadgeDao` in `Database::DAOS`
  or record why it stays outside.

### Raw SQL

Counted at `2bbe8c8` with the method below. Inside `core/dbmanager` raw DBAL and `pg_*` are allowed: the DAO README
keeps locks, leases, claims and append-only checkpoint writes as parameterized DBAL inside the DAO, and
`lib/postgresql.class.php` (84 `pg_*` calls) is the legacy `$db` wrapper itself.

**`$db->` uses (305):**

| Kind | Uses | Disposition |
| --- | --- | --- |
| Lock and transaction wrappers (`withNativeRequestLock` 88, `isTransactionIdle` 36, `withManagementTransaction` 7, `withClaimedRequestLock` 3, `assertTransactionCurrent` 1) | 135 | Not SQL statements. They hold the request connection's lock or transaction around DAO calls. Stay until the wrapper moves into `core/dbmanager`. |
| Escaping (`escapeLiteral` 26, `escape` 6) | 32 | Builds SQL text next to the statements below; goes with them. |
| Statements inside `core/dbmanager` (`SharedApiKeyStore`, `SharedSessionDriver`, `UtteranceRepository`) | 11 | Inside the boundary. |
| Transaction control outside it (`BEGIN`, `COMMIT`, `ROLLBACK`, `SET LOCAL`, `LOCK TABLE`) | 53 | Intentional: the caller owns the transaction its DAO calls share. |
| Liveness and encoding probes (`SELECT 1`, `SHOW server_encoding`) | 4 | Intentional. |
| Session advisory locks (`workers/Player2/Native/player2_health.php`, `workers/Profiles/Native/dynamic_update_util.php`) | 4 | Intentional single-runner guards; move into a DAO with their workers. |
| Intentional data and DDL adapters | 24 | Fallout catalogue release apply on its own connection under table locks (`fallout_catalogue_sql.php`, 10, plus the catalogue connection accessor in `synth_protocol_state.php`, 1); startup and bootstrap checks (`lib/runtime_bootstrap.php` 3, `tools/bootstrap-database.php` 3); the legacy index drop in `worldknowledge_catalog.php` (1); table-parametric rollback in `save_rollback.php` (3); the `current_worldknowledge_topic` read in `processor/worldknowledge.php` (1, see below); the two original-context reads in `synth_protocol_state.php` (2, a separate follow-up, see below). |
| Statements still to convert | 42 | Listed below. |

Statements still to convert (42), by file:

- `core_context_event` writes through the legacy `$db->insert()`: `processor/comm.php` 8, `gamedata.php` 5, `main_synth_pipeline.php` 2, `lib/chat_helper_functions.php` 1, `processor/import_files.php` 1, `synth_protocol_state.php` 1.
- `processor/comm.php` save-rewind cleanup (8): two reads (`core_context_event`, `core_memory`) with one `fetchArray` over the second, four deletes and one update across `core_context_event`, `core_speech`, `core_memory`, `core_memory_log` and `core_response_log`, with the cutoff interpolated into the SQL text.
- `lib/settings.php` (4): general-scope `core_config_setting` reads and upserts. Planned home: `ConfigManager`.
- `managers/ActionsManager/Native/synth_social_tasks.php` (5), `workers/Profiles/Native/synth_profile_state.php` (2), `managers/ContextManager/Native/data_history.php` (1), `managers/ListenerManager/Native/synth_runtime.php` (1, the default-profile repair), `workers/Profiles/Native/dynamic_update_util.php` (1, a history CTE read), `managers/Conversation/Rechat/synth_autochat.php` (1, `insertNativeHistoryOnce`).
- `ui/index.php` (1): reads `openai_token_count`, which no migration creates. Fix or remove the page branch.

**`pg_*` extension calls outside `core/dbmanager` and `lib/postgresql.class.php` (164):** 86 statement calls
(`pg_query`, `pg_query_params`, `pg_send_query_params`); the other 78 read results, report errors or check connection
state for those statements.

| Kind | Statements | Disposition |
| --- | --- | --- |
| Savepoint control in `managers/Conversation/Rechat/` (five files) | 21 | Intentional: nested rollback inside the caller's transaction. |
| Extension migration runner (`ExtensionManager/Native/plugin_package_manager.php`) | 7 | Intentional adapter: checked extension migrations in their own transaction. |
| Installer, repair tool and catalogue connection (`bin/install-db.php` 3, `tools/repair-npc-biography.php` 2, `fallout_catalogue_sql.php` 3) | 8 | Intentional adapters. |
| Lock control on the caller's connection (`synth_profile_state.php`, a `LOCK TABLE`) | 1 | Intentional, as for the `$db->` transaction control above. |
| Playthrough snapshot schemas (`SaveState/Native/playthrough_schema.php`) | 9 | To convert. |
| Data statements on the caller's locked connection | 40 | To convert, keeping the locks and the connection scope inside the DAO: `synth_social_tasks.php` 13, relationship readers in `managers/Relationships/Native` and `workers/Relationships/Native` 14, `TtsStudio/Native/synth_tts_jobs.php` 5, `QuestManager/Native/quest_knowledge.php` 3, `processor/worldknowledge.php` 2, and one each in `synth_history_speech.php`, `synth_audience_control.php` and `synth_action_inventory.php`. |

Constraints recorded for the residue:

1. **Original-context reads:** `synth_protocol_original_context()` in
   `managers/MessageHandler/Native/synth_protocol_state.php` keeps its v2 snapshot read and v1 latest-context read as SQL.
   The DAO batch (#217–#225) left them out on purpose: a combined conversion did not fit the fake connections that
   existing suites install, and #224 kept these two reads unchanged. They are a separate follow-up with its own
   acceptance cases (v2 exact snapshot, v1 latest-context and failed-read behaviour).
2. **`processor/worldknowledge.php`:** one read of `current_worldknowledge_topic` stays as SQL, because `WorldKnowledgeVocabularyTest`'s fake `$db` scripts that query. To convert it, first change the test to stub `ConfigManager`, which needs a reset or inject hook on the singleton. The `synth_mode` readers also keep SQL (locked or on their own connection).
3. **Failure semantics:** the #217–#225 slices were intended to keep each converted read's prior failed-query and
   no-row behaviour. Known error-path differences are tracked separately. One change is intentional: since #225 a
   failed bulk event-log delete propagates the database error instead of reporting the selected rows as deleted.
   Conversions of the residue should state any such change the same way.

### Globals and require_once

The method and the converter are in [GLOBALS-PASS.md](GLOBALS-PASS.md). Its per-slice tables use the older `71d3eb8` baseline.

- **Remaining global uses (66):** `bootstrap.php` 49 (the `Bootstrap` store itself), literal `{$GLOBALS["SYNTH_NAME"]}`
  and `PLAYER_NAME` prompt placeholders 11 (text to substitute, not accesses), `lib/classes/Logger.php` 2,
  `tools/globals-*.php` comments 2, and `global $wkArticleDao` twice in `ui/worldknowledge_upload.php` (from #196).
  GLOBALS-PASS.md records 64 at `e2e85c3`, but the same method gives 66 there and at `2bbe8c8`; its exception list does
  not name the `ui/worldknowledge_upload.php` uses.
- **Accessor pattern:**
  - Reads go through `\Synth\Bootstrap::db()`, `enginePath()`, `engineRoot()`, `requestEvent()`, `claimedRequest()`, `gameRequest()`, or `config('NAME', $default)` for anything else.
  - Don't convert writes, by-reference uses or `new $GLOBALS[...]`.
  - Never run the converter on `bootstrap.php`, because it recurses.
  - To retire a name: add a setter on `Bootstrap` (or a request-context object), move the writers to it, then switch the accessor to read the object.
- **`require_once` (1,684):** by directory ui 398, managers 396, lib 235, drivers 138, root 129, workers 64, processor 59,
  service 42, api 41, core 37, tts 34, stt 26, tools 25, functions 20, bin 14, debug 9, ext 8, connector 5, prompts 4.
  The most-required targets are `runtime_bootstrap.php` (109), `autoload.php` (96), `logger.php` (76),
  `synth_protocol_state.php` (43) and `chat_helper_functions.php` (40). GLOBALS-PASS.md (status section) records why the
  remaining explicit requires are entry loaders, files with load-time side effects or module hubs. Next steps:
  1. Turn the remaining function groups into autoloaded classes, keeping the old function names as thin wrappers until their callers move, or add them to the Composer `files` section where they are always needed.
  2. Work one directory per PR.

### Behaviour gaps against chevo

These came from comparing chevo's feature set with SYNTH. They are product work rather than restructuring, so each needs an owner decision.

| chevo behaviour | SYNTH today |
| --- | --- |
| Durable message queue (`core_message_queue`): a 202 reply, then a WebSocket `message_available` push, then a fetch that succeeds exactly once (a second fetch gets 409) | Built for speech (#175): exactly-once fetch with 409 afterwards, announcement over the notify channel, polling fallback. On by default on both sides (server unset = on; client source lists `message.queue` by default) and used only when both agree; `SYNTH_MESSAGE_QUEUE=0` or `[Server] MessageQueue=false` falls back to the legacy cache-key poll. Still missing: a 202 acknowledgement for dialogue and message types other than `speech`. |
| LLM request log with replay (`core_llm_request_log`) | Built (#165): recorder, sanitizer, replay through the same or another connector, retention. |
| 14 extension hook points, plus extension routes (`/api/v1/ext/<id>/`), workers and `ext_<id>_*` tables | Built (#182): 15 hooks in API 1.1, routes, workers, store and tables. chevo's `game.event.recorded`, `npc.registered` and `playthrough.started` are not offered. Handler time limits and uninstall with data removal are not built. |
| GameRpc channel (`rpc.request` / `rpc.response` over the socket) | Server channel built (#178), opt-in. No server caller and no prompt use yet. |
| Typed runtime-actor registration and deferred load-order references | Server classes exist (#164, #167). The wire messages, plugin-manifest endpoint and persisted pending references are not built. |
| A separate Decisions connector for two-step actions | Two-step actions (#68) make one bounded call through the `CORE_CONNECTOR_TWO_STEP` connector setting, not a separate Decisions connector. |
| Extension distribution (phase 6) | Not built (also pending in chevo). |

### Risks

- **Shared API keys:** DwemerDistro doesn't register SYNTH as a key consumer yet, so CHIM could delete a key SYNTH uses (#62). The server publishes `public.synth_key_references` for that bridge; the consumer side is outside this repository and was not checked here.
- **Not checked in a real install:** nothing in the parity pass has been checked with real Apache, a browser or in-game. The highest-risk changes are #112 (front-controller allowlist) and #110 (supervisor as the default runtime). The DAO conversions (#168–#225) change how almost every request reads and writes the database; their coverage is disposable-database and fake-connection suites, not an installed server.

## How the counts are measured

Run from a SynthServer checkout. `REF` is the commit being counted; `git grep` reads the commit's tree, so no checkout
or working-tree state is involved. Each pipeline counts token occurrences.

```bash
REF=2bbe8c86794484afe5c692f8347350981430f2d4
x=(-- '*.php' ':!vendor/' ':!unittests/' ':!tests/')
git ls-tree -r --name-only "$REF" | grep '\.php$' | grep -Ev '^(vendor|unittests|tests)/' | wc -l   # files
git grep -o -F '$db->' "$REF" "${x[@]}" | wc -l                                  # $db-> uses
git grep -o -E '\bpg_[a-z_]+\(' "$REF" "${x[@]}" | wc -l                         # pg_* tokens
git grep -o -E '(\bglobal \$|\$GLOBALS\[)' "$REF" "${x[@]}" | wc -l              # global uses
git grep -o -E '\brequire_once\b' "$REF" "${x[@]}" | wc -l                       # require_once
git ls-tree --name-only "$REF" core/entities/base/ | grep -c '\.php$'           # and system/, core/dbmanager/dao/
git grep -h -o -E '\$db->[A-Za-z_]+' "$REF" "${x[@]}" | sort | uniq -c          # $db-> by method
```

For the [Raw SQL](#raw-sql) split, list the statement methods (`execQuery`, `fetchOne`, `fetchAll`, `insert`, `query`,
`delete`, `update`, `fetchArray`, `executeQuery`, `executeStatement`, `fetchAssociative`, `getCatalogueSqlConnection`,
`insertReceiptHistory`, `insertNativeHistoryOnce`, `GetLastError`) with `git grep -n` and classify each call site by its
SQL text. The `pg_*` token count includes PostgreSQL function names written inside SQL strings; subtract them to get PHP
extension calls. A count is a text occurrence, not a behaviour: one statement can span several tokens, and a converted
caller can still hold a lock wrapper.

For chevo, use `git ls-tree -r --name-only origin/unstable core/entities/base` (and the same for `system` and `core/dbmanager/dao`). Read chevo only through git; don't copy code from it.

Update the matching row in the same PR that changes an area. Keep this file to status; design belongs in the manager's `README.md` or `PLAN.md`.
