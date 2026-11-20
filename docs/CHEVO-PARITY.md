# Chevo parity checklist

Area-by-area comparison of Synthserver with **chevo** (the CHIM 4.0 server, reference commit `8ebe86e`).
Layout and layering are in [SERVER-FILE-LAYOUT.md](SERVER-FILE-LAYOUT.md). chevo is a structure and
design reference, not a code source; SYNTH keeps its own tables, protocol (`protocol/`) and defaults.

**Status** values: `parity` (SYNTH has an equivalent in the target location), `in progress` (open PR or
session; the legacy implementation may exist and be unmoved), `missing` (no SYNTH equivalent and none
planned yet), `SYNTH-specific` (no chevo counterpart; stays product-specific).
**Owner PR** `TBD` means the command centre assigns the number when the session opens its PR.

## Core and persistence

| chevo area | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `core/dbmanager` (Doctrine ORM, DAOs, migrations; only DB boundary) | Doctrine pilot (`repositories/`, migrations, `SchemaCheck`) beside about 1,200 legacy `$db->` calls in `lib/`, `processor/`, `ui/` | in progress | #63 |
| Raw SQL outside `lib/` (`$db->` lines; `pg_*` calls) | before: managers 141, processor 69, ui 212, service 13, root entry points 86, api 0 (`pg_*`: managers 146, ui 101, processor 4). After the `core_config_setting` slice: processor 53, service 11, root 73, others unchanged (-30). Single-row reads and writes now go through `ConfigManager` (`ConfigSettingDao`, plus `setMany`/`upsertMany` for the atomic multi-row Fallout stats write). Left as SQL: locked or connection-scoped reads (`FOR SHARE` in `managers/MemoryManager/Native/synth_diary_state.php`, `ui/api/audience_control.php`), prefix `LIKE` reads and deletes in `ui/xtts_clone.php`, and `ui/tmpl/navbar.php` (own session fallback). Tables with raw SQL and no DAO yet go to the schema session (list in the PR description) | in progress | this PR |
| `core/entities/base` (mapped `Abstract*`) | none yet | in progress | #63 |
| `core/entities/system` (classes top-level code uses) | none yet; `lib/core/*.class.php` legacy | in progress | #63 |
| `core_*` table naming and fresh-install migration | schema redesign rebuilds tables from scratch, CHIM 4 style | in progress | #63 |
| Entity set: Npc, NpcActor, Profile, Playthrough, Plugin, Location, Memory, Book, ContextEvent, QueuedMessage, ApiBadge, connectors (Llm/Tts/Itt) | legacy tables and classes | in progress | #63 |
| `core/entities/Relationships` (authored relationships) | design PR | in progress | #67 |
| Runtime (0xFF) actors and load-order references | design PR | in progress | #66 |
| API keys | shared Dwemer `api_keys` store (kept deliberately, not a server-private table) | in progress | #62 |
| `core/contracts` | `dialogue.php`, `narrator.php` (not PSR-4 yet) | in progress | TBD (domain move) |

## API (`api/index.php` -> `api/routes.php` -> `api/v1/*`)

