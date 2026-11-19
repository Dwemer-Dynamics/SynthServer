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
| `dialogue` (`request`, `spoken`, `cancel`) | `api/v1/dialogue/*` adapters over `main.php` (`api/v2` conversation dispatch behind it) | parity (adapter); lifecycle work continues | this PR; TBD (conversation lifecycle) |
| `editing` (generic edit API) | `/api/v1/edit` answers 501 (`api/v1/editing/index.php`) | missing (501 stub) | TBD (edit API PR) |
| `extensions` | `api/v1/extensions.php`: `GET /extensions`, `POST /extensions/{id}/{enable,disable}`; id checked against the registry allowlist, same-origin guard, generic errors; `ext/relationship_system` kept | parity (list, enable, disable) | this PR |
| `health` | `api/v1/health.php` over root `health.php` (read-only readiness, 503 contract; `health/{live,db,api-keys-configured,npcs-available,llm}`) | parity (adapter) | this PR; TBD (health notice) |
| `locations/save` | `api/v1/locations/save.php` (`location_catalog` event via `main.php`; no storage yet) | parity (adapter) | this PR; TBD (locations) |
| `messages` (response queue delivery) | `GET /api/v1/messages/<id>` over `tts_status.php` (id is the TTS cache key); `processor/`, `ui/response_queue.php` | parity (adapter, delivery state only) | this PR |
| `narrator/checkpoint` | `api/v1/narrator/checkpoint.php` (`narrator_command`, `narrator_receipt` via `main.php` to `api/v2` narrator route, `PrivateNarratorManager`) | parity (adapter) | this PR |
| `npc/add` | `api/v1/npc/add.php` (`actor_profile` record via `gamedata.php`); runtime actor design | parity (adapter); runtime actors in progress | this PR; #66 |
| `plugins/manifest` | `api/v1/plugins/manifest.php` (`loaded_plugins` record via `gamedata.php`); `ui/server_plugins.php`, `data/plugin_packages/` | parity (adapter) | this PR |
| `save/created`, `save/loaded` | `save/loaded`: `init` event via `main.php`; `save/created`: `save_created` event (capability `session.save_created`, sent by the flat client on F4SE `kPostSaveGame`) via `main.php` to `api/v2`, recorded through `SessionManager::saveCreated` and `SaveState::created` (logged; no storage until the save schema lands) | parity (adapter) | this PR; TBD (save storage) |
| `speech/transcribe` | `api/v1/speech/transcribe.php` over `stt.php`; `stt/` | parity (adapter) | this PR |
| `tts` (`studio`, `voice-preview`, `voice-sample`, `voice-samples`) | `api/v1/tts/*.php`: `studio` over `ui/api/player_tts_preview.php` (POST, management guard and dashboard token added), `voice-preview` and `voice-samples` over `api/v1/voice/{preview,samples}.php`, `voice-sample` over `voice-samples` with one voice required | parity (adapter) | this PR |
| `user/input` | `api/v1/user/input.php` (`input_text`, `input_audio` via `main.php`); `player_rewrite.php` is a CLI worker, not an entry point | parity (adapter) | this PR |
| `vision/describe` | `api/v1/vision/describe.php` over `itt.php`; `itt/` | parity (adapter) | this PR |

## Managers (`managers/<Name>/`)

SYNTH managers are flat files today (`managers/*.php`); a domain becomes a folder when it moves.

