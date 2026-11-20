# Chevo parity status

Where Synthserver stands against **chevo** (the CHIM 4.0 server), as of the parity handoff on 2026-11-20.

- Reference: chevo `origin/unstable` `e1f32e4`. SYNTH counts are from `unstable` `2bc3752` with #149 merged.
- Layout and layering are in [SERVER-FILE-LAYOUT.md](SERVER-FILE-LAYOUT.md). Code rules are in [CODING-STANDARDS.md](CODING-STANDARDS.md).
- chevo is a reference for structure and design, not a source of code. SYNTH keeps its own protocol (`protocol/`, byte-identical with the SYNTH client), defaults and Fallout-specific features.

**Status values**

| Value | Meaning |
| --- | --- |
| `parity` | SYNTH has an equivalent in the chevo-shaped location. |
| `partial` | The shape is in place, but some logic is still procedural or legacy. The row says what is left. |
| `not started` | chevo has it, SYNTH has no equivalent, and nobody is working on it. |
| `deferred` | Left out on purpose for now (see [Deferred](#deferred)). |
| `SYNTH-specific` | No chevo counterpart. It stays product-specific. |

## Summary

The structural parity pass is finished: chevo's folder layout, entry points, workers, drivers and request boundary are in place, along with a schema rebuilt from scratch with `core_*` table names. The remaining work is a long tail of moving code into the new shape: about 2,200 raw SQL calls, about 3,400 global uses, about 1,840 `require_once` lines, and the entity families that have no DAO yet. Each of these can be done in small slices; nothing else is blocked on them. See [Remaining work](#remaining-work).

### Counts

Counts cover non-test PHP. They exclude `vendor/`, `unittests/vendor/`, `tests/` and `unittests/`. See [How the counts are measured](#how-the-counts-are-measured).

| Measure | SYNTH (after #149) | chevo `e1f32e4` | Note |
| --- | --- | --- | --- |
| Base entities (`core/entities/base`) | 54 | 57 | |
| System entities (`core/entities/system`) | 56 | 66 | |
| DAOs (`core/dbmanager/dao/*.php`) | 56 | 52 | Includes the `UsesEntityManager` and `ConnectorReferences` helpers. |
| `$db->` calls | 1,320 | — | Lines by directory: managers 733, workers 165, ui 96, root 70, drivers 64, processor 48, debug 35, lib 32, core 13, other 11 |
| `pg_*` calls | 870 | — | Mostly in `lib/postgresql.class.php`, `managers/*/Native`, `ui/` |
| Global uses (`global $x`, `$GLOBALS[`) | 2,926 | — | 6,471 before the globals pass. |
| `require_once` | 1,838 | — | The autoloader is in place, but most includes of procedural files remain. |
| `lib/*.php` | 189: 171 thin hooks and 18 real files | small final classes | See [lib/](#lib) |

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
| `managers/NpcRegistration` | Registration runs through `gamedata.php` (`actor_profile` record) and `NpcActorDao` / `NpcRuntimeActorDao`. The design is merged (#66). |
| `managers/ReferenceResolver` (deferred load-order references) | Designed in #66, not built. |
| `managers/WorldManager` | `managers/WorldKnowledgeManager` and observed equipment cover part of it. |
| Generic EntityEditor (create, read, update and delete over DB entities via `/api/v1/edit`) | `api/v1/editing` covers the `config` entity only: list, read and PATCH, with no create or delete. |
| Dialogue websocket listener | `bin/notify-server.php` sends opt-in wake-up hints only. Dialogue stays HTTP. |
| `managers/RumorManager` | A placeholder in chevo too. |

See also [Behaviour gaps against chevo](#behaviour-gaps-against-chevo) for chevo behaviours SYNTH lacks: message queue, LLM request log, extension hooks, GameRpc.

## Core and persistence

| chevo area | SYNTH today | Status |
| --- | --- | --- |
| `core/dbmanager` (Doctrine ORM, DAOs, migrations, as the only DB boundary) | Doctrine with the hash-pinned migration manifest and `SchemaCheck` (`schema-check --offline`). The DAOs follow `ConfigSettingDao` (see `core/dbmanager/dao/README.md`). There are still about 1,330 `$db->` and 870 `pg_*` calls outside it. | partial |
| `core/entities/base`, `core/entities/system` | 54 base and 56 system entities. Families with DAOs: memory batches and summaries, diary, middle-term, context, events and dialogue (history events, protocol events, rechat, dialogue, request execution, prompts, visual context, media objects), config settings, API badges, connectors (LLM, TTS, STT, ITT), profile, location, location state, location marker, worldspace, NPC, NPC actor, NPC runtime actor, NPC history, NPC profile rules, NPC biography templates, plugin manifest revisions, relationship config, LLM request log. | partial |
| `core_*` table naming and fresh-install migration | The schema was rebuilt from scratch in chevo style, with no upgrade migrations from the old tables. Every table has a `core_*` name (for example `core_npc`, `core_action_issued`, `core_log`). | parity |
| API keys | The shared Dwemer `api_keys` store (#62). It is kept on purpose, rather than a server-private table. | SYNTH-specific |
| `core/contracts` | `dialogue.php` and `narrator.php`, which are not PSR-4 yet. | partial |

## API (`api/index.php` -> `api/routes.php` -> `api/v1/*`)

Every chevo v1 route has a SYNTH adapter in `api/v1/`. Most adapters are thin and call into the existing root entry points (`main.php`, `gamedata.php`, `stt.php`, `itt.php`, `tts_status.php`). `api/v2/` is the internal native-dispatch layer behind `main.php`, not a public version.

| chevo area | SYNTH today | Status |
| --- | --- | --- |
| Router, versioned routes and request boundary | `.htaccess` allowlist read by `router.php`. `Synth\Lib\{LocalAccess,SameOriginRequest,RequestBody}` sit behind `lib/management_request_guard.php`. Requests without a length get 411 and oversized bodies get 413. See [REQUEST-BOUNDARY.md](REQUEST-BOUNDARY.md). | parity |
| `books/read`, `context/event`, `context/history`, `dialogue/{request,spoken,cancel,observe}`, `locations/save`, `messages`, `narrator/{checkpoint,transcribe}`, `npc/{add,view}`, `plugins/manifest`, `save/{created,loaded}`, `speech/transcribe`, `tts/{studio,voice-preview,voice-sample,voice-samples}`, `user/input`, `vision/describe`, `health`, `extensions` | Adapters in `api/v1/`. `context/history` and `npc/view` are SYNTH-shaped reads, through `SaveState/HistoryRead` and `CharacterManager/NpcViewRead`. | parity |
| `config` (`index`, `player-tts`) | `ui/global_settings.php` over `ConfigManager`. | partial |
| `editing` (generic edit API) | The `config` entity only. | partial (see [Not started](#not-started)) |
| `bgl/*` | — | deferred |

## Managers (`managers/<Name>/`, PSR-4 `Synth\Managers\<Name>\*`)

Code moved from `lib/` lives under `managers/<Name>/Native/`. It was moved, not rewritten: most of it is still procedural and uses the request-scoped `$db`. The old path keeps a thin hook.

| chevo manager | SYNTH today | Status |
| --- | --- | --- |
| ActionsManager | Folder with `TwoStepActions`, `TradeOffer`, `CompanionOrders` and `Native/` action code (combat, gifts, social, wait, script proxy). | partial (procedural) |
| BookManager | `ReadingEvent`, `ReadingHistory`. | partial (no book entity) |
| CharacterManager | `CharacterManager`, `NpcViewRead`, and `Native/` identity, biography and voice code. | parity (class); Native procedural |
| ConfigManager | `ConfigManager` over `ConfigSettingDao` (five scopes, `setEditable()`, `explain()`) and `ConfigFile`. | parity |
| ContextManager | `ContextManager`, `ConditionProjector`, `ActorConditionValidator`, and `Native/` context builders (history, nearby, location, world state, reactions). | partial (procedural) |
| ExtensionManager | Manifest validation, enable and disable, failure state in `conf/extensions.json`, hooks, isolation, auto-disable, and deferred events. There are no per-extension tables. | parity |
| GameRpc | The contract only, with no game-side channel. | partial |
| ListenerManager | `ListenerRouter` plus `Native/` ingress, request and runtime code. | partial |
| LocationManager | `LocationCatalog` over `LocationDao` / `LocationStateDao`. | partial |
| MemoryManager | `Native/` memory recall, ranking, vectors, diary, and middle-term. There is no `core_memory` DAO yet. | partial (procedural) |
| MessageHandler | `MessageStatusReader` (read side) and `Native/` response and protocol state. There is no queue table, so no create, cancel or expiry. | partial |
| Narrator | `PrivateNarratorManager`, `PrivateNarratorProtocol`, `NarratorObjectIdentity`. | parity |
| Notification | `NotifyServer`, `NotifyPublisher`, `NotifyConfig`, `NotifyHint` (opt-in). | parity |
| PromptManager | `PromptManager` plus `Native/` prompt composition and injections. | parity (class); Native procedural |
| SaveState | `SaveState`, `HistoryRead`, and `Native/` playthrough snapshot and rollback. | partial (no save storage) |
| ServerStatus | `HealthCheckManager` plus `Native/` backup, log trim, auditing. | parity |
| TtsStudio | `VoiceSampleStore`, `VoicePreviewManager`, and `Native/` TTS jobs, pronunciation, voice catalog and cloning. | parity (class); Native procedural |
| Relationships | `AuthoredRelationships`, `Native/` evaluator, `Legacy/` relationship system. | partial (procedural) |
| NpcRegistration, ReferenceResolver, WorldManager, RumorManager | — | not started |
| BglManager | — | deferred |
| OghmaManager | Skyrim knowledge. SYNTH has `WorldKnowledgeManager` (with `Fallout/` content packs). | SYNTH-specific |
| (no chevo equivalent) | `Conversation` (rechat, autochat), `QuestManager`, `VisualContext`, and the flat `SpeechManager`, `SessionManager`, `ReceiptManager`, `DialogueManager`. | SYNTH-specific |

## Drivers

| chevo area | SYNTH today | Status |
| --- | --- | --- |
| `drivers/llm` | `LlmDriverInterface`, `LlmWrapper`, and one driver per provider in `Drivers/` (OpenAI, OpenRouter, Google OpenAI, Groq, KoboldCpp, Player2). The six `connector/*json.php` files are 8-line hooks that keep the legacy class names. `connector/templates/` holds prompt templates. Every request is logged to `core_llm_request_log` (`Logging/`: recorder, redaction, size bounds, retention) and `bin/llm-request-log.php` replays one through the same driver. | parity |
| `drivers/tts` | `TtsDriverInterface`, `TtsWrapper`, and one driver per provider registered via `TtsProviderRegistry`, plus `SpeechAudio` and `SpeechStorage`. The provider bodies still read the legacy TTS globals. | parity (shape) |
| `drivers/stt`, `drivers/itt` | Wrapper plus one driver per provider. `stt/`, `itt/` are thin hooks. | parity |

## Workers and bin

| chevo item | SYNTH today | Status |
| --- | --- | --- |
| `WorkerInterface`, `WorkerRegistry`, `WorkerLimits`, `WorkerReport`, Supervisor, `bin/worker.php`, `bin/worker-supervisor.php` | `workers/Supervisor`. `service/start.sh` starts the supervisor with a `speech` lane and a `general` lane. The legacy manager runs only with `SYNTH_LEGACY_MANAGER=1`. | parity |
| Memory, MiddleTerm, Relationships, ExtensionEvents workers | `workers/{Memory,Relationships,ExtensionEvents}`. The logic was moved, not split into chevo's four memory workers. | partial |
| TTS, profile and Player2 workers | `workers/{Speech,Profiles,Player2}`. | SYNTH-specific |
| `bin/notify-server.php` | Opt-in wake-up hints only. | parity |
| `bin/message-maintenance.php` | Applies retention to the response log only. | partial |

## Config, extensions, tests, startup

| chevo item | SYNTH today | Status |
| --- | --- | --- |
| `config/config.inc.tmpl.php` | Read by `ConfigManager/ConfigFile`. `conf/conf.sample.php` stays as a compatibility shim. | parity |
| `extensions/` | `extensions/talk-awareness` is the reference extension, with its page in Config Hub. `ext/relationship_system` stays as a thin hook over `managers/Relationships`. | parity |
| `tests/` and root `phpunit.xml` | `tests/` and `unittests/` under one root `phpunit.xml`, run in CI. | parity |
| `lib/Logger` | `Synth\Lib\Logger` (classifier, level, caller, redaction, file sinks, a single error handler). | parity |
| Startup (one composition root, PSR-4) | `lib/autoload.php` is the single autoloader entry and `Synth\Bootstrap` is the composition root. The globals pass (#150–#155) moved config, DB and engine-path access behind `Bootstrap` accessors. | partial (globals and `require_once` remain) |

### lib/

`lib/` is down to 18 real files. The other 171 are thin hooks kept at their old paths for legacy callers. [LIB-INVENTORY.md](LIB-INVENTORY.md) records where each moved file went.

| File | Planned home |
| --- | --- |
| `LocalAccess`, `SameOriginRequest`, `RequestBody`, `PlayerName`, `PronunciationDictionary`, `classes/Logger` | Stay in `lib/` (small final classes, as in chevo). |
| `postgresql.class.php` | The legacy `$db` wrapper. It goes away when the raw SQL is gone. |
| `settings.php`, `core/settings_presets.php`, `db_connection_settings.php` | `ConfigManager`. |
| `runtime_bootstrap.php` | `Synth\Bootstrap`. |
| `management_request_guard.php` | Stays as the request-boundary entry. |
| `chat_helper_functions.php`, `data_functions.php`, `misc_ui_functions.php`, `utils.php`, `logger.php`, `synth_private_narrator.php` | Owning managers or `ui/`. `logger.php` and `synth_private_narrator.php` are already near-hooks. |

## Remaining work

The long tail after the handoff. Each item can be done in small slices, one draft PR each, with a full sandbox run on the exact head.

### Entities and DAOs (schema)

The schema needs a single writer, because the migration manifest is hash-pinned and the baseline statement count is fixed in a test. The generator and the hash tool are in [tools/schema/](../tools/schema/README.md); the design record is [SCHEMA-REDESIGN.md](SCHEMA-REDESIGN.md) (§13 records the owner decisions) and [SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md).

Families with no entity or DAO yet. Table names omit the `core_` prefix.

| Family | Tables |
| --- | --- |
| Playthrough and identity | `playthrough_profile`, `actor_identity`, `actor_activation`, `actor_event_batch`, `runtime_session`, `runtime_context`, `plugins`, `profiles`, `catalog_version` |
| Narrator | `narrator_definition` / `_revision`, `narrator_state`, `_context_state`, `_lane_head`, `_pending_output`, `_transcript_cursor`, `_transcript_entry`, `character_generation` / `_field` / `_revision` |
| Relationships | `relationship_actor_control` / `_event`, `_eval_queue`, `_init_queue`, `_evaluation` / `_source`, `_interaction_*`, `_response_input` / `_source`, `_result` / `_result_prior`, `_revision` / `_source`, `social_task_claim` / `_event` / `_input` |
| Actions and world | `action`, `action_custom`, `action_intent`, `action_result`, `faction`, `item_description` (plus `_custom`), `location_marker`, `worldknowledge_article` / `_audit` / `_catalog`, `tts_pronunciation`, `config_preset`, `worker_job` |

These tables have no primary key, so the generator skips them, and each needs a key or a composite-key entity first: `action_issued`, `audit_request`, `context_event`, `director_note`, `location_legacy`, `log`, `memory`, `memory_log`, `quest`, `response_log`, `speech`.

Schema steps still to do:

- Merge tables into chevo's `core_context_event`, `core_llm_request_log` and `core_message_queue`.
- Rename `core_npc.id` to `character_id`, then the remaining column renames.
- Replace the remaining views.
- SchemaMigrations step 10b: delete `lib/core/database_schema/*.sql`. Three of those files are applied at runtime, so inline them into the baseline first.

### Raw SQL

For the counts by directory, see [Counts](#counts). The #149 description lists the remaining call sites in detail.

1. **Tables that already have a DAO, but need DAO methods first** (about 40 call sites):
   - `ConfigSettingDao` prefix read and delete, for the voice-cache `LIKE` reads and deletes in the Cartesia and Inworld drivers and `ui/xtts_clone.php`.
   - The queue-key rename and delete in `dynamic_update_util`.
   - `ProfileDao` writes for the default-profile repair in `managers/ListenerManager/Native/synth_runtime.php`.
   - `LlmConnectorDao` insert and update for the LLM randomizer.
   - `FOR SHARE` and connection-scoped reads in `synth_diary_state`, `ui/api/audience_control.php` and the rechat joins. Keep the locks, parameterized, inside the DAO.
2. **Tables with no DAO** (about 600 lines, the bulk): context events, history events, runtime context and session, actor identities, `core_worker_job`, the memory, diary and middle-term families, protocol events, prompts, relationships, `core_location_legacy`. They wait on the entity families above.
3. **`processor/worldknowledge.php`:** one read of `current_worldknowledge_topic` stays as SQL, because `WorldKnowledgeVocabularyTest`'s fake `$db` scripts that query. To convert it, first change the test to stub `ConfigManager`, which needs a reset or inject hook on the singleton. The `synth_mode` readers also keep SQL (locked or on their own connection).

### Globals and require_once

The method and the converter are in [GLOBALS-PASS.md](GLOBALS-PASS.md). Its per-slice tables use the older `71d3eb8` baseline.

- **Remaining global uses** by directory: managers 672, drivers 611, root scripts 338, ui 305, prompts 0, connector 0, core 205, workers 182, processor 182, functions 167, service 113, lib 86.
- **What is left is mostly not mechanical.** The DB global is down to 4 declarations. The rest are mostly:
  - writes (`$GLOBALS['x'] = ...`),
  - `isset`, `empty` and `unset`,
  - by-reference uses,
  - prompt and template variables (`$alreadysent`, `$isEmbed`, `$responseTemplate`).
- **Accessor pattern:**
  - Reads go through `\Synth\Bootstrap::db()`, `enginePath()`, `engineRoot()`, `requestEvent()`, `claimedRequest()`, `gameRequest()`, or `config('NAME', $default)` for anything else. Each reads `$GLOBALS[...] ?? null` and nothing more.
  - Writers use `set('NAME', $v)`, `isset` uses `has('NAME')` and `unset` uses `forget('NAME')` (W-series in GLOBALS-PASS.md). Don't convert by-reference uses or `new $GLOBALS[...]` by script.
  - Never run the converter on `bootstrap.php`, because it recurses.
  - To retire a name: add a setter on `Bootstrap` (or a request-context object), move the writers to it, then switch the accessor to read the object.
- **`require_once` (1,838):** the rest load legacy function files, not classes. Plan:
  1. Wait until the globals writers have moved, so that load order doesn't change behaviour.
  2. Turn the function groups into autoloaded classes. Keep the old function names as thin wrappers until their callers move. The alternative is a Composer `files` autoload section for the function files that are always needed.
  3. Start with the most-required files: `main_synth_pipeline.php` (74), `ui/core/llm_connectors.php` (59), `ui/core/npc_master.php` (56), `ui/xtts_clone.php` (45), `managers/MessageHandler/Native/synth_protocol_state.php` (40).
  4. Work one directory per PR.

### Behaviour gaps against chevo

These came from comparing chevo's feature set with SYNTH. They are product work rather than restructuring, so each needs an owner decision.

| chevo behaviour | SYNTH today |
| --- | --- |
| Durable message queue (`core_message_queue`): a 202 reply, then a WebSocket `message_available` push, then a fetch that succeeds exactly once (a second fetch gets 409) | No queue table. Results come back through the existing response path; `GET /api/v1/messages/<id>` reads delivery state only; the client polls, with opt-in wake hints. |
| LLM request log with replay (`core_llm_request_log`) | Built: see [LLM-REQUEST-LOG.md](LLM-REQUEST-LOG.md). Differences from chevo: the replayable input is the driver's own input (messages and parameters), not an `LLMRequest`; token counts are only filled when the provider body is read whole (non-streamed requests). |
| 14 extension hook points, plus extension routes (`/api/v1/ext/<id>/`), workers and `ext_<id>_*` tables | 4 hook points (2 filters, 2 events). No extension routes, workers or tables. |
| GameRpc channel (`rpc.request` / `rpc.response` over the socket) | The contract only. |
| A separate Decisions connector for two-step actions | Two-step actions (#68) use the existing profile connectors. |
| Extension distribution (phase 6) | Not built (also pending in chevo). |

### Risks

- **Shared API keys:** DwemerDistro doesn't register SYNTH as a key consumer yet, so CHIM could delete a key SYNTH uses (#62).
- **Not checked in a real install:** nothing in the parity pass has been checked with real Apache, a browser or in-game. The highest-risk changes are #112 (front-controller allowlist) and #110 (supervisor as the default runtime).

## How the counts are measured

Run from a SynthServer checkout:

```sh
ex='^(vendor|unittests/vendor|tests|unittests)/'
f(){ git ls-files '*.php' | grep -Ev "$ex"; }
f | xargs grep -o '\$db->' | wc -l                        # $db-> calls
f | xargs grep -oE '\bpg_[a-z_]+\(' | wc -l               # pg_* calls
f | xargs grep -oE '(\bglobal \$|\$GLOBALS\[)' | wc -l    # global uses
f | xargs grep -oE '\brequire_once\b' | wc -l             # require_once
```

For chevo, use `git ls-tree -r --name-only origin/unstable core/entities/base` (and the same for `system` and `core/dbmanager/dao`). Read chevo only through git; don't copy code from it.

Update the matching row in the same PR that changes an area. Keep this file to status; design belongs in the manager's `README.md` or `PLAN.md`.