| chevo area | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| Router and versioned routes | `api/index.php` -> `api/routes.php` -> `api/v1/<area>/` (thin adapters over the root entry points, which stay as they are); `api/v2/dispatch.php`, `routes.php` stay as the internal native-dispatch layer behind `main.php`, not a public version ([api/v2/README.md](../api/v2/README.md)) | parity (entry shape); handlers move behind managers as domains move | this PR |
| Request boundary (`index.php` + `.htaccess` deny by default; `lib/LocalAccess`, `SameOriginRequest`, `RequestBody`; `Content-Length` limits in `api/index.php`) | `.htaccess` allowlist read by `router.php`; `Synth\Lib\{LocalAccess,SameOriginRequest,RequestBody}` behind `lib/management_request_guard.php`; 411/413 limits for `speech/transcribe`, `vision/describe`, `voice/*`; no web `display_errors`; [REQUEST-BOUNDARY.md](REQUEST-BOUNDARY.md) | parity (`debug/` stays in place: frozen schema chain and middleterm still use it) | this PR |
| `books/read` | `api/v1/books/read.php`: a `context` event carrying `reading_event`, via `main.php` | parity (adapter) | this PR |
| `config` (`index`, `player-tts`) | `ui/global_settings.php`, `conf/` | in progress | #65 |
| `context/event` | `api/v1/context/event.php` (`context` event via `main.php`) | parity (adapter) | this PR |
| `context/history` | `api/v1/context/history.php` over `managers/SaveState/HistoryRead` (`history.read`; the active load's lines as the current target NPC reads them, through the saved-ancestry reader; read-only, 1 KiB body limit, 409 when the session is not active) | parity (SYNTH-shaped read; chevo reads `PrismaHistoryReadDAO`) | this PR |
| `dialogue` (`request`, `spoken`, `cancel`) | `api/v1/dialogue/*` adapters over `main.php` (`api/v2` conversation dispatch behind it) | parity (adapter); lifecycle work continues | this PR; TBD (conversation lifecycle) |
| `dialogue/observe` | `api/v1/dialogue/observe.php`: a `context` event carrying `payload.observed_dialogue` (first-observed vanilla lines), via `main.php` and `lib/observed_dialogue.php` | parity (adapter) | this PR |
| `editing` (generic edit API) | `api/v1/editing/index.php`: `GET /edit`, `/edit/{entity}/_schema`, list/read/PATCH for the `config` entity (entity, scope, field and key allowlists; management guards; generic errors) | partial (config entity only; no create/delete, no DB-entity editor yet) | this PR |
| `extensions` | `api/v1/extensions.php`: `GET /extensions`, `POST /extensions/{id}/{enable,disable}`; id checked against the registry allowlist, same-origin guard, generic errors; `ext/relationship_system` kept | parity (list, enable, disable) | this PR |
| `health` | `api/v1/health.php` over root `health.php` (read-only readiness, 503 contract; `health/{live,db,api-keys-configured,npcs-available,llm}`) | parity (adapter) | this PR; TBD (health notice) |
| `locations/save` | `api/v1/locations/save.php` (`location_catalog` event via `main.php`; no storage yet) | parity (adapter) | this PR; TBD (locations) |
| `messages` (response queue delivery) | `GET /api/v1/messages/<id>` over `tts_status.php` (id is the TTS cache key); `processor/`, `ui/response_queue.php` | parity (adapter, delivery state only) | this PR |
| `narrator/checkpoint` | `api/v1/narrator/checkpoint.php` (`narrator_command`, `narrator_receipt` via `main.php` to `api/v2` narrator route, `PrivateNarratorManager`) | parity (adapter) | this PR |
| `narrator/transcribe` | `api/v1/narrator/transcribe.php` over `stt.php` (same multipart contract and recording limit as `speech/transcribe`, `Cache-Control: no-store`); SYNTH's `stt.php` has no narrator-only step, so the route is a separate lane, not separate processing | parity (adapter) | this PR |
| `npc/add` | `api/v1/npc/add.php` (`actor_profile` record via `gamedata.php`); runtime actor design | parity (adapter); runtime actors in progress | this PR; #66 |
| `npc/view` | `api/v1/npc/view.php` over `managers/CharacterManager/NpcViewRead` (`npc.view.read`; name, kind and status from `NpcActorDao` / `NpcRuntimeActorDao`; never registers or updates, no profile text; 1 KiB body limit) | parity (SYNTH-shaped read) | this PR |
| `plugins/manifest` | `api/v1/plugins/manifest.php` (`loaded_plugins` record via `gamedata.php`); `ui/server_plugins.php`, `data/plugin_packages/` | parity (adapter) | this PR |
| `save/created`, `save/loaded` | `save/loaded`: `init` event via `main.php`; `save/created`: `save_created` event (capability `session.save_created`, sent by the flat client on F4SE `kPostSaveGame`) via `main.php` to `api/v2`, recorded through `SessionManager::saveCreated` and `SaveState::created` (logged; no storage until the save schema lands) | parity (adapter) | this PR; TBD (save storage) |
| `speech/transcribe` | `api/v1/speech/transcribe.php` over `stt.php`; `stt/` | parity (adapter) | this PR |
| `tts` (`studio`, `voice-preview`, `voice-sample`, `voice-samples`) | `api/v1/tts/*.php`: `studio` over `ui/api/player_tts_preview.php` (POST, management guard and dashboard token added), `voice-preview` and `voice-samples` over `api/v1/voice/{preview,samples}.php`, `voice-sample` over `voice-samples` with one voice required | parity (adapter) | this PR |
| `user/input` | `api/v1/user/input.php` (`input_text`, `input_audio` via `main.php`); `player_rewrite.php` is a CLI worker, not an entry point | parity (adapter) | this PR |
| `vision/describe` | `api/v1/vision/describe.php` over `itt.php`; `itt/` | parity (adapter) | this PR |

## Managers (`managers/<Name>/`)

A domain is a folder `managers/<Name>/` (PSR-4, `Synth\Managers\<Name>\*`). The managers moved so far keep a thin
`class_alias` hook at their old flat path (`managers/<Name>.php`) for legacy callers; the flat files that remain are
SYNTH-specific (see the last row).

| chevo manager | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| ActionsManager (actions, companion orders, confirmed trading) | `functions/`, `lib/synth_*_actions.php` | in progress | TBD (orders/actions, trade confirmation); #68 two-step actions |
| BookManager | none | in progress | TBD (reading) |
| CharacterManager | `managers/CharacterManager/CharacterManager.php` (legacy hook `managers/CharacterManager.php`) | folder (resolution only; observations stay with their domains) | this PR |
| ConfigManager (scoped settings) | `managers/ConfigManager/ConfigManager.php` over `ConfigSettingDao`: five storage scopes (get/set/delete/all), validated `setEditable()` from `conf_schema.json`, `explain()` (Global -> profile -> NPC, global-only keys skipped). `lib/settings.php` helpers and the config pages (`global_settings`, `home`, `llm_connectors`, `tts_connectors`, `xtts_clone`) call it | partial: schema-derived definitions, not the dotted-key registry of #65; `getForCharacter`, `applyToGlobals` and `$expected` writes not built | #65, this PR |
| ContextManager | `managers/ContextManager/` (`ContextManager`, `ConditionProjector`, `ActorConditionValidator`; legacy hook `managers/ContextManager.php`) | folder; one implementation | this PR; TBD (active effects) |
| ExtensionManager | none (`ext/relationship_system` legacy) | parity for the config-file model (manifest validation, enable/disable and failure state in `conf/extensions.json`, 2 filters + 2 events, error isolation, auto-disable after 5 consecutive failures, optional deferred events, API and UI); no per-extension tables or handler time limits | this PR |
| GameRpc | `managers/GameRpc/` (`GameRpcClient` contract, exceptions, `UnwiredGameRpcClient`); no game-side channel exists and nothing calls it | contract only | this PR |
| ListenerManager | `managers/ListenerManager/ListenerRouter` (pure parse and rotation, same rules as legacy `parseDialogueListenerNames`); the legacy functions stay live | in progress (no name matching, segmentation or aliases) | this PR |
| LocationManager | `lib/` location ancestry | in progress | TBD (locations) |
| Notification (wake-up hints) | `managers/Notification/` (`NotifyServer`, `NotifyPublisher`, `NotifyConfig`, `NotifyHint`) | done (opt-in) | this PR |
| MemoryManager | `managers/MemoryManager/` (`Native/`: memory recall and ranking, vector helper, diary state, middle-term policy); `lib/synth_memory*`, `lib/synth_diary*`, `lib/synth_middleterm*`, `lib/memory_*` are thin hooks. Moved, not rewritten: still procedural with request-scoped `$db`; no `MemoryManagerInterface` and no DAO use yet | in progress (consolidated, not class-based) | this PR |
| Narrator | `managers/Narrator/` (`PrivateNarratorManager`, `PrivateNarratorProtocol`, `NarratorObjectIdentity` for talking objects; legacy hooks `managers/PrivateNarratorManager.php` and `lib/synth_private_narrator.php`), contract stays in `core/contracts/narrator.php` | folder; one implementation | this PR |
| MessageHandler (durable outbound queue) | `managers/MessageHandler/MessageStatusReader` (read side over the TTS job, status file and media registry behind `GET /api/v1/messages/<id>`); no queue table, so no create, cancel, expiry or delivered state | in progress (read side only; needs the queue table) | this PR; schema #63 |
| NpcRegistration | `gamedata.php` | in progress | #66 |
| OghmaManager | n/a (Skyrim knowledge); SYNTH has `WorldKnowledgeManager` | SYNTH-specific | n/a |
| PromptManager | `managers/PromptManager/PromptManager.php` (legacy hook `managers/PromptManager.php`), `prompts/`; the unused `lib/synth_prompt_manager.php` is removed | folder; one implementation | this PR |
| ReferenceResolver | none | in progress | #66 |
| RumorManager | none (placeholder in chevo) | missing | n/a |
| SaveState | `managers/SaveState/SaveState.php` (legacy hook `managers/SaveState.php`) | folder | this PR |
| ServerStatus | `managers/ServerStatus/HealthCheckManager.php`, `health.php` (thin dispatcher), `ui/system_health.php` | folder (health checks); TBD (health notice) | this PR |
| TtsStudio | `managers/TtsStudio/` (`VoiceSampleStore`, `VoicePreviewManager`), `api/v1/voice/`, `ui/xtts_clone.php`, `vsx.php` | folder (voice samples and preview); UI pages stay | this PR |
| WorldManager | `WorldKnowledgeManager`, observed equipment | in progress | TBD |
| (no chevo equivalent) `SpeechManager`, `SessionManager`, `ReceiptManager`, `DialogueManager` | flat files | SYNTH-specific | n/a |

## Drivers

| chevo area | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `drivers/llm` (Interface, `LLMWrapper`, `Drivers/`) | Done: `LlmDriverInterface` and `LlmWrapper`; `OpenAiCompatibleDriver` and `PlayerRewriteDriver` are built and called through the wrapper. `connector/*` classes load through `LlmWrapper::legacyConnector()`. Left: the provider logic in `connector/` still lives there (stateful `open/process/close/fast_request`) and moves into `Drivers/` one provider at a time | in progress | this PR |
| `drivers/tts` (Interface, `TTSWrapper`, `Drivers/`, audio, storage) | `TtsDriverInterface`, `TtsWrapper`, `ProfileSpeechDriver` and `NarratorSpeechDriver` (through `TtsSynthesisInterface`) are behind the wrapper. Each `tts/tts-*.php` provider is a driver in `Drivers/<Name>Driver.php` (`TtsProviderInterface`) registered by a thin hook via `TtsProviderRegistry`. Audio is `SpeechAudio`, storage is `SpeechStorage`; `lib/speech_cache.php` and `lib/npc_tts_status.php` delegate to them. The provider bodies still read the legacy TTS globals; typed provider settings move with the connector entities | parity (shape); provider settings in progress | this PR; #63 (connector entities) |
| `drivers/stt` | Done: `SttDriverInterface`, `SttWrapper`, one driver per provider (whisper, parakeet, localwhisper, azure, deepgram, gemini, inworld, none); `stt/stt-*.php` are thin hooks that resolve globals and API badge keys | done | this PR |
| `drivers/itt` | Done: `IttDriverInterface`, `IttWrapper`, drivers for openai, openrouter, google_openai, custom, llamacpp; `itt/itt-*.php` and `lib/itt_service.php` are thin hooks | done | this PR |

## Workers, bin and notification

| chevo item | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `WorkerInterface`, `WorkerRegistry`, `WorkerLimits`, `WorkerReport` | `workers/*.php` (storage-agnostic) | done | #82 |
| `ExtensionEvents` worker | `workers/ExtensionEvents/ExtensionEventsWorker` (`extension-events`), draining `FileEventQueue` (`data/extensions/events/`, no table); queue is used only when `conf/extensions.json` sets `deferred_events` | done | this PR |
| `MemoryContext`, `MemorySources`, `MemoryEmbedding`, `MiddleTerm` workers | `workers/Memory/`: `MemoryRunner` runs the dispatch pass and persisted jobs (`service/memory_dispatch.php`, `service/memory_worker.php` are hooks), `Native/` holds the memory, diary and middle-term job functions and the legacy middle-term task (`service/processors/middleterm` is a hook); registered via `MemoryWorker` (adapter over `service/memory_dispatch.php`) and `MiddleTermWorker` (adapter over `service/task_dispatch.php middleterm`); the manager's `native_memory` entry point is a thin hook over the registry. Not yet split into chevo's four workers; DAOs for `core_memory`, `core_memory_log`, `core_context_event` are not available yet | in progress (logic moved, not class-based) | #82, this PR |
| `Relationships` worker | worker logic in `workers/Relationships/`: `RelationshipRunner` runs the dispatch pass and persisted jobs, and the job, dispatch and interaction-queue functions are in `Native/`; `service/relationship_worker.php`, `service/relationship_dispatch.php` and `service/processors/native_relationship` are thin hooks; registered as `workers/Relationships/RelationshipWorker` (launches the dispatch hook in a fresh process). Moved, not rewritten: the evaluator stays procedural | in progress (logic moved, not class-based) | #82, this PR; authored relationships #67 |
| `Relationships` manager | one `managers/Relationships/` (`AuthoredRelationships`, `Native/` evaluator state and policy, `Legacy/RelationshipManager` and the `relationship_system` extension code); `lib/relationship_*`, `lib/synth_relationship*` and `ext/relationship_system/*` are thin hooks, so entry points, tests and stored data are unchanged | in progress (consolidated, not class-based) | this PR |
| `Supervisor`, `bin/worker.php`, `bin/worker-supervisor.php` | `workers/Supervisor/*`, `bin/worker.php`, `bin/worker-supervisor.php`; `service/start.sh` now starts the supervisor (a `speech` and a `general` lane, `WorkerRegistry::LANES`) as the default runtime; `service/manager.php` and the shell speech loop run only with `SYNTH_LEGACY_MANAGER=1` (or `--legacy-manager`), kept for one release | done (default runtime) | #82, this PR |
| TTS and profile workers | `workers/Speech/SpeechWorker` (`tts`, adapter over `service/tts_dispatch.php`, one bounded dispatch window per run, `service/tts_worker.php <job>` stays the per-job runner) and `workers/Profiles/ProfileAutofillWorker` (`profile`, on demand: `bin/worker.php profile --job=<file>` over `processor/profile_autofill_worker.php`); the manager's `dynamicprofile` and `player2health` tasks are registered as `workers/Profiles/DynamicProfileWorker` and `workers/Player2/Player2HealthWorker` | SYNTH-specific, registered | this PR |
| `bin/notify-server.php` (WebSocket to the plugin) | `bin/notify-server.php`, `managers/Notification/`; opt-in wake-up hint only (`notify.wake`), no message content; the client still fetches over HTTP and polls by default; not started by `service/manager.php` | done (opt-in) | this PR; client: SYNTH `codex/notify-client` |
| `bin/message-maintenance.php` | `bin/message-maintenance.php`: report-only by default, `--apply` removes `synth.responselog` rows past the retention; counts finished speech jobs and history events but never prunes them (no queue table; speech jobs are retired by the speech worker, history is the conversation record) | partial (retention over the response log only) | this PR |
| `bin/` maintenance scripts (`npc-biographies`, `item-descriptions`, `oghma-catalog`) | `tools/` (biography, voice catalog, Fallout content import) | SYNTH-specific | n/a |
| `lib/PlayerName`, `lib/PronunciationDictionary` (`Synth\Lib`) | `PlayerName` reads the runtime `PLAYER_NAME` (no callers yet); `PronunciationDictionary` holds the rule resolution and application, and `lib/tts_pronunciation.php` functions are thin hooks over it | parity (class); `PlayerName` not yet used | this PR |

## Config, extensions, UI, tests

| chevo item | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `config/config.inc.tmpl.php` | `config/config.inc.tmpl.php` (connection plus `game_id`, `allowed_hosts`) read by `ConfigFile`; `conf/conf.sample.php` stays as the compatibility shim | partial: template and reader done, the runtime bootstrap does not read it yet (bootstrap session) | this PR |
| `extensions/` (`extension.json`, hooks, `time-awareness` reference) | `ext/relationship_system` | parity (reference is `talk-awareness`; page `ui/extensions.php` in Config Hub) | this PR |
| Health route (`/api/v1/health`) | `api/v1/health.php` over `health.php`, 200 or 503 | parity (adapter) | this PR; TBD (health notice) |
| `ui/` (Slim/Twig over `/api/v1/edit`) | `ui/` PHP pages with raw SQL | missing (SYNTH keeps its own UI) | TBD |
| `tests/` + root `phpunit.xml` | `tests/` (suite `tests`) and `unittests/` (suite `unittests`) under one root `phpunit.xml`, `tests/bootstrap.php`, PHPUnit in root `require-dev`; the test `sql` adapter lives in `tests/Fixtures/phpunit.class.php`; CI runs both | parity (the `phpunit` TTS stub is `tests/Fixtures/tts-phpunit.php`) | this PR |
| PSR-4 autoload | added in this PR; `require_once` lists still used | in progress | this PR |
| Startup (`Bootstrap`, one composition root, Composer PSR-4, no `$GLOBALS`) | Slice 1 (#115, merged): `lib/autoload.php` is the one autoloader entry; `bootstrap.php`, `lib/runtime_bootstrap.php` (101 callers), `api/index.php`, `main.php` and every `bin/`/`tools/`/`service` CLI entry load it. Counts on `origin/unstable` (non-test PHP; `global $x` + `$GLOBALS` tokens, `require_once` tokens): before 6471 global uses in 294 files, 1703 `require_once`; after slice 1 6471 / 294 / 1713 (+10 autoload includes, no globals moved yet). Slice 2: `synthRuntimeBootstrap` and `synthRuntimeReadConfiguration` delegate to `Synth\Bootstrap::runtime()` / `::readConfiguration()` (one composition root; `Bootstrap::current()` holds the runtime instance; the dialogue legacy requires load lazily in `Bootstrap::load()`); counts after slice 2: 6473 global uses in 294 files, 1715 `require_once` (the config/DB/globals import moved, not removed). Slice 3: 150 `require_once` of pure PSR-4 class files removed across 91 files (listed by a scan of `composer.json` mappings: namespace and class match the path, no top-level functions or statements); counts on `unstable` 5c45226 before/after slice 3: 6473 global uses in 293 files both; `require_once` 1749 to 1643. Files that child processes load on their own (`lib/response.php`, `lib/synth_ingress.php`, `llm_connector.class.php`, `tts_status.php`, the `tts/tts-*.php` hooks) and `unittests/bootstrap.php` load `lib/autoload.php` first. Slice 4: `Bootstrap::db()`, `::enginePath()` and `::config()` accessors (legacy globals stay the source of truth while legacy files and tests assign them; the runtime instance is the fallback); `lib/autoload.php` also loads `Bootstrap`. Entry-layer reads of `$GLOBALS['db']`/`ENGINE_PATH` (service, top-level pages, rolemaster commands, `ui/*.php`) now use them; the logger is already the static `Synth\Lib\Logger` behind the `Logger` shim, so it needs no global. Counts on top of slice 3: 6473 to 6401 global uses, 293 to 287 files, `require_once` 1643 to 1644. Not touched: `lib/`, `managers/`, `ext/`, `ui/core`, drivers, `debug/db_updates.php` (owned by the schema, manager and relationship sessions) | in progress | #115 (slice 1), #116 (slice 2), #124 (slice 3), this PR (slice 4) |
| `lib/Logger` (`Chevo\Lib\Logger`: required classifier, level, caller line, `error_log`) | `Synth\Lib\Logger` in `lib/classes/Logger.php` (PSR-4 `Synth\Lib\`): classifier + level, caller `file:line`, secret redaction, the named `log/` file sinks with rotation, one error/exception/fatal handler installed once. `lib/logger.php` is a shim over it. Direct `error_log` calls are converted in files no other session owns; the rest are listed in the PR | parity (class and handler); call-site conversion continues | this PR |
| `lib/Logger` call sites (direct `error_log` -> `Synth\Lib\Logger::log`) | Remaining direct calls converted except files another session had open: `lib/data_functions.php`, `main_synth_pipeline.php`, `debug/util_memory_subsystem.php`, `player_rewrite.php`, `functions/functions.php`, `lib/core/llm_connector.class.php`, `core/dbmanager/dao/ApiBadgeDao.php`, `drivers/tts/Drivers/{Inworld,MeloTts,PiperTts}Driver.php`, `service/tts_*.php`, `tts_status.php`, `ui/xtts_clone.php`, `drivers/stt/SttWrapper.php` (unit-test fixtures copy it without `lib/`), and the test fixtures | parity (class, handler); about 180 calls (incl. commented-out ones) in the skipped files remain | this PR |
| Coding standards | [CODING-STANDARDS.md](CODING-STANDARDS.md) | parity | this PR |

## In-flight work map

| Item | Area | PR |
| --- | --- | --- |
| Schema redesign | core entities and dbmanager | #63 |
| Shared API keys | config | #62 |
| Scoped settings | ConfigManager | #65 |
| Runtime actors | NpcRegistration, ReferenceResolver | #66 |
| Authored relationships | Relationships | #67 |
| Two-step actions | ActionsManager | #68 |
| Optional voice | drivers/tts | TBD |
| Health notice | health route, ServerStatus | TBD |
| Conversation lifecycle | dialogue API | TBD |
| Voice samples | tts API | TBD |
| Locations | LocationManager | TBD |
| Reading | BookManager | TBD |
| Trade confirmation | ActionsManager | TBD |
| Orders and actions | ActionsManager | TBD |
| Talking objects | Narrator | TBD |
| Active effects | ContextManager | TBD |

Update the row in the same PR that changes an area. Keep this file to status; design lives in the
manager's `README.md` or `PLAN.md`.
