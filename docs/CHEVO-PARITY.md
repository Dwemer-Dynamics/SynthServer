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
| Router and versioned routes | `api/v2/dispatch.php`, `routes.php`; root entry points (`main.php`, `gamedata.php`) | in progress | TBD |
| `books/read` | none | in progress (reading session) | TBD |
| `config` (`index`, `player-tts`) | `ui/global_settings.php`, `conf/` | in progress | #65 |
| `context/event` | `gamedata.php` / `processor/` | missing in `api/` | TBD |
| `dialogue` (`request`, `spoken`, `cancel`) | `main.php`, `processor/request.php`, `api/v2` conversation | in progress | TBD (conversation lifecycle) |
| `editing` (generic edit API) | none | missing | TBD |
| `extensions` | none | in progress (draft PR: `talk-awareness` reference; `ext/relationship_system` kept) | TBD |
| `health` | root `health.php` (read-only readiness, 503 contract) | in progress | TBD (health notice) |
| `locations/save` | none | in progress (locations session) | TBD |
| `messages` (response queue delivery) | `processor/`, `ui/response_queue.php` | in progress | TBD |
| `narrator/checkpoint` | `api/v2` narrator routes, `PrivateNarratorManager` | in progress | TBD |
| `npc/add` | `gamedata.php`, runtime actor design | in progress | #66 |
| `plugins/manifest` | `ui/server_plugins.php`, `data/plugin_packages/` | in progress | TBD |
| `save/created`, `save/loaded` | `SaveState`, `SessionManager` | in progress | TBD |
| `speech/transcribe` | `stt.php`, `stt/` | in progress | TBD |
| `tts` (`studio`, `voice-preview`, `voice-sample`, `voice-samples`) | `ui/api`, `tts/`, voice catalog tools | in progress | TBD (voice samples, optional voice) |
| `user/input` | `main.php`, `player_rewrite.php` | in progress | TBD |
| `vision/describe` | `itt.php`, `itt/` | in progress | TBD |

## Managers (`managers/<Name>/`)

SYNTH managers are flat files today (`managers/*.php`); a domain becomes a folder when it moves.

| chevo manager | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| ActionsManager (actions, companion orders, confirmed trading) | `functions/`, `lib/synth_*_actions.php` | in progress | TBD (orders/actions, trade confirmation); #68 two-step actions |
| BookManager | none | in progress | TBD (reading) |
| CharacterManager | `managers/CharacterManager.php` | in progress | TBD (domain move) |
| ConfigManager (scoped settings) | `lib/`, `ui/global_settings.php` | in progress | #65 |
| ContextManager | `managers/ContextManager.php` | in progress | TBD (domain move; active effects) |
| ExtensionManager | none (`ext/relationship_system` legacy) | in progress (draft PR: manifest validation, enable/disable in `conf/extensions.json`, 2 filters + 2 events, error isolation; no tables, auto-disable or UI yet) | TBD |
| GameRpc | none | missing | TBD |
| ListenerManager | none | missing | TBD |
| LocationManager | `lib/` location ancestry | in progress | TBD (locations) |
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
| `drivers/llm` (Interface, `LLMWrapper`, `Drivers/`) | `connector/`, `drivers/llm/OpenAiCompatibleDriver.php`, `PlayerRewriteDriver.php` | in progress | TBD |
| `drivers/tts` (Interface, `TTSWrapper`, `Drivers/`, audio, storage) | `tts/`, `drivers/tts/*SpeechDriver.php` | in progress | TBD (optional voice) |
| `drivers/stt` | `stt/` | missing in `drivers/` | TBD |
| `drivers/itt` | `itt/` | missing in `drivers/` | TBD |

## Workers, bin and notification

| chevo item | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `WorkerInterface`, `WorkerRegistry`, `WorkerLimits`, `WorkerReport` | `workers/*.php` (storage-agnostic) | done | #82 |
| `ExtensionEvents` worker | none | missing (seam: `DeferredEventSink` in ExtensionManager) | TBD |
| `MemoryContext`, `MemorySources`, `MemoryEmbedding`, `MiddleTerm` workers | `service/memory_worker.php`, `service/processors/{native_memory,middleterm}`; registered via `workers/Memory/MemoryWorker` (thin adapter over `service/memory_dispatch.php`) | in progress | #82 (adapter only) |
| `Relationships` worker | `service/relationship_worker.php`, `service/processors/native_relationship`; registered via `workers/Relationships/RelationshipWorker` (thin adapter over `service/relationship_dispatch.php`) | in progress | #82 (adapter only); authored relationships #67 |
| `Supervisor`, `bin/worker.php`, `bin/worker-supervisor.php` | `workers/Supervisor/*`, `bin/worker.php`, `bin/worker-supervisor.php`; `service/manager.php` and `service/start.sh` unchanged and still the default runtime | done (opt-in) | #82 |
| TTS and profile workers | `service/tts_worker.php`, `processor/*_worker.php` | SYNTH-specific | n/a |
| `bin/notify-server.php` (WebSocket to the plugin) | none; the client polls | missing | TBD |
| `bin/` maintenance scripts (`message-maintenance`, `npc-biographies`, `item-descriptions`, `oghma-catalog`) | `tools/` (biography, voice catalog, Fallout content import) | SYNTH-specific | n/a |

## Config, extensions, UI, tests

| chevo item | SYNTH today | Status | Owner PR |
| --- | --- | --- | --- |
| `config/config.inc.tmpl.php` | `conf/` (`conf_loader.php`, `conf_schema.json`) | in progress (moves last) | TBD |
| `extensions/` (`extension.json`, hooks, `time-awareness` reference) | `ext/relationship_system` | in progress (draft PR; reference is `talk-awareness`) | TBD |
| Health route (`/api/v1/health`) | `health.php` returns 200 or 503 | in progress | TBD (health notice) |
| `ui/` (Slim/Twig over `/api/v1/edit`) | `ui/` PHP pages with raw SQL | missing (SYNTH keeps its own UI) | TBD |
| `tests/` | `unittests/` with its own vendor | in progress: `tests/` is the target, `unittests/` stays for now | this PR |
| PSR-4 autoload | added in this PR; `require_once` lists still used | in progress | this PR |
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