| chevo manager | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| ActionsManager (actions, companion orders, confirmed trading) | `functions/`, `lib/synth_*_actions.php` | in progress | TBD (orders/actions, trade confirmation); #68 two-step actions |
| BookManager | none | in progress | TBD (reading) |
| CharacterManager | `managers/CharacterManager.php` | in progress | TBD (domain move) |
| ConfigManager (scoped settings) | `lib/`, `ui/global_settings.php` | in progress | #65 |
| ContextManager | `managers/ContextManager.php` | in progress | TBD (domain move; active effects) |
| ExtensionManager | none (`ext/relationship_system` legacy) | parity for the config-file model (manifest validation, enable/disable and failure state in `conf/extensions.json`, 2 filters + 2 events, error isolation, auto-disable after 5 consecutive failures, optional deferred events, API and UI); no per-extension tables or handler time limits | this PR |
| GameRpc | none | missing | TBD |
| ListenerManager | none | missing | TBD |
| LocationManager | `lib/` location ancestry | in progress | TBD (locations) |
| Notification (wake-up hints) | `managers/Notification/` (`NotifyServer`, `NotifyPublisher`, `NotifyConfig`, `NotifyHint`) | done (opt-in) | this PR |
| MemoryManager | `service/processors/native_memory` | in progress | TBD |
| MessageHandler (durable outbound queue) | `processor/`, response queue UI | missing as a manager | TBD |
| Narrator | `managers/PrivateNarratorManager.php`, `core/contracts/narrator.php` | in progress | TBD (talking objects) |
| NpcRegistration | `gamedata.php` | in progress | #66 |
| OghmaManager | n/a (Skyrim knowledge); SYNTH has `WorldKnowledgeManager` | SYNTH-specific | n/a |
| PromptManager | `managers/PromptManager.php`, `prompts/` | in progress | TBD (domain move) |
| ReferenceResolver | none | in progress | #66 |
| RumorManager | none (placeholder in chevo) | missing | n/a |
| SaveState | `managers/SaveState.php` | in progress | TBD (domain move) |
| ServerStatus | `health.php`, `ui/system_health.php` | in progress | TBD (health notice) |
| TtsStudio | `ui/` voice pages | in progress | TBD |
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
| `MemoryContext`, `MemorySources`, `MemoryEmbedding`, `MiddleTerm` workers | `service/memory_worker.php`, `service/processors/{native_memory,middleterm}`; registered via `workers/Memory/MemoryWorker` (thin adapter over `service/memory_dispatch.php`) and `workers/Memory/MiddleTermWorker` (adapter over `service/task_dispatch.php middleterm`); the manager's `native_memory` entry point is a thin hook over the registry | in progress | #82, this PR (adapters only) |
| `Relationships` worker | `service/relationship_worker.php`, `service/processors/native_relationship`; registered via `workers/Relationships/RelationshipWorker` (thin adapter over `service/relationship_dispatch.php`); the manager's `native_relationship` entry point is a thin hook over the registry | in progress | #82, this PR (adapter only); authored relationships #67 |
| `Supervisor`, `bin/worker.php`, `bin/worker-supervisor.php` | `workers/Supervisor/*`, `bin/worker.php`, `bin/worker-supervisor.php`; `service/start.sh` now starts the supervisor (a `speech` and a `general` lane, `WorkerRegistry::LANES`) as the default runtime; `service/manager.php` and the shell speech loop run only with `SYNTH_LEGACY_MANAGER=1` (or `--legacy-manager`), kept for one release | done (default runtime) | #82, this PR |
| TTS and profile workers | `workers/Speech/SpeechWorker` (`tts`, adapter over `service/tts_dispatch.php`, one bounded dispatch window per run, `service/tts_worker.php <job>` stays the per-job runner) and `workers/Profiles/ProfileAutofillWorker` (`profile`, on demand: `bin/worker.php profile --job=<file>` over `processor/profile_autofill_worker.php`); the manager's `dynamicprofile` and `player2health` tasks are registered as `workers/Profiles/DynamicProfileWorker` and `workers/Player2/Player2HealthWorker` | SYNTH-specific, registered | this PR |
| `bin/notify-server.php` (WebSocket to the plugin) | `bin/notify-server.php`, `managers/Notification/`; opt-in wake-up hint only (`notify.wake`), no message content; the client still fetches over HTTP and polls by default; not started by `service/manager.php` | done (opt-in) | this PR; client: SYNTH `codex/notify-client` |
| `bin/` maintenance scripts (`message-maintenance`, `npc-biographies`, `item-descriptions`, `oghma-catalog`) | `tools/` (biography, voice catalog, Fallout content import) | SYNTH-specific | n/a |

## Config, extensions, UI, tests

| chevo item | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `config/config.inc.tmpl.php` | `conf/` (`conf_loader.php`, `conf_schema.json`) | in progress (moves last) | TBD |
| `extensions/` (`extension.json`, hooks, `time-awareness` reference) | `ext/relationship_system` | parity (reference is `talk-awareness`; page `ui/extensions.php` in Config Hub) | this PR |
| Health route (`/api/v1/health`) | `api/v1/health.php` over `health.php`, 200 or 503 | parity (adapter) | this PR; TBD (health notice) |
| `ui/` (Slim/Twig over `/api/v1/edit`) | `ui/` PHP pages with raw SQL | missing (SYNTH keeps its own UI) | TBD |
| `tests/` + root `phpunit.xml` | `tests/` (suite `tests`) and `unittests/` (suite `unittests`) under one root `phpunit.xml`, `tests/bootstrap.php`, PHPUnit in root `require-dev`; the test `sql` adapter lives in `tests/Fixtures/phpunit.class.php`; CI runs both | parity (follow-up: `tts/tts-phpunit.php` moves with the TTS PR) | this PR |
| PSR-4 autoload | added in this PR; `require_once` lists still used | in progress | this PR |
| `lib/Logger` (`Chevo\Lib\Logger`: required classifier, level, caller line, `error_log`) | `Synth\Lib\Logger` in `lib/classes/Logger.php` (PSR-4 `Synth\Lib\`): classifier + level, caller `file:line`, secret redaction, the named `log/` file sinks with rotation, one error/exception/fatal handler installed once. `lib/logger.php` is a shim over it. Direct `error_log` calls are converted in files no other session owns; the rest are listed in the PR | parity (class and handler); call-site conversion continues | this PR |
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
