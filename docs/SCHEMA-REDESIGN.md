# Schema redesign (from scratch, CHIM 4 layout)

**Status: Phase 1, design only. Nothing here is implemented.** No migration, table, store or caller changes
with this document. It supersedes conventions D1 and D5 of [SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md#schema-conventions)
once approved (D2 is replaced by [naming](#2-naming-and-layout), D6 by [snapshots](#8-snapshots)).

The decision behind it, quoted from the owner: "we can reorganize and rework tables from scratch it does not
matter. we dont need to care about migrations". SYNTH is pre-alpha, so there is **no upgrade path** from
existing installs: the new schema is one new Doctrine baseline that replaces `Version20261115000000`, and an
install made from the old baseline is recreated, not migrated.

Inputs read, all read-only:

- SYNTH `origin/unstable` `0fa6e84` (121 relations: 115 tables and 6 views; 53 in `synth`, 68 in `synth_meta`).
- ChimServer `origin/unstable` `804e501`: 36 migrations, 46 `chim_*` tables, `runtime.json` format 2,
  `bin/schema-check.php`, `tools/schema-candidate.php`, `DBManager` routing to `Runtime/*Store`, shared `api_keys`.
- PR #62 branch `codex/shared-api-keys`, **head `648d1a2`** at the time of writing (see [section 6](#6-connectors-and-shared-keys)).

How the inventory was measured: `git grep` on `origin/unstable` over tracked files, excluding
`vendor/`, `unittests/`, `core/dbmanager/migrations/` and the frozen `debug/db_updates.php`. A "caller file" is a
file that names the table in SQL position (`FROM`, `JOIN`, `INTO`, `UPDATE`, `synth.x`, `synth_meta.x`) or as a quoted
table argument (`$db->insert('x', ...)`). `lib/runtime_bootstrap.php` (the readiness gate) lists most tables; it is
counted separately and not as a caller. Counts are files, not call sites, and a few generic names (`log`, `memory`,
`speech`, `prompts`, `factions`) are noisy, so they are given as approximations (`~`). They size work; they are not proofs.

---

## 1. Summary of decisions

| # | Decision | Why |
|---|---|---|
| R1 | One new baseline `Version20261117000000` replaces `Version20261115000000`; its `.php`, `.structure.txt` and `.fresh.sql` are regenerated from a disposable PostgreSQL 17. No `debug/db_updates.php` chain, no `data/database_default.sql` import. | Owner decision: no migrations. |
| R2 | Every table is `synth_<domain>_<noun>` in schema `public`; `synth` and `synth_meta` schemas, `plugins` schema and the `synth.`/`synth_meta.` qualifiers disappear. | Mirrors `chim_*`; one search path; manifest-friendly ([section 2](#2-naming-and-layout)). |
| R3 | Domain stores replace the `$db->` string API and the six repositories: one DBAL store per domain behind a `SynthDBManager` facade ([section 5](#5-domain-stores)). | ChimServer `DBManager` + `Runtime/*Store`. |
| R4 | Format-2 manifest with per-migration hashes and one manifest per reviewed PostgreSQL major (17 first) ([section 7](#7-manifest-and-drift-check)). | ChimServer `runtime.json`. |
| R5 | API keys are not in SYNTH's schema at all; connectors hold a shared-key reference ([section 6](#6-connectors-and-shared-keys)). | PR #62. |
| R6 | 5 tables are dropped outright, 10 more are merged away and 5 views are replaced ([section 4](#4-drop-list), [3.11](#311-totals)). | Dead, duplicate or replaced. |
| R7 | Snapshots stay **paused**; their tables and version metadata are designed, not wired ([section 8](#8-snapshots)). | Hard hold. |
| R8 | Phase 2 proceeds domain by domain; the baseline is regenerated at every step so the app works at each commit ([section 9](#9-rollout-order)). | App must stay runnable. |

---

## 2. Naming and layout

**Choice: `public.synth_<domain>_<noun>`.** Rejected: old D2 `synth_meta.<domain>_<noun>`.

- ChimServer puts every table in `public` with a `chim_` prefix. The prefix, not a schema, marks ownership, which is
  what its manifest relies on ("only explicitly listed public tables ... are managed; other relations are ignored").
  SYNTH shares a PostgreSQL server (and, via #62, the `dwemer` database neighbours) with other Dwemer products, so
  a product prefix is the safer boundary than a schema the other products do not use.
- D2 made sense only to extend a baseline that already lived in two schemas. With a from-scratch baseline a single
  rule is simpler: the table name alone says product and domain, and the 53/68 split (which was historical, not
  semantic: `core_*` legacy tables beside native protocol tables) is removed.
- Domain names in the table name match the store that owns it (`synth_memory_*` belongs to `MemoryStore`), so a
  `git grep synth_memory_` finds exactly one owner.
- One schema removes the `search_path` dependence of today's `$db->insert('eventlog', ...)` unqualified names, which
  is also why the callers are hard to audit.

Other layout rules:

- Extensions `vector` and `pg_trgm` live in `public`. Ledger table: `doctrine_migration_versions` in `public`
  (as ChimServer), outside the application tables so it cannot be copied by any row-scoped snapshot.
- **Types.** Surrogate keys `bigint GENERATED ALWAYS AS IDENTITY` (not `integer`/`serial`, not `rowid`). All
  timestamps `timestamptz` (today ~20 columns are `timestamp without time zone`). Hashes `char(64)` with a
  `CHECK (x ~ '^[0-9a-f]{64}$')`. Integer `0/1` flags become `boolean`. Free enumerations get `CHECK (... IN (...))`.
- **Foreign keys.** Durable state tables carry real FKs (they already do in `synth_meta`). Append-only per-turn logs
  carry `playthrough_id` and `session_id` without FKs so pruning and rollback by `gamets` stay cheap. `playthrough_id`
  is a FK to `synth_playthroughs` on durable tables only.
- **JSON vs columns.** Keep `jsonb` for payloads that are stored and returned whole (`input_payload`, `result_payload`,
  `event_payload`, `capabilities`) and add `CHECK (jsonb_typeof(x)='object')`. Promote to columns anything a query
  filters on (`worker_jobs` FKs, `eventlog.type`). Demote sparse tuning knobs to one `params jsonb` (LLM sampling
  columns, as `chim_provider_connections.params`).
- **Functions.** `synth.convert_gamets2*` (6 functions, used by 9 files) are kept as `public.synth_gamets_to_*`;
  `synth_meta.json_object_has_unique_keys` becomes `public.synth_json_object_has_unique_keys` (used by
  `synth_protocol.sql` CHECKs and `lib/synth_wait_actions.php`). The four schema-clone functions are dropped
  ([section 8](#8-snapshots)).

---

## 3. Inventory and mapping

Volume classes: **cfg** config or catalog (rarely written), **npc** per-NPC rows, **log** per-turn or append-only log,
**cache** derived/rebuildable. Status: **live** (read and written by current code), **legacy-live** (HerikaServer-era
table still used by the running pipeline), **dead** ([section 4](#4-drop-list)), **snap** (snapshot-related).
"Files" is `prod/tests`. All tables below get new names; "same" shapes keep their columns except where stated.

### 3.1 Runtime and protocol (`synth_runtime_*`, `synth_protocol_*`, `synth_actor_*`, `synth_dialogue_*`, `synth_worker_*`)

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth_meta.runtime_sessions` | `synth_runtime_sessions` | One row per game connection: generation, active turn, capabilities, per-worker dispatch deadlines. | 41/13 | cfg-ish | live |
| `synth_meta.request_executions` | `synth_runtime_requests` | Idempotent request claim and stored response (`response_body bytea`). | 45/15 | log | live |
| `synth_meta.protocol_events` | `synth_protocol_events` | Canonical wire events with hash, for replay and audit. | 41/9 | log | live |
| `synth_meta.context_snapshots` | `synth_runtime_contexts` | Per-sequence context payload and audience; links to history event. Name follows `chim_runtime_contexts`. | 43/13 | log | live |
| `synth_meta.active_agents` | `synth_actor_activation` | Which actors are active in a session (`chim_actor_activation`). | 4/2 | per-NPC | live |
| `synth_meta.actor_event_batches` | `synth_actor_event_batches` | Batched actor observations. | 1/0 | log | live |
| `synth_meta.dialogue_utterances` | `synth_dialogue_utterances` | Spoken lines with receipt state. | 4/2 | log | live |
| `synth_meta.dialogue_origin_policies` | `synth_dialogue_origin_policies` | Per-request origin and execution policy (`policy jsonb`). | 7/4 | log | live |
| `synth_meta.response_routes` | `synth_dialogue_routes` | Speaker/listener routing of each line. | 9/5 | log | live |
| `synth_meta.worker_jobs` | `synth_worker_jobs` | Lease-based background jobs (diary, memory, middleterm, relationship). Five nullable FK columns kept, plus `CHECK (num_nonnulls(...) <= 1)` and a partial index per job type. | 23/5 | log | live |
| `synth_meta.history_events` | `synth_history_events` | Ordered event ledger every native subsystem points at (`event_payload`, `eventlog_rowid`). | 31/8 | log | live |
| `synth.synthnpcs` (view) | dropped | Compatibility view over the NPC table, created lazily by `synth_ensure_synthnpcs_view()`. | 2/1 | | dead after R6 |

### 3.2 Characters (`synth_characters`, `synth_character_*`)

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth.actor_identities` | `synth_characters` | Actor identity (game, plugin, full form id, playthrough, name). Unique `(playthrough_id, game, origin_plugin, full_form_id)`. | 38/15 | npc | live |
| `synth.core_npc_master` | `synth_character_profiles` | NPC profile text and flags. 29 columns: `int` flags become `boolean` (`npc_favorite`, `lock_profile`, `dynamic_profile`), `md5`/`base`/`emote_moods` kept, `extended_data jsonb` stays, FK `character_id` replaces `actor_identity_id` nullable. | 38/16 | npc | live |
| `synth.core_npc_master_history` | `synth_character_profile_revisions` | Profile revisions (31 columns, a full copy of the master row). Redesigned as `(character_id, revision, profile jsonb, reason, created_at)` plus the relationship fields the readers query. | 8/1 | npc | live |
| `synth_meta.profile_revisions` | `synth_character_generation_revisions` | AI profile generation results per history event. | 1/1 | npc | live |
| `synth_meta.profile_job_inputs`, `profile_job_fields` | `synth_character_generation_jobs`, `synth_character_generation_fields` | Inputs and per-field results of profile jobs. | 1/0 each | npc | live |
| `synth.import_rules` | `synth_character_import_rules` | Rules mapping imported actors to profiles. | 4/0 | cfg | live |
| `synth.bio_template_actor_map` | `synth_character_bio_actor_map` | Authored biography template per actor. | 5/6 | cfg | live |
| `synth.core_player` | row-group of `synth_settings_global` (`scope='player'`) | Key/value player definition (`id text, value text`). | 14/1 | cfg | live |
| `synth.core_narrator` | row-group of `synth_settings_global` (`scope='narrator'`) | Key/value narrator definition. | 7/1 | cfg | live |

### 3.3 Settings, profiles and presets (`synth_settings_*`)

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth.general_settings` | `synth_settings_global` (`scope='general'`) | Managed typed settings (54 ids). `updated_at` becomes `timestamptz`. | 5/3 | cfg | live (ORM pilot) |
| `synth.conf_opts` | `synth_settings_global` (`scope='runtime'`) | Free runtime flags (`COMBAT_BARK_LAST_TIMESTAMP` ...). | ~34/3 | cfg | live |
| `synth_meta.settings` | `synth_settings_global` (`scope='system'`) | Backup and log-trim keys. | 4/0 | cfg | live |
| `synth.core_profiles` | `synth_settings_profiles` + `synth_settings_profile_connections` | Profile shell; the six `llm_*_id` columns and `diary_connector_id`/`tts_connector_id` become `(profile_id, role, connection_id)` rows with `role` checked. | 28/8 | cfg | live |
| `synth.global_settings_presets` | `synth_settings_presets` | Named settings snapshots (`snapshot jsonb`). | 2/0 | cfg | live |
| `synth.prompts` | `synth_settings_prompts` | Default and custom prompt text by key. | ~26/3 | cfg | live |

`synth_settings_global` is one table `(scope, key, value text, updated_at)` with `PRIMARY KEY (scope, key)` and a
`CHECK` on scope. Five key/value tables have the same shape and are always read by key; collapsing them removes four
tables and one store without losing a distinguishing constraint. `scope='general'` keys still have their
managed-id allowlist enforced in the store, as `SettingsRepository` does today.

### 3.4 Provider connectors (`synth_provider_*`; see [section 6](#6-connectors-and-shared-keys))

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth.core_llm_connector` | `synth_provider_connections` `(kind='llm')` | LLM endpoint + sampling. 13 sampler/format columns move to `params jsonb`; `*_i4` flags become booleans inside `params`. | 19/5 | cfg | live |
| `synth.core_tts_connector` | `synth_provider_connections` `(kind='tts')` | TTS endpoint, `voice_field`. | 6/1 | cfg | live |
| `synth.core_stt_connector` | `synth_provider_connections` `(kind='stt')` | STT endpoint. | 3/1 | cfg | live |
| `synth.core_itt_connector` | `synth_provider_connections` `(kind='itt')` | Image-to-text endpoint. | 3/0 | cfg | live |
| `synth.core_api_badge` | **none** | Local key table. Removed by #62's rework: keys live only in the shared store. | 4/0 | cfg | replaced |
| `synth.core_tts_pronunciation` | `synth_tts_pronunciations` | Spoken-form overrides. | 2/1 | cfg | live |

### 3.5 Catalogs and authored content (`synth_catalog_*`, `synth_worldknowledge_*`)

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth.core_action`, `core_action_custom`, view `combined_core_action` | `synth_catalog_actions` + view `synth_catalog_actions_effective` | Action definitions. Two tables + a UNION view become one table with `origin IN ('stock','custom')`, PK `(code_name, origin)`; the view keeps custom-over-stock precedence. | 4+3+4 | cfg | live |
| `synth.descriptions`, `descriptions_custom`, view `combined_descriptions` | `synth_catalog_descriptions` + `_effective` view | Same collapse. | 4+2+3 | cfg | live |
| `synth.bio_templates`, `bio_templates_custom`, view `combined_bio_templates` | `synth_catalog_bio_templates` + `_effective` view | Same collapse. | 6+3+6 | cfg | live |
| `synth.worldknowledge`, view `worldknowledge_effective` | `synth_worldknowledge_entries` + `_effective` view | Articles with `vector384`, `native_vector`. | ~20/8 | cfg | live |
| `synth.worldknowledge_catalogs` | `synth_worldknowledge_catalogs` | Installed catalog versions and checksums. | 6/3 | cfg | live |
| `synth.worldknowledge_audit` | `synth_worldknowledge_audits` | Retrieval audit (23 columns). | 3/2 | log | live |
| `synth.factions` | `synth_catalog_factions` | Faction ranks and vendor data. | ~4/5 | cfg | live |
| `synth.locations` | `synth_catalog_locations` | Locations with `coords point`. | ~7/1 | cfg | live |
| `synth.quests` | `synth_catalog_quests` | Quest records (`ts text` becomes `timestamptz`; `rowid` identity). Pruned by `gamets`. | ~6/4 | per-save | live |
| `synth.game_plugins` | `synth_catalog_game_plugins` | Plugin load-order and form-id prefixes. | 3/1 | cfg | live |
| `synth.database_versioning` | `synth_catalog_versions` | Installed content versions per catalog key. Re-purposed: the 47 legacy schema floors disappear; what remains is content versions for `fallout_catalogue_sql.php` and `fallout_content_pack.php`. | 5/6 | cfg | live (content only) |
| `synth_meta.media_objects` | `synth_media_objects` | Stored media metadata and cache keys. | 3/3 | cache | live |
| `synth.visual_context` | `synth_visual_contexts` | ITT descriptions per subject (34 columns). | 12/2 | npc | live |
| `synth_meta.visual_results`, `visual_description_revisions` | `synth_visual_results`, `synth_visual_description_revisions` | Native visual results/revisions. | 1/0 each | npc | live |

### 3.6 Legacy timeline (`synth_timeline_*`) — HerikaServer-era tables still driving the pipeline

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth.eventlog` | `synth_timeline_events` | Raw game events with `gamets`/`localts` (`rowid` identity, `synth_*` correlation columns). `data` stays `text` in step 5 (see open question 6). | ~40/12 | log | legacy-live |
| `synth.speech` | `synth_timeline_speech` | Spoken dialogue per turn. | ~34/0 | log | legacy-live |
| `synth.actions_issued` | `synth_timeline_actions` | Issued game actions. | 5/1 | log | legacy-live |
| `synth.responselog` | `synth_timeline_responses` | Outbound response queue with `sent` flag (`processor/comm.php`). | 12/0 | log | legacy-live |
| `synth.rolemaster` | `synth_timeline_director_notes` | Scene notes with `ttl` (`type='scenenote'`). | ~3/0 | log | legacy-live |
| `synth.log` | `synth_audit_prompt_log` | Prompt and response log read by `log_trim.php`. | ~6/1 | log | legacy-live |
| `synth.audit_request` | `synth_audit_llm_requests` | Per-connector request/result with `usage jsonb`; read by the audit UI. | 14/0 | log | live |

### 3.7 Memory, middle term and diaries (`synth_memory_*`, `synth_middleterm_*`, `synth_diary_*`)

| Old | New | Purpose | Files | Vol | Status |
|---|---|---|---|---|---|
| `synth.memory` | `synth_memory_events` | Legacy conversation memory. | ~24/6 | log | legacy-live |
| `synth.memory_summary` | `synth_memory_legacy_summaries` | Legacy summaries with `embedding`, `embedding768`, `native_vec`. | ~14/2 | cache | legacy-live |
| view `synth.memory_v` | `synth_memory_events_view` only if a reader remains | Projection for `util_memory_subsystem.php`. | 4/1 | | review |
| `synth_meta.memory_batches` | `synth_memory_batches` | Native memory batches. | 3/2 | log | live |
| `memory_batch_sources`, `memory_batch_summary_sources`, `memory_batch_rebuilds`, `memory_batch_controls` | `synth_memory_batch_sources`, `_summary_sources`, `_rebuilds`, `_controls` | Source links and control state. | 2/1-3 each | log | live |
| `memory_summaries`, `memory_summary_revisions` | `synth_memory_summaries`, `synth_memory_summary_revisions` | Native summaries, embedding, `native_vec`. | 6/4, 3/1 | cache | live |
| `middleterm_inputs`, `_sources`, `_results`, `_replacements`, `_regeneration_approvals`, `_control_revisions` | `synth_middleterm_*` (same suffixes) | Middle-term narrative pipeline. | 2-4/0 each | npc | live |
| `diary_inputs`, `diary_input_sources`, `diary_results`, `diary_job_results`, `diary_control_revisions` | `synth_diary_*` (same suffixes) | Native diary pipeline with embeddings. | 1-5/0-1 | npc | live |
| `synth.diarylog` | `synth_diary_legacy_entries` | Legacy diary text (`ts text` becomes `timestamptz`). | 10/0 | npc | legacy-live |

### 3.8 Relationships (`synth_relationship_*`)

The 13 kept `synth_meta.relationship_*` tables keep their shape and gain the prefix (`relationship_evaluations`, `_evaluation_sources`,
`_results`, `_result_priors`, `_revisions`, `_revision_sources`, `_response_inputs`, `_response_sources`,
`_interaction_evaluations`, `_interaction_results`, `_interaction_priors`, `_interaction_result_priors`,
`_actor_controls`; the 14th, `_actor_control_events`, is dropped). Callers: 2-10 files each (combined ~25 files, all under `lib/synth_relationship*.php`,
`lib/relationship_response.php` and the relationship service); volume class npc/log; status live. Two legacy queues
`synth.relationship_eval_queue` and `synth.relationship_init_queue` (`ext/relationship_system/async_queue.php`,
3 files each) become `synth_relationship_eval_queue`/`_init_queue`; they are pruned by `save_rollback.php` (`1=1`).

### 3.9 Narrator, rechat, actions, social tasks

| Old | New | Files | Notes |
|---|---|---|---|
| `narrator_definitions`, `_definition_revisions`, `_states`, `_lane_heads`, `_context_states`, `_pending_outputs`, `_transcript_entries`, `_transcript_cursors` | `synth_narrator_*` (same suffixes) | 1-4 each; `PrivateNarratorRepository` | live; per-NPC/log |
| `rechat_chains`, `rechat_continuations`, `rechat_selections` | `synth_rechat_*` | 11-12 each | live; log |
| `action_intents`, `action_terminal_results` | `synth_action_intents`, `synth_action_results` | 8, 7 | live; log |
| `social_task_inputs`, `_claims`, `_events` | `synth_social_task_*` | 1 each (`lib/synth_social_tasks.php`) | live; npc |

### 3.10 Playthroughs and snapshots (`synth_playthroughs`, `synth_snapshots`)

| Old | New | Files | Status |
|---|---|---|---|
| `synth_meta.playthrough_profiles` | `synth_playthroughs` + `synth_snapshots` | 4/1 | snap (paused). See [section 8](#8-snapshots). |

### 3.11 Totals

121 relations in (115 tables, 6 views) → **103 tables and 4 views out** (the four `_effective` views). Counted from the tables above:
runtime 11, characters 8, settings 5, connectors 2, catalogs 15, timeline 7, memory/middleterm/diary 21, relationships 15,
narrator/rechat/actions/social 16, playthroughs/snapshots 3. Tables removed outright: 5 (`relationship_actor_control_events`,
`memory_batch_control_events`, `moods_issued`, `audit_memory`, `core_api_badge`). Tables merged away: 3 `_custom` tables, 3 of the 4 connector
tables, 4 of the 5 key/value tables. Views removed: `combined_*` (3), `synthnpcs`, `memory_v`. New tables: `synth_settings_profile_connections`,
`synth_snapshots`, `synth_snapshot_rows`. The exact figures are fixed by the Phase 2 baseline and then carried by the manifest.

---

## 4. Drop list

Evidence is `git grep` on `origin/unstable` outside `debug/db_updates.php`, the migrations and the generated baseline files.

| Relation | Evidence it is not needed | Replacement |
|---|---|---|
| `synth_meta.relationship_actor_control_events` | **0** PHP files name it (only `lib/core/database_schema/synth_protocol.sql` creates it and the `legacy_profile_id` column). `synth_relationship_control.php` reads only `relationship_actor_controls`. | `relationship_actor_controls.revision` already versions the lock. |
| `synth_meta.memory_batch_control_events` | **0** PHP files except the readiness list (`runtime_bootstrap.php:129`) and the schema SQL. Written by nothing. | `memory_batch_controls` (`revision`, `changed_at`). |
| `synth.moods_issued` | Written once (`chat_helper_functions.php:2126`) and deleted by `save_rollback.php`; no `SELECT` anywhere in 3 files. | none (no reader). |
| `synth.audit_memory` | Written only by `data_functions.php` (5 inserts, all diagnostic); no `SELECT`, no UI. | none. |
| `synth.core_api_badge` | Replaced by shared keys (#62 rework). | shared `dwemer.api_keys`. |
| `synth.core_action_custom`, `descriptions_custom`, `bio_templates_custom` | Folded into the base table with `origin`. | [section 3.5](#35-catalogs-and-authored-content-synth_catalog_-synth_worldknowledge_). |
| views `combined_core_action`, `combined_descriptions`, `combined_bio_templates` | Replaced by `_effective` views over one table. | same. |
| view `synth.synthnpcs` | Lazy compat view with 2 callers (`synth_runtime.php`, readiness list). | Query `synth_character_profiles` directly. |
| `synth_meta.settings`, `synth.general_settings`, `conf_opts`, `core_player`, `core_narrator` | Merged into `synth_settings_global`. | [section 3.3](#33-settings-profiles-and-presets-synth_settings_). |
| `synth.core_llm_connector`, `core_tts_connector`, `core_stt_connector`, `core_itt_connector` | Merged into `synth_provider_connections`. | [section 6](#6-connectors-and-shared-keys). |
| `synth.database_versioning` (47 version floors) | The floors were upgrade gates for the legacy chain (`runtime_bootstrap.php` lists them). | `synth_catalog_versions` for content only. |
| schema `plugins` | Created empty in the baseline; no table or function in the 121-object inventory lives in it. | none. |
| functions `clone_schema`, `drop_schema_safe`, `get_schema_size`, `sync_schema_sequences` | Only `lib/playthrough_schema.php` and `lib/runtime_bootstrap.php`; those entry points are already refused while snapshots are paused. | Row-scoped snapshots ([section 8](#8-snapshots)). |

Candidates **kept but flagged** as maybe dead (decision needed, [open questions](#10-open-questions)): `synth.memory`,
`memory_summary`, `diarylog` (legacy memory path that the native batches replace), `synth.log`, the two relationship queues, `rolemaster`.

---

## 5. Domain stores

One final class per domain in `core/dbmanager/stores/`, namespace `Synth\DBManager\Stores`, each taking a shared
`RuntimeConnection` (the DBAL `pgsql` connection `SharedSessionDriver` already adapts) and `RuntimeStores`, stateless and
created per call as in ChimServer. `core/dbmanager/SynthDBManager.php` is the facade; no ORM.
`GeneralSetting` (the only ORM entity) is removed with `SettingsRepository`.

| Store | Owns | Absorbs |
|---|---|---|
| `RuntimeStore` | sessions, requests, protocol events, contexts, history events | `UtteranceRepository` (request and context parts), `$db->` in `lib/synth_protocol_state.php`, `lib/actor_event.php` |
| `ActivationStore` | `synth_actor_activation` | `player_reaction.php`, `quest_reaction.php` call sites |
| `DialogueStore` | utterances, origin policies, routes | `UtteranceRepository` (utterance part), `lib/synth_dialogue_policy.php`, `synth_rechat_history.php` |
| `CharacterStore` | characters, profiles, revisions, generation jobs, import rules, bio actor map | `CharacterRepository`, `lib/core/npc_master.class.php`, `synth_profile_jobs.php`, `synth_profile_state.php` |
| `SettingsStore` | global settings, profiles, presets, prompts | `SettingsRepository`, `lib/core/settings_presets.php`, `core_profiles.class.php`, `$db` KV reads of `conf_opts`/`core_player`/`core_narrator` |
| `ProviderStore` | `synth_provider_connections` and key references | `ApiBadgeRepository` (connector part), `lib/core/{llm,tts,stt,itt}_connector.class.php`, `tts_pronunciation.php` |
| `CatalogStore` | actions, descriptions, bio templates, factions, locations, quests, plugins, versions | `lib/core/action_catalog.php`, `game_plugins.php`, `fallout_catalogue_sql.php`, `fallout_content_pack.php` |
| `WorldKnowledgeStore` | entries, catalogs, audits | `lib/worldknowledge_*.php`, `WorldKnowledgeManager.php` |
| `TimelineStore` | events, speech, actions, responses, director notes | `lib/data_functions.php` (about 60 call sites), `chat_helper_functions.php`, `processor/comm.php`, `save_rollback.php` |
| `AuditStore` | prompt log, LLM requests, memory audit (none) | `connector/*json.php`, `log_trim.php`, `Logger` DB sink |
| `MemoryStore` | legacy events and summaries, native batches and summaries | `lib/synth_memory*.php`, `util_memory_subsystem.php`, `memory_helper_vectordb.php` |
| `MiddletermStore` | middle-term tables | `lib/synth_middleterm*.php` |
| `DiaryStore` | native and legacy diaries | `lib/synth_diary_*.php`, `ui/diaries.php` |
| `RelationshipStore` | relationship tables and queues | `lib/synth_relationship*.php`, `ext/relationship_system/async_queue.php` (see open question 3) |
| `NarratorStore` | narrator tables | `PrivateNarratorRepository`, `synth_private_narrator.php` |
| `RechatStore` | rechat tables | `lib/synth_rechat_*.php` |
| `ActionStore` | intents and results | `synth_action_inventory.php`, `synth_player_gifts.php` |
| `VisualStore` | visual contexts, results, revisions, media | `lib/visual_context*.php`, `media_store.php`, `itt.php` |
| `SocialTaskStore` | social tasks | `lib/synth_social_tasks.php` |
| `WorkerJobStore` | `synth_worker_jobs` leasing | the job code in `service/processors/*` and `synth_*_jobs.php` |
| `PlaythroughStore` | playthroughs and (paused) snapshots | `SaveRepository`, `lib/playthrough_schema.php` (paused paths stay refusing) |

That is 21 stores for 103 tables; ChimServer routes 18 stores for 46. The legacy `$db->` call sites (194 files use
`$db->`/`$GLOBALS['db']->`) are removed table by table in [section 9](#9-rollout-order); `lib/postgresql.class.php`
remains only as the connection owner for sessions and advisory locks until the last step.

---

## 6. Connectors and shared keys

PR #62 (`codex/shared-api-keys`, session `local_3f5f8f84-e5f9-4131-8ff2-68ea0c0bc0ef`) is being reworked. **Head read:
`648d1a2`**, which is still the *previous* design (a local `core_api_badge.shared_key_id uuid` binding column, migration
`Version20261116100000`, `SharedApiKeyStore`, four byte-identical copies of ChimServer's shared migrations pinned in
`shared-schema-manifest.json`). The brief says the reworked branch removes the local key table and re-points the connector
tables at shared keys. This design therefore takes only that as given and does **not** redo it:

- No SYNTH-side table stores a credential or a key label. Nothing named `synth_*api_key*` exists.
- `SharedApiKeyStore` (connection, `CHIM_SHARED_DB_*`, 5 s statement timeout, fixed error messages, the four shared
  migrations and their ledger `dwemer_shared_migration_versions`) is #62's and stays unchanged and outside the baseline.
- Each connector row carries `credential_key_id uuid NULL`: the `key_id` of a shared `api_keys` row. No FK (another
  database). `NULL` means "no key" (local STT/TTS, or a provider that needs none).

Target table, one for all four kinds, like `chim_provider_connections`:

```
synth_provider_connections
  connection_id   bigint identity PK
  kind            varchar(3)   CHECK (kind IN ('llm','tts','stt','itt'))
  label           varchar(64)  NOT NULL, UNIQUE (kind, label)
  driver          varchar(32)  NOT NULL            -- today's driver/provider/service values
  endpoint        varchar(2048)                    -- url, CHECK like chim endpoint rule
  model           varchar(256)
  params          jsonb NOT NULL DEFAULT '{}'      -- sampling, voice_field, json mode, prefill, metadata
  credential_key_id uuid                           -- shared api_keys.key_id (#62), no FK
  revision        bigint NOT NULL DEFAULT 1
  created_at, updated_at timestamptz
  CHECK (jsonb_typeof(params)='object')
```

Differences from `chim_provider_connections`: SYNTH has four kinds, so `kind` is wider; the single `credential_ref`
`'env:'|'key:'` string and `credential_origin` are *not* copied, because #62 pins the provider and origin in the shared
row. Profiles reference `connection_id`, not an `api_badge_id`, so no connector row is rewritten for a key change.

Risk to resolve at Phase 2 step 1: the exact column name and bind semantics in #62's rework. If its final head names the
column differently (for example `api_key_id`), the baseline adopts that name; the design only requires "a nullable uuid on the
connection row". Phase 2 must re-read the then-current head before writing the baseline.

Alternative considered: keep four per-kind tables (as #62's brief says "connector tables"). Rejected because the four
tables share endpoint, label, driver, key reference and `metadata`, and the profile-to-connector join then needs a `kind`
discriminator anyway. Open question 1 asks for the owner's choice.

---

## 7. Manifest and drift check

Adopt ChimServer's format-2 manifest (`runtime.json`) and adapt `tools/schema-check.php`.

| Aspect | Today (format 1) | Target (format 2, ChimServer) |
|---|---|---|
| Content frozen | hashes of the migration class and its two generated companion files; structure text file (2062 lines) | per-migration `migration_hashes` (SHA-256 of each `Version*.php`, LF-normalized) **and** a catalog snapshot of every managed table: columns (type, nullability, identity, default, collation), constraints (name, type, validated, definition), indexes, triggers, policies, owned sequences; and functions |
| Scope | structure lines in `synth` and `synth_meta`, `vector` typmods | explicit list of managed tables in `public` plus owned functions; other relations are ignored, never repaired |
| PostgreSQL major | one manifest (generated on 15, validated on 17) | `runtime.json` (15) retained as the legacy name only for major 15; `runtime-pg17.json` for 17. SYNTH has no installed majors to carry, so **start at 17 only** and name the file `runtime-pg17.json`; add another major only by a reviewed file |
| Generation | hand-edited text from a PG15 inventory | `tools/schema-candidate.php runtime --from-disposable-migrations`: builds from an empty disposable database named `synth_schema_candidate_*`, prints a candidate, never overwrites |
| Check | `tools/schema-check.php` compares structure lines, ledger state | `tools/schema-check.php runtime`: loads every reviewed manifest, validates hashes **before** connecting, picks the one for the server major, compares catalog, ledger versions exactly, exits 0 match / 1 drift / 2 cannot inspect / 3 manifest stale |

Concrete changes in Phase 2: replace `core/dbmanager/migrations/schema-manifest.json` with
`core/dbmanager/schema/runtime-pg17.json`; add `core/dbmanager/schema/SchemaDefinition.php` and `SchemaInspector.php`
(ported shapes, SYNTH namespace and read-only transaction wrapper from today's `SchemaCheck.php`); keep `--offline`,
`--hashes` and the fixed-message error policy; drop `<Version>.structure.txt` and `<Version>.fresh.sql` as manifest inputs (the
baseline is now authored SQL in the `.php`, see below). `vector` columns keep their typmods as part of the type string.
Limits text is copied from ChimServer's `SchemaDefinition` and adapted ("PostgreSQL 17 only").

**Baseline shape.** `Version20261117000000.php` holds the DDL in `addSql()` heredocs, as ChimServer's migrations do, not a 5500-line
generated `.fresh.sql`. It is generated once from a disposable PG17 and hand-reviewed; later changes are new migration files
with their own `migration_hashes` entry. The old "fresh vs chain" duality (`isEmptyDatabase`, SHA-pinned statement list,
legacy session lock) is removed from `SchemaMigrations.php`: the owner migrates an empty or ledgered database only and refuses
anything else with a fixed message ("recreate the database").

---

## 8. Snapshots

Snapshots stay **feature-paused**: nothing in this redesign re-enables `timeline_break_create_snapshot`, `pts_clone_schema`
or the playthrough UI. The existing refusals stay in place and keep their tests.

The old design cloned a whole schema; with one `public` schema that is impossible, and it also copied configuration
and catalogs. The redesign makes a snapshot **row-scoped to one playthrough** and records the version metadata D6 needed:

```
synth_playthroughs        playthrough_id text PK, name, game, player_name, is_active, notes, created_at, updated_at
synth_snapshots           snapshot_id bigint identity PK, playthrough_id FK, reason text,
                          format text NOT NULL,                 -- 'synth-schema-snapshot.v2'
                          schema_major int NOT NULL,            -- PostgreSQL major the manifest targets (17)
                          migration_versions jsonb NOT NULL,    -- sorted list, from the ledger (as captureSnapshot())
                          manifest_sha256 char(64) NOT NULL,    -- hash of runtime-pg17.json at capture
                          table_counts jsonb NOT NULL, size_bytes bigint, last_gamets bigint,
                          state text CHECK (state IN ('creating','ready','failed')), created_at timestamptz
synth_snapshot_rows       snapshot_id FK, table_name text, rows jsonb   -- or an external file; see open question 5
```

`SchemaMigrations::captureSnapshot()` and `restoreSnapshot()` are kept as the compatibility guard (same metadata contract:
`format`, `schema`, `migration_versions`); `schema` becomes `public` and `manifest_sha256` is added. Per-save tables are the
ones carrying `playthrough_id` or a `gamets` column; the table list is a constant in `PlaythroughStore`, checked against the
manifest by a test so a new per-save table cannot be forgotten. Restore refuses unless `migration_versions` and
`manifest_sha256` equal the live values. Existing refusals remain until the owner lifts the pause.

---

## 9. Rollout order

The baseline cannot be both replaced and kept running in one commit, so Phase 2 regenerates the **single** baseline at every
step. At step N the baseline file contains new-name tables for the domains already done and the old tables, verbatim and
under their old names, for the rest. Each commit therefore installs on an empty disposable PG17, passes `schema-check` and the
suite, and the app runs. The final step deletes the last verbatim legacy block. All of this stays on the one branch
`codex/schema-redesign`; each step is a commit, not a PR. Estimates count files touched in production code (tests extra,
roughly +30 %).

| Step | Scope | Prod files (est.) | Notes / risk |
|---|---|---|---|
| 0 | Scaffolding: `Version20261117000000` = old baseline renamed byte-for-byte as authored SQL; format-2 manifest and `schema-candidate`; `SynthDBManager` + `RuntimeConnection` + `RuntimeStores`; `DatabaseTestCase` builds the fresh baseline instead of `database_default.sql` plus the chain. No table renamed. | ~15 (`core/dbmanager/*`, `tools/schema-*.php`, `unittests/DatabaseTestCase`, docs) | Highest leverage; proves fresh install and drift. |
| 1 | Settings and provider connectors: `synth_settings_*`, `synth_provider_connections`, `synth_tts_pronunciations`, profiles. Rebase on #62's reworked head first. | ~75 (`conf_opts` ~34, `core_profiles` 28, `llm_connector` 19, `core_player` 14, `core_narrator` 7, connectors, `ui/core/*`) | Largest fan-out through UI; `ApiBadgeRepository`, `SettingsRepository` removed. |
| 2 | Characters: `synth_characters`, `synth_character_*`, `synthnpcs` view dropped. | ~60 (`actor_identities` 38, `core_npc_master` 38, history 8) | Identity FK used by every native table; do before runtime. |
| 3 | Catalogs: actions, descriptions, bio templates (origin collapse), factions, locations, plugins, worldknowledge, catalog versions, media/visual. | ~55 | Touches installers `fallout_catalogue_sql.php`, `fallout_content_pack.php`. |
| 4 | Runtime/protocol: sessions, requests, protocol events, contexts, activation, dialogue, routes, policies, history events, worker jobs. | ~95 (`request_executions` 45, `context_snapshots` 43, `runtime_sessions` 41, `protocol_events` 41, `history_events` 31) | Largest and riskiest; native dialogue turn tests, loopback. Split commits: 4a runtime (sessions, requests, events, contexts), 4b dialogue, 4c worker jobs and history. |
| 5 | Legacy timeline and audit: events, speech, actions, responses, director notes, prompt log, LLM requests; `save_rollback.php`, `legacy_row_management.php`. | ~75 (`eventlog` ~40, `speech` ~34, `data_functions.php`) | Keep `localts`/`gamets` bigint unchanged; no semantic rewrite. |
| 6 | Memory, middle term, diaries (native and legacy). | ~40 | Includes vector columns; drop `memory_batch_control_events`. |
| 7 | Relationships and queues. | ~30 | Includes `ext/relationship_system/*`. |
| 8 | Narrator, rechat, action intents, social tasks. | ~35 | |
| 9 | Snapshots/playthroughs ([section 8](#8-snapshots)); delete the last legacy block, `lib/core/database_schema/*.sql`, the frozen `debug/db_updates.php` chain, `data/database_default.sql`; update `README.md`, `CONTRIBUTING.md`, `docs/SCHEMA-MIGRATIONS.md`, `docs/HANDOFF.md`; final search proving no old table name remains. | ~25 | **Deletes whole files: needs the owner's explicit approval (AGENTS.md).** |

Total about **500 prod-file edits across 130–140 distinct files**, plus ~60 test files; matches the "133 PHP files" noted at the start.
Every step ends with: `php -l` on changed files, the PHPUnit suite on isolated PG17, `schema-check` match on a fresh
install, the dashboard HTTP probe, the credential harnesses, and a name-search for the removed old names. Scratch under
`D:\build\synth-schema-redesign`.

Test fixture note: `unittests/tests/DatabaseTestCase` builds the schema from `data/database_default.sql` plus the legacy chain
today (111 test files, about 30 use per-table assertions). Step 0 changes it once; every later step edits only its own
domain's assertions.

---

## 10. Open questions

For Stu (CHIM 4 / schema owner):

1. **Single `synth_provider_connections` or four per-kind tables?** Recommendation: one table (as `chim_provider_connections`). Needs agreement with #62's final shape.
2. **Prefix `synth_` in `public`, versus keeping a `synth` schema?** Recommendation: `public` + prefix, as CHIM.
3. **Are `ext/relationship_system/*` and the two relationship queues still maintained?** If not, they and their tables go in the drop list.
4. **`credential_key_id` name and semantics** in #62's rework (nullable uuid, no FK, shared `api_keys.key_id`).
5. **Snapshot storage:** `synth_snapshot_rows jsonb` in the same database, or a per-snapshot file/schema outside the application schema?

For the owner:

6. **Can the legacy `memory`, `memory_summary`, `diarylog` tables be retired now?** Pre-alpha, no data to keep: the native batches/summaries/diaries
   exist. Retiring removes ~50 caller files from steps 5–6 but is a behaviour change, not a rename. `eventlog.data` JSON-vs-text is part of the same decision.
7. **Approval to delete files** in step 9 (`debug/db_updates.php`, `lib/core/database_schema/*.sql`, `data/database_default.sql`, the old baseline's companion files). AGENTS.md forbids removing complete files without it.
8. **Settings collapse:** `synth_settings_global` merges five KV tables; OK, or keep `core_player`/`core_narrator` separate for UI parity?
9. **Drop `moods_issued` and `audit_memory`** ([section 4](#4-drop-list))? Evidence shows no reader; confirm no external reporting depends on them.
10. **New baseline id `Version20261117000000`:** acceptable, given #62's `Version20261116100000` goes away?
11. **Test impact:** ok to rewrite per-table assertions in ~30 test files as part of each step, rather than add compatibility views for old names?
12. **PostgreSQL 15 users:** the manifest targets 17 only. Is anyone still on 15?

Out of scope and unchanged by this design: the installed database, deployments, services, the game, legacy snapshots (stay paused), PR #41,
ChimServer, HerikaServer, Stobe and Dialectic.
