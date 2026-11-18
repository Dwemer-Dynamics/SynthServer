# Schema redesign (from scratch, on the CHIM Evolution persistence model)

**Status: design approved at `9bcdbdf`; Phase 2 in progress, step 0 (scaffolding) done.** Steps 1 and later are not started. This document stays the design of record; the answers to the owner questions are recorded in [section 13](#13-open-questions).
It supersedes conventions D1, D2, D4 and D5 of
[SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md#schema-conventions); D6 is replaced by [snapshots](#9-snapshots).

Decision behind it, quoted from the owner: "we can reorganize and rework tables from scratch it does not
matter. we dont need to care about migrations". SYNTH is pre-alpha, so there is **no upgrade path**: the new schema is
one new Doctrine baseline replacing `Version20261115000000`, and an install made from the old baseline is recreated.

**Target: chevo ("CHIM Evolution", CHIM 4.0), not ChimServer.** An earlier revision of this document mapped to ChimServer
(DBAL stores, `chim_*`, no ORM). The owner chose chevo, so that revision is replaced: ORM, `core_*`, entities and DAOs.

Inputs, all read-only:

- SYNTH `origin/unstable` `0fa6e84`: 121 relations (115 tables, 6 views); 53 in `synth`, 68 in `synth_meta`.
- chevo `origin/unstable` `8ebe86e`: `README.md`, `PLAN.md`, `AGENT.md`, `.github/copilot-instructions.md`, `coding-standards.txt`,
  `core/dbmanager/PLAN.MD`, `core/dbmanager/CORE-ENTITY-ALIGNMENT.md`, `api/v1/editing/README.md`, all 51 mapped entities in
  `core/entities/base`, `Database.php`, `Editor/EntityCatalog.php`, and the 51 incremental migrations in `core/dbmanager/migrations`.
- PR #62 (`codex/shared-api-keys`, head still `648d1a2` when last read) **owns key storage in both stores**: the shared `dwemer` `api_keys` store (default) and a small local custom-key table. This design only binds connectors to its facade ([section 7](#7-api-keys)).

Inventory method: `git grep` on `origin/unstable` over tracked files, excluding `vendor/`, `unittests/`,
`core/dbmanager/migrations/` and the frozen `debug/db_updates.php`. A "caller file" names the table in SQL position
(`FROM`, `JOIN`, `INTO`, `UPDATE`, `synth.x`, `synth_meta.x`) or as a quoted table argument (`$db->insert('x', ...)`).
`lib/runtime_bootstrap.php` (the readiness gate) lists most tables and is not counted as a caller. Counts are files, not
call sites; generic names (`log`, `memory`, `speech`, `prompts`, `factions`) are approximate (`~`). They size work, they prove nothing.

---

## 1. What chevo's persistence model is

From `PLAN.md` §6 and `core/dbmanager/PLAN.MD`:

- **Doctrine ORM + Doctrine Migrations**. Only `core/dbmanager` touches the database; no second ORM.
- **Three layers per `core_*` table.** A mapped class `core/entities/base/Abstract<Name>.php` (`#[ORM\Entity]`, `#[ORM\Table(name: 'core_x')]`,
  protected typed properties); an application-facing class `core/entities/system/<Name>.php` (no ORM attributes; static lookups
  return untracked copies via the `CopiesMappedEntity` trait); a DAO `core/dbmanager/dao/<Name>DAO.php` (`Chevo\Core\DbManager\Dao`,
  singleton-backed, registered in `Database::DAOS`). Top-level code (API, `lib`, drivers, workers, UI) uses **only** the system
  classes; it never calls a DAO and never names an `Abstract*` class.
- **Boot.** `Database::boot()` creates the shared EntityManager from config and configures every DAO; types beyond Doctrine's
  live in `core/dbmanager/Types` (`PreciseDateTimeType` / `datetimetz_precise`, microsecond `timestamptz`), listed in `Database::TYPES`.
- **`core_` means the pattern, not membership.** A table outside the pattern must use another name and document its owner and
  access path. Migration ledgers, temporary tables and archives keep their names.
- **Atomic work stays DBAL inside DAOs.** Locks, leases, claims and append-only checkpoints keep their SQL; their tables get
  `#[ORM\Entity(readOnly: true)]` mappings with scalar columns only (no associations), as chevo did for `core_relationship_*`
  and `core_narrator_*`. They are not registered as generic editable resources.
- **Migrations.** Incremental `Chevo\Migrations\VersionYYYYMMDDHHMMSS` classes using `addSql()` with
  `CREATE TABLE IF NOT EXISTS public.core_x (...)`, `all_or_nothing`, `check_database_platform`. There is **no baseline, no manifest
  and no schema-check in chevo**: its guard is hand-written PostgreSQL migration tests and a data-preservation procedure.
- **Edit API.** `/api/v1/edit` (`api/v1/editing`, `Editor/EntityCatalog` + `EntityEditor`) is a generic CRUD API built on the Doctrine
  mapping; the UI and an MCP server use it ([section 10](#10-ui-and-the-edit-api)).
- **Conventions** (`coding-standards.txt`): file and method comments, `declare(strict_types=1)`, PSR-12, no `function_exists()` checks.

SYNTH already ancestrally shares chevo's names: `core_llm_connector`, `core_tts_connector`, `core_stt_connector`,
`core_itt_connector`, `core_profiles`, `core_npc_master`, `core_action` are CHIM-lineage tables. So most of this redesign is
**aligning SYNTH to chevo's current shape of the same concept**, and designing SYNTH-only tables in the same style.

---

## 2. Decisions

| # | Decision | Why |
|---|---|---|
| C1 | Doctrine ORM with chevo's three layers and DAO registry (`Database::boot()`, `Database::DAOS`) under `core/dbmanager`, `core/entities/base`, `core/entities/system`. Namespaces `Synth\Core\Entities\Base`, `Synth\Core\Entities\System`, `Synth\Core\DbManager\Dao`. Supersedes D4 (ORM pilot frozen) and the earlier DBAL-store plan. | Owner decision; PLAN.md §6. |
| C2 | All tables live in `public` and are named `core_*`, using **chevo's name wherever chevo has the concept**. SYNTH-only tables are also `core_<singular_noun>` because they use the same mapped pattern. The `synth` and `synth_meta` schemas, the empty `plugins` schema and every `synth.`/`synth_meta.` qualifier disappear. | chevo naming rule: `core_` marks the pattern. |
| C3 | One new baseline `Version20261117000000` (`Synth\Migrations`) replaces `Version20261115000000`; its class, `.structure.txt` and `.fresh.sql` are regenerated from a disposable PostgreSQL 17. Later changes are incremental migrations in chevo's style. | No upgrade path (owner). |
| C4 | API keys: **shared global keys by default, plus SYNTH-only custom keys** the user creates explicitly (providers the shared table cannot hold: Player2, local services, custom endpoints, region-specific Azure). Both stores are owned by PR #62 and presented as one list by a chevo-style `ApiBadge` facade; each key records its store. A connector binds to exactly one store's key and nothing switches stores silently. | Owner decisions ([section 7](#7-api-keys)). |
| C5 | SYNTH's read-only drift check stays and is retargeted to `public.core_*` ([section 8](#8-schema-check)). | chevo has none; SYNTH's is kept. |
| C6 | Snapshots stay **paused**; their metadata is designed ([section 9](#9-snapshots)). | Hard hold. |
| C7 | The generic edit API and moving UI SQL behind it is a **later phase** ([section 10](#10-ui-and-the-edit-api)); every domain step still moves UI SQL into system-entity methods. | Size. |
| C8 | Phase 2 proceeds domain by domain; the baseline is regenerated at every step so the app runs at each commit ([section 11](#11-rollout-order)). | App must stay runnable. |

### Type and column conventions (chevo's, adopted)

- Config tables keep `integer` generated ids; event, log and queue tables use `bigint`.
- Timestamps: `PreciseDateTimeType` (`timestamptz`, microseconds) for server-written times; `datetime_immutable` where chevo uses it.
- JSON: Doctrine `json` with `options: ['jsonb' => true]`. Booleans are `boolean`, not `0/1` integers.
- Hashes are fixed-length strings with `CHECK`s. Enumerations get `CHECK (x IN (...))`.
- `playthrough_id` is a `guid` in chevo; SYNTH stores it as `text`. Adopting `guid` is open question 4.
- **Specialized columns** (`vector(n)`, `tsvector`, generated columns, `point`) are **not** ORM fields: the mapping omits them and the DAO
  reads and writes them with parameterized DBAL, as chevo does for search columns (`CORE-ENTITY-ALIGNMENT.md`: "not a project to map every
  specialized search column"). Their typmods and indexes are still frozen by the schema-check.
- Foreign keys: durable state tables have real FKs; append-only logs carry ids without FKs so pruning by `gamets` stays cheap.

---

## 3. Inventory and mapping to chevo

**Match** column: `=` same concept and same columns as the chevo table; `~` same concept, columns differ (differences listed);
`new` SYNTH-only, designed in chevo's style; `merge` folded into another table; `drop` removed ([section 5](#5-drop-list)).
Volume: **cfg** config or catalog, **npc** per-NPC, **log** per-turn or append-only, **cache** rebuildable.
Status: **live**, **legacy-live** (HerikaServer-era table still driving the pipeline), **dead**, **snap**.
"Files" is `prod/tests` caller files.

### 3.1 API keys, connectors, profiles, settings

| SYNTH table | chevo table / new name | Match | Notes | Files | Vol | Status |
|---|---|---|---|---|---|---|
| `synth.core_api_badge` | local custom-key table owned by #62 (custom keys only) + shared `api_keys`, both behind the `ApiBadge` facade | `~`, owned by #62 | The local table holds **only** SYNTH-only custom keys; shared keys are never copied into it. Its columns are #62's to define; this design depends on it, not on its shape. | 4/0 | cfg | live |
| `synth.core_llm_connector` | `core_llm_connector` | `=` | Identical 22 columns; `api_badge_id` is renamed `local_api_key_id integer NULL` and `api_key_id uuid NULL` is added, with a CHECK that at most one is set ([section 7](#7-api-keys)); sampler columns, `metadata jsonb` unchanged. `int` flags (`enforce_json`, `json_schema`, `prefill_json`, `reasoning_model`) stay integers as in chevo. | 19/5 | cfg | live |
| `synth.core_tts_connector` | `core_tts_connector` | `=` | `voice_field` present in both; key-binding pair as above. | 6/1 | cfg | live |
| `synth.core_stt_connector` | `core_stt_connector` | `=` | Key-binding pair as above. | 3/1 | cfg | live |
| `synth.core_itt_connector` | `core_itt_connector` | `=` | Key-binding pair as above. | 3/0 | cfg | live |
| `synth.core_profiles` | `core_profiles` | `~` | chevo adds `itt_connector_id`; SYNTH has the rest (six `llm_*_id`, `tts_connector_id`, `diary_connector_id`, `slot`, `prompt`, `metadata`). Add `itt_connector_id`. | 28/8 | cfg | live |
| `synth.general_settings`, `synth.conf_opts`, `synth_meta.settings`, `synth.core_player`, `synth.core_narrator` | `core_config_setting` (`ConfigSetting`) | merge | chevo: `config_key text PK, config_value json, updated_at`, dotted lower-case keys (`player.tts`, `memory.window.*`) with a registry in `ConfigManager`. Five SYNTH key/value tables become one. Player/narrator definition values become `player.*` / `narrator.*` keys. | 5+34+4+14+7 / ~25 | cfg | live |
| `synth.core_tts_pronunciation` | `core_config_setting` keys `tts.pronunciation.rules` / `.defaults` | merge | chevo stores pronunciation as settings, not a table. | 2/1 | cfg | live |
| `synth.global_settings_presets` | `core_config_preset` | new | `id integer, name varchar(60) unique, snapshot jsonb, created_at, updated_at`; mapped, editable. | 2/0 | cfg | live |
| `synth.prompts` | `core_prompt` | new | chevo renders prompts from files in `PromptManager` and has no table; SYNTH has `default_prompt`/`custom_prompt` per key. Kept as `(prompt_key PK, default_prompt, custom_prompt, description, updated_at)`. See open question 7. | ~26/3 | cfg | live |

### 3.2 Characters, plugins, authored content

| SYNTH table | chevo table / new name | Match | Notes | Files | Vol | Status |
|---|---|---|---|---|---|---|
| `synth.core_npc_master` | `core_npc` | `~` | chevo `core_npc`: `character_id integer PK, canonical_name, npc_favorite, lock_profile, dynamic_profile, profile_id, prompt_head, npc_static_bio, oghma_knowledge_tags, emote_moods, personality, relationships, occupation, appearance, skills, speechstyle, goals json, voiceid, voicefilter, ...`; SYNTH `npc_master` has the same family plus `md5`, `base`, `core`, `refid`, `race`, `gender`, `gamets_last_updated`, `extended_data`. Identity text moves to `core_npc_actor`; SYNTH fields with no chevo column (`core`, `md5`, `base`, Fallout `worldknowledge_tags`) are added to `core_npc` or `extended_data`. | 38/16 | npc | live |
| `synth.actor_identities` | `core_npc_actor` | `~` | chevo: composite key `(refid, plugin)` plus `character_id`, `name`, `gender`, `race`, `actor_class`, `base_plugin`, `base_formid`, template and replaced fields. SYNTH identity is `(playthrough_id, game, origin_plugin, full_form_id)` bigint. Fallout `full_form_id` becomes `(refid, plugin)`; `playthrough_id` stays on the runtime tables, not the actor. See open question 3. | 38/15 | npc | live |
| `synth.core_npc_master_history` | `core_npc_history` | `~` | chevo: `(character_id, saved_gamets) PK, state_hash, state json`. SYNTH's 31-column row copy becomes a JSON `state` snapshot per save point. | 8/1 | npc | live |
| `synth.import_rules` | `core_npc_profile_rule` | `~` | chevo: `id, label, enabled, priority, profile_id, race, gender, actor_class, plugin, local_formid`. SYNTH `match_*`, `mods[]`, `action jsonb` map onto these; arrays and `action` that chevo lacks are added. | 4/0 | cfg | live |
| `synth.bio_templates`, `bio_templates_custom`, `bio_template_actor_map` | `core_npc_biography_template`, `core_npc_biography_application`, `core_npc_biography_import` | `~` | chevo: versioned `document json` templates, per-NPC application report and catalog import record. SYNTH's flat 15-column template rows become `document`. | 6/9, 3/2, 5/6 | cfg | live |
| `synth.game_plugins` | `core_plugins` | `~` | chevo: `canonical_name PK, reported_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix`; same fields, `plugin_name` becomes `canonical_name`. | 3/1 | cfg | live |
| `synth_meta.profile_revisions`, `profile_job_inputs`, `profile_job_fields` | `core_character_generation`, `core_character_generation_field` | new | AI profile generation jobs and results (chevo's `core_npc_biography_application` records only template application). | 1/1, 1/0, 1/0 | npc | live |
| view `synth.synthnpcs` | none | drop | Lazy compat view (2 callers). | 2/1 | | dead after C2 |

### 3.3 Catalogs and knowledge

| SYNTH table | chevo table / new name | Match | Notes | Files | Vol | Status |
|---|---|---|---|---|---|---|
| `synth.descriptions`, `descriptions_custom`, view `combined_descriptions` | `core_item_description`, `core_item_description_custom` | `=`/`~` | Same base/custom split. chevo adds `reported_plugin`, `catalog_version`, `row_sha256`, timestamps. The view is replaced by chevo's merge in `ItemDescription` (the system class). | 4+2+3 | cfg | live |
| `synth.worldknowledge`, view `worldknowledge_effective` | `core_oghma_article`, `core_oghma_custom` | `~` | chevo: `topic PK, aliases, retrieval_phrases, basic/advanced text and tags, keywords, category, catalog_version, row_sha256`; SYNTH keeps `entry_id`, `canonical_topic`, `knowledge_class*`, `topic_desc*`, plus `vector384` and `native_vector` (specialized, DAO-only). "Oghma" is a Skyrim name: open question 5. | ~20/8 | cfg | live |
| `synth.worldknowledge_catalogs` | `core_oghma_catalog_state` | `~` | chevo: singleton `id smallint, catalog_version, articles_sha256, manifest_sha256, row_count, imported_at`. SYNTH keeps multiple catalogs (`catalog_id`, `is_active`, `manifest jsonb`). | 6/3 | cfg | live |
| `synth.worldknowledge_audit` | `core_worldknowledge_audit` | new | retrieval audit rows (23 columns). | 3/2 | log | live |
| `synth.database_versioning` | `core_catalog_version` | new (**done, step 3a**: `public.core_catalog_version(catalog_key text PK, version bigint)`, the 47 frozen floors kept as seed rows until step 8) | Content versions per catalog key for `fallout_catalogue_sql.php` and `fallout_content_pack.php`; the 47 legacy schema floors disappear. | 5/6 | cfg | live (content) |
| `synth.locations` | `core_location`, `core_location_state`, `core_location_marker`, `core_worldspace` | `~` | chevo splits a stable catalog (`location_id bigint`, `plugin`, `baseid`, hierarchy, `map_x`, `map_y`) from per-playthrough `core_location_state(playthrough_id, location_id, cleared)`. SYNTH's flat `formid`, `coords point`, `cleared`, `refs`, `tags` move accordingly. | ~7/1 | cfg | live |
| `synth.factions` | `core_faction` | new | Fallout faction ranks and vendor data (`caps`, `stock jsonb`, `vendor_cont`). | ~4/5 | cfg | live |
| `synth.quests` | `core_quest` | new | Fallout quest records; `ts text` becomes `PreciseDateTimeType`; pruned by `gamets`. | ~6/4 | per-save | live |
| `synth.core_action`, `core_action_custom`, view `combined_core_action` | `core_action`, `core_action_custom` | new | chevo's actions are code-defined (`ActionsManager`); SYNTH's catalog stays as authored rows with the same base/custom split. View dropped; the system class merges. | 4+3+4 | cfg | live |
| `synth_meta.media_objects` | `core_media_object` | new | Media metadata and cache keys (chevo keeps audio files in `cache/` with no table). | 3/3 | cache | live |
| `synth.visual_context`, `synth_meta.visual_results`, `visual_description_revisions` | `core_visual_context`, `core_visual_result`, `core_visual_description_revision` | new | ITT descriptions per subject; chevo has an ITT connector but no visual table. | 12/2, 1/0, 1/0 | npc | live |

### 3.4 Timeline, dialogue, queue, logs (the legacy HerikaServer tables)

| SYNTH table | chevo table / new name | Match | Notes | Files | Vol | Status |
|---|---|---|---|---|---|---|
| `synth.eventlog` and `synth_meta.history_events` | `core_context_event` | merge, `~` | chevo `core_context_event`: `id bigint, playthrough_id guid, event_type, gamets, gametm, recorded_at, dialogue_status, characters_present json, location_id, party json, payload json, context_metadata json, request_id, utterance_id, chain_id`. SYNTH has **two** ledgers for the same thing: legacy `eventlog` (~40/12) and the native `history_events` (31/8, with `eventlog_rowid`). Both become one `core_context_event`; `eventlog.type`, `data`, `people`, `location`, `party`, `ts`, `localts`, `sess`, `delivery_state` map onto `event_type`, `payload`, `characters_present`, `location_id`, `party`, `recorded_at`, `context_metadata`. **The largest semantic change in the plan.** | ~40/12 + 31/8 | log | legacy-live + live |
| `synth.speech`, `synth.actions_issued`, `synth.moods_issued` | `core_context_event` rows | merge | Spoken lines and issued actions are event types (chevo records them as context events). `moods_issued` is dropped ([section 5](#5-drop-list)). | ~34/0, 5/1, 3/0 | log | legacy-live |
| `synth.responselog` | `core_message_queue` (`QueuedMessage`) | `~` | chevo: durable outbound queue with `message_type`, `payload`, `request_id`, `status`, `delivered_at`, `cancelled_at`, `expires_at`, `notified_at`. SYNTH's `sent` flag becomes `status`. | 12/0 | log | legacy-live |
| `synth.rolemaster` | `core_director_note` | new | Scene notes with `ttl` (`type='scenenote'`). | ~3/0 | log | legacy-live |
| `synth.log`, `synth.audit_request` | `core_llm_request_log` | merge, `~` | chevo `core_llm_request_log`: 26 columns (`status`, `purpose`, `connector_id`, `driver`, `model`, `request`, `response_*`, `duration_ms`, `replay_of_id`). SYNTH `log(prompt, response, url)` and `audit_request(connector, request, result, url, usage)` are both subsets. | ~6/1 + 14/0 | log | legacy-live + live |
| `synth_meta.request_executions` | `core_dialogue_request` | `~` | chevo `core_dialogue_request`: `request_id`, `request_type`, `gamets`, `chain_id`, `speaker_rid`, `listener_rid`, `dialogue_text`, `request_payload json`, `created_at`, `completed_at`, cancel fields. SYNTH adds idempotent claim, `request_sha256`, stored `response_body bytea`, `state`; those columns are added (response body is DAO-only). | 45/15 | log | live |
| `synth_meta.context_snapshots` | `core_runtime_context` | new | Per-sequence context payload and audience (chevo derives context from events). | 43/13 | log | live |
| `synth_meta.protocol_events` | `core_protocol_event` | new | Canonical wire events with hash, for replay and audit. | 41/9 | log | live |
| `synth_meta.runtime_sessions` | `core_runtime_session` | new | Game connection state: generation, active turn, capabilities, per-worker dispatch deadlines. | 41/13 | cfg-ish | live |
| `synth_meta.active_agents` | `core_actor_activation` | new | chevo's archived `core_actor_assignment` is the nearest ancestor; SYNTH's per-session activation stays. | 4/2 | npc | live |
| `synth_meta.actor_event_batches` | `core_actor_event_batch` | new | Batched observations. | 1/0 | log | live |
| `synth_meta.dialogue_utterances`, `dialogue_origin_policies`, `response_routes` | `core_dialogue_utterance`, `core_dialogue_origin_policy`, `core_dialogue_route` | new | Receipt state, origin policy (`policy jsonb`), speaker/listener routing. | 4/2, 7/4, 9/5 | log | live |
| `synth_meta.worker_jobs` | `core_worker_job` | new, `~` | chevo `core_worker_unit(worker, unit_key, failures, retry_after)` is a derived-from-data back-off record, not a lease queue. SYNTH's leases and five nullable FK columns are kept (`CHECK (num_nonnulls(...) <= 1)`). See open question 8. | 23/5 | log | live |

### 3.5 Memory, middle term, diaries

| SYNTH table | chevo table / new name | Match | Notes | Files | Vol | Status |
|---|---|---|---|---|---|---|
| `synth.memory`, `synth.memory_summary`, view `memory_v` | `core_memory` | merge, `~` | chevo `core_memory`: `id, playthrough_id, gamets, gametm, from_gamets, source_type, source_key, source_id_from/to, episode, owner_character_id, character_ids json, location_id, text, tags, created_at`. SYNTH legacy raw `memory` (~24/6) and summarised `memory_summary` (~14/2) both fit as `source_type`; the SYNTH `embedding` (384), `embedding768` and `native_vec` stay DAO-only columns. View dropped. | ~24/6, ~14/2, 4/1 | log/cache | legacy-live |
| `synth_meta.memory_batches`, `memory_batch_sources`, `memory_batch_summary_sources`, `memory_batch_rebuilds`, `memory_batch_controls` | `core_memory_batch` + `_source`, `_summary_source`, `_rebuild`, `_control` | new | Native memory batching. | 3/2, 2/1-3 each | log | live |
| `synth_meta.memory_summaries`, `memory_summary_revisions` | `core_memory_summary`, `core_memory_summary_revision` | new | Native summaries with vectors (DAO-only). | 6/4, 3/1 | cache | live |
| `synth_meta.middleterm_*` (6 tables) | `core_middleterm_input`, `_source`, `_result`, `_replacement`, `_regeneration_approval`, `_control_revision` | new | chevo has `memory.middle_term.*` settings but no table. | 2-4/0 each | npc | live |
| `synth_meta.diary_*` (5 tables) and `synth.diarylog` | `core_diary_input`, `_input_source`, `_result`, `_job_result`, `_control_revision`, `core_diary_legacy_entry` | new | `diarylog` rows could instead be `core_memory` with `source_type='diary'`: open question 6. | 1-5/0-1, 10/0 | npc | live / legacy-live |

### 3.6 Relationships and narrator

| SYNTH table | chevo table / new name | Match | Notes | Files | Vol | Status |
|---|---|---|---|---|---|---|
| `synth_meta.relationship_actor_controls` and `relationship_revisions` | `core_relationship_config` | `~` | chevo: directed pair per playthrough `(playthrough_id, source_character_id, target_character_id)` with `relation_detail`, `custom_info`, `private_notes`, `automatic_updates_locked`, `baseline_affinity`, `baseline_type`, `revision`. SYNTH's `locked`, `affinity`, `relationship_type`, `revision` correspond. | 3 + 6 | npc | live |
| `relationship_evaluations`, `_evaluation_sources`, `_results`, `_result_priors`, `_revision_sources`, `_response_inputs`, `_response_sources`, `_interaction_evaluations`, `_interaction_results`, `_interaction_priors`, `_interaction_result_priors` (11 tables) | `core_relationship_evaluation`, `_evaluation_source`, `_result`, `_result_prior`, `_revision_source`, `_response_input`, `_response_source`, `_interaction_evaluation`, `_interaction_result`, `_interaction_prior`, `_interaction_result_prior` | new | SYNTH's LLM evaluation pipeline; chevo's `core_relationship_{checkpoint,session,output_proof,batch}` are a different, narrower mechanism (open question 8). Mapped read-only with DBAL writes, as chevo's relationship tables. | 2-10/0-1 each | log | live |
| `synth_meta.relationship_actor_control_events` | none | drop | | 0/0 | | dead |
| `synth.relationship_eval_queue`, `relationship_init_queue` | `core_relationship_eval_queue`, `core_relationship_init_queue` | new | `ext/relationship_system` queues; open question 9. | 3/0 each | log | legacy-live |
| `synth_meta.narrator_definitions`, `narrator_definition_revisions` | `core_narrator_definition` | `~` | chevo: `(playthrough_id, narrator_id, definition_id, settings json)`. SYNTH's `name`, `personality`, `style`, `background`, `voice` move into `settings`; revisions become `definition_id` generations. | 2/0, 2/0 | npc | live |
| `synth_meta.narrator_transcript_entries`, `narrator_transcript_cursors` | `core_narrator_transcript` | `~` | chevo: `(playthrough_id, narrator_id, entry_id, parent_id, role, body)`. | 1/0 each | log | live |
| `synth_meta.narrator_states`, `narrator_lane_heads`, `narrator_context_states` | `core_narrator_checkpoint` | `~` | chevo: `(playthrough_id, token, parent_token, operation_id, session_id, action, input_digest, manifest json)`. SYNTH's `applied_revisions`, `persona_heads` map into `manifest`. | 2/0, 2/0, 1/0 | log | live |
| `synth_meta.narrator_pending_outputs` | `core_narrator_generation` | `~` | chevo: `(playthrough_id, operation_id)` claim. SYNTH's pending text and receipt state need extra columns. | 4/0 | log | live |

### 3.7 Rechat, actions, social tasks

| SYNTH table | New name | Notes | Files | Vol | Status |
|---|---|---|---|---|---|
| `rechat_chains`, `rechat_continuations`, `rechat_selections` | `core_rechat_chain`, `core_rechat_continuation`, `core_rechat_selection` | SYNTH-only; chevo has `chain_id` on events only. | 11-12 each | log | live |
| `action_intents`, `action_terminal_results` | `core_action_intent`, `core_action_result` | SYNTH-only. | 8, 7 | log | live |
| `social_task_inputs`, `_claims`, `_events` | `core_social_task_input`, `_claim`, `_event` | SYNTH-only follower errands. | 1 each | npc | live |

### 3.8 Playthroughs and snapshots

| SYNTH table | chevo table / new name | Match | Notes | Files | Status |
|---|---|---|---|---|---|
| `synth_meta.playthrough_profiles` | `core_playthrough`, `core_save_point`, `core_timeline_control`, `core_snapshot` | `~` / new | chevo has the playthrough (`playthrough_id guid, active, last_saved_gamets, last_loaded_gamets, player_background, background_revision`), save points `(playthrough_id, gamets)` and a timeline control row. `core_snapshot` is new and paused ([section 9](#9-snapshots)). | 4/1 | snap |

### 3.9 Totals

121 relations (115 tables, 6 views) become **about 101 tables and no views**, counted from the tables above: settings and connectors 8, characters 10, catalogs 19,
timeline/dialogue/queue/logs 14, memory/middle term/diaries 20, relationships and narrator 18, rechat/actions/social 8, playthroughs/snapshots 4.
Where chevo has the concept (`=` or `~`) the table takes chevo's name; the rest are SYNTH-only `core_*` tables. Four tables are dropped outright and about 20 are merged away.
The exact figures are fixed by the Phase 2 baseline and then carried by the schema-check manifest.

---

## 4. Entities and DAOs

Each domain gets, per table family, one `Abstract<Name>` mapping, one system class and one DAO, following chevo. SYNTH's existing
`core/dbmanager` already has an `EntityManagerFactory`, a `SharedSessionDriver` (the DBAL driver that shares the legacy PostgreSQL
session so a DAO can run inside a legacy-owned transaction), `entities/GeneralSetting` and six repositories. Their fate:

| SYNTH today | After |
|---|---|
| `EntityManagerFactory`, `SharedSessionDriver` | Kept until the last legacy `$db->` call is gone (step 8), then removed; `Database::boot()` wraps them. |
| `entities/GeneralSetting`, `repositories/SettingsRepository` | Become `AbstractConfigSetting`, `ConfigSetting`, `ConfigSettingDAO`. |
| `repositories/ApiBadgeRepository` | Superseded by #62's `ApiBadge` facade (system entity + `ApiBadgeDAO`) over both stores; the shared store has no ORM mapping (another database), and any mapping of the local custom-key table is #62's ([section 7](#7-api-keys)). |
| `repositories/CharacterRepository`, `UtteranceRepository`, `PrivateNarratorRepository`, `SaveRepository` | Replaced by `NpcDAO`/`NpcActorDAO`, the context-event, dialogue-request and queue DAOs, the narrator DAOs, and `PlaythroughDAO`. |

Approximate class counts (about 101 tables): 100 mapped `Abstract*`, 75 system classes, 60 DAOs. Tables with atomic claims, leases or append-only
semantics (`core_worker_job`, `core_request_execution`-style claims, protocol and narrator tables) get `readOnly: true` mappings and keep every write in
DBAL inside the DAO, exactly as chevo does for `core_relationship_*`. Top-level code (API, `lib`, UI, drivers, `service/`) switches to the system
classes; the 194 files that use `$db->`/`$GLOBALS['db']->` are converted domain by domain. `lib/postgresql.class.php` stays only as the legacy session owner
until step 8.

---

## 5. Drop list

Evidence is `git grep` on `origin/unstable` outside `debug/db_updates.php`, the migrations and the generated baseline files.

| Relation | Evidence it is not needed | Replacement |
|---|---|---|
| `synth_meta.relationship_actor_control_events` | **0** PHP files name it; only the schema SQL creates it. `relationship_actor_controls` is read, never this table. | `revision` on the controls row. |
| `synth_meta.memory_batch_control_events` | **0** PHP files except the readiness list (`runtime_bootstrap.php:129`). | `memory_batch_controls.revision`. |
| `synth.moods_issued` | One write (`chat_helper_functions.php:2126`) and a rollback delete; no `SELECT`. | none. |
| `synth.audit_memory` | Five inserts in `data_functions.php`; no `SELECT`, no UI. | none. |
| views `combined_core_action`, `combined_descriptions`, `combined_bio_templates`, `memory_v`, `worldknowledge_effective`, `synthnpcs` | Replaced by merging in the system classes, as chevo does. | system entity methods. |
| `synth.general_settings`, `conf_opts`, `synth_meta.settings`, `core_player`, `core_narrator`, `core_tts_pronunciation` | Merged into `core_config_setting`. | [section 3.1](#31-api-keys-connectors-profiles-settings). |
| `synth.eventlog` + `history_events`, `speech`, `actions_issued`, `diarylog` (maybe) | Merged into `core_context_event` (and `core_memory`). | [section 3.4](#34-timeline-dialogue-queue-logs-the-legacy-herikaserver-tables). |
| `synth.log`, `synth.audit_request` | Merged into `core_llm_request_log`. | same. |
| `synth.database_versioning` (47 version floors) | Floors were upgrade gates for the legacy chain. | `core_catalog_version` for content only. |
| schema `plugins` | Created empty; no table or function lives in it. | none. |
| functions `clone_schema`, `drop_schema_safe`, `get_schema_size`, `sync_schema_sequences` | Only `lib/playthrough_schema.php` and `lib/runtime_bootstrap.php`; already refused while snapshots are paused. | [section 9](#9-snapshots). |
| `synth.core_api_badge` (today's mixed local table) | Replaced by shared `api_keys` (default) and a local table for **custom keys only**, both owned by #62. | `ApiBadge` facade. |

Functions kept: `synth.convert_gamets2*` (6, used by 9 files) become `public` helpers; `json_object_has_unique_keys` is kept for the protocol `CHECK`s.

---

## 6. Naming

**Choice: chevo's own names, in `public`, with the `core_` prefix; no invented `synth_*` prefix.**

- chevo's `PLAN.MD`: "`core_` identifies tables using this mapped Abstract entity, business entity and DAO approach ... Do not replace
  `core_` with `chim_`." The same applies to a SYNTH rename. So the earlier `synth_<domain>_<noun>` scheme is withdrawn.
- Singular nouns (`core_book`, `core_memory`, `core_location`, `core_context_event`) are chevo's majority style; the plural exceptions
  (`core_profiles`, `core_plugins`, `core_extensions`) are kept where SYNTH maps to those. SYNTH-only tables use the singular.
- A SYNTH table is named `core_*` only if it gets the three-layer mapping. A table deliberately outside the pattern must carry another name and document
  its owner; none is proposed.
- One schema removes the `search_path` dependence of unqualified `$db->insert('eventlog', ...)`.
- Domain grouping by name prefix (`core_diary_*`, `core_middleterm_*`, `core_relationship_*`, `core_narrator_*`, `core_memory_*`) mirrors chevo's
  (`core_npc_*`, `core_oghma_*`, `core_location_*`, `core_relationship_*`).

---

## 7. API keys

**Frozen contract (Q16, settled with the #62 session).** Key storage is owned by PR #62 (`codex/shared-api-keys`); this section is the contract SYNTH's schema and step 1 build on. It supersedes the earlier two-column
proposal (`shared_key_id` / `custom_key_id`).

### Two stores, one list

| Store | What it holds | Id | Reference |
|---|---|---|---|
| **shared** (default) | Global provider keys in the shared `dwemer` database's `api_keys` table (#62's `SharedApiKeyStore` and its four byte-identical shared migrations). Another database, no ORM mapping, own DBAL connection. | `uuid` | `shared:<uuid>` |
| **local** | SYNTH-only custom keys the user creates explicitly (Player2, local services, custom endpoints, region-specific Azure). The table **`core_api_badge`** stays in SYNTH's database: `id integer` PK, `label` **UNIQUE**, `api_key text`. | `integer` | `local:<int>` |

`core_api_badge` therefore keeps its chevo-compatible shape and is created by the baseline as today, but holds **only** local keys; shared keys are never copied into it and local keys never into the shared store.
Nothing switches between stores silently.

### Classes (frozen names)

| Class | Path | Role |
|---|---|---|
| `Synth\Core\Contracts\ApiKeyRef` | `core/contracts/ApiKeyRef.php` | Value object for a key reference: `shared:<uuid>` or `local:<int>`. An id can never be read as the other store's. |
| `ApiBadge` | `core/entities/system/ApiBadge.php` | **Read view** of one key from either store (store, id, label, provider, `configured`); never carries the secret to callers that only list. |
| `ApiBadgeDao` | `core/dbmanager/dao/ApiBadgeDao.php` | The facade over both stores: `listAll()`, `find(ApiKeyRef)`, `findByLabel()`, `defaultForProvider()`, `secret()`, `create()`, `update()`, `delete()` returning `deleted` / `in_use` / `missing` / `unavailable`, and `storeStatus()`. |
| `ConnectorReferences` | `core/dbmanager/dao/ConnectorReferences.php` | `usedBy(ApiKeyRef)` and `forSharedKey(uuid)`: which connectors use a key, for strict Delete. |

Naming note: the DAO is `ApiBadgeDao` (not chevo's `ApiBadgeDAO`) and the contract class lives in a new `core/contracts` directory; these are #62's names and are kept. The facade is not a registered `Database::DAOS` entry for the shared store
(no EntityManager behind it); whether the local table also gets an `AbstractApiBadge` mapping is #62's call and does not change the contract.

### Connector binding (the schema consequence)

Each of `core_llm_connector`, `core_tts_connector`, `core_stt_connector` and `core_itt_connector` gets two nullable key columns and one check:

```
api_key_id        uuid    NULL   -- shared key (api_keys.key_id); no FK (another database)
local_api_key_id  integer NULL   -- REFERENCES core_api_badge(id) ON DELETE RESTRICT; this is today's api_badge_id, renamed
CHECK (num_nonnulls(api_key_id, local_api_key_id) <= 1)       -- at most one of the two
```

- **Both NULL means "use the provider's global default"** (`ApiBadgeDao::defaultForProvider()`), not "no key". A local key is used only when `local_api_key_id` is set, which only an explicit user edit does.
- The existing `api_badge_id` is **renamed** `local_api_key_id` (same integer values, same FK); chevo's single `api_badge_id integer` therefore diverges, so entities, editors, `ConnectorReadiness` and the edit API's `references` entry name both columns.
- Moving a connector between stores is an explicit edit that sets one column and clears the other in one write; the facade never does it.
- **Strict Delete:** `ApiBadgeDao::delete()` returns `in_use` while `ConnectorReferences::usedBy()` finds a connector (a local key is also protected by `ON DELETE RESTRICT`; a shared key only by this check), `missing` for an unknown id, and `unavailable` when the shared store cannot be reached.
  An unreachable shared store reads as "no key" for shared-bound connectors and never falls back to a local key.

### Behaviour kept from #53/#54

Implemented through the facade for both stores: **write-only keys** (never returned; only `configured`), **blank preserves**, **Clear** is explicit, **server-side Test**, **strict Delete**. The edit API, when added, exposes
`'api-badge' => ['write_only' => ['api_key']]` as in chevo. The shared migrations stay outside SYNTH's baseline manifest (they keep `shared-schema-manifest.json`); `core_api_badge` is in SYNTH's database, so it is in the baseline and the schema-check.

### Dependency and ordering

Step 1 starts only on the command centre's go, after #62's rework lands. Step 1 then adopts the contract above as frozen; any rename in #62 beyond it needs a design update first.

---

## 8. Schema check

chevo has no schema-check, so there is nothing to adopt; SYNTH keeps and retargets its own (`tools/schema-check.php`,
`core/dbmanager/SchemaCheck.php`, `migrations/schema-manifest.json`). Changes:

- Scope becomes **`public` relations whose name starts with `core_`** (plus the two `public` functions), instead of the `synth` and `synth_meta` schemas; the check
  still renders under one `search_path`, reads in one `READ ONLY` transaction and ends in `ROLLBACK`.
- The migration ledger stays `doctrine_migration_versions` in `public` (ChimServer-style, not chevo-specific); chevo does not ship its own ledger convention beyond
  Doctrine's default.
- `<Version>.structure.txt` is regenerated from the disposable PG17 baseline; `vector` typmods and indexes of DAO-only columns remain in the structure so
  they stay frozen even though the ORM does not map them.
- The manifest stays format 1, one entry per migration; the optional move to per-migration hashes over a catalog snapshot is deferred and not required here.
- Exit codes, `--offline`, `--hashes` and the fixed-message error policy are unchanged.
- A new PHPUnit test (kept small) checks that every `#[ORM\Table(name: 'core_*')]` in `core/entities/base` exists in the manifest, so an entity cannot be
  added without its table and structure line.
- Mapping validation is **separate** from schema output (chevo rule): never `doctrine:schema:update`; real migrations build the test database, not an
  ORM-generated schema that omits checks, cascades and DAO-only columns.

---

## 9. Snapshots

Snapshots stay **feature-paused**: nothing here re-enables `timeline_break_create_snapshot`, `pts_clone_schema` or the playthrough UI. Existing refusals and their tests stay.

chevo does not clone schemas: it records `core_save_point(playthrough_id, gamets)` and `core_npc_history(character_id, saved_gamets, state)` and
restores NPC state on load (`managers/SaveState`). With one `public` schema, cloning is impossible anyway. The redesign therefore:

- keeps the paused snapshot feature as a **row-scoped** design: `core_snapshot (snapshot_id bigint identity PK, playthrough_id, reason text, format text, schema_major int,
  migration_versions jsonb, manifest_sha256 char(64), table_counts jsonb, size_bytes bigint, last_gamets bigint, state, created_at)`, plus row storage chosen under open question 10;
- stores the version metadata D6 needed (`format`, `migration_versions`, `manifest_sha256`) so `SchemaMigrations::restoreSnapshot()` can refuse a mismatch;
- records per-save tables as a constant checked by a test against the manifest (those with `playthrough_id` or `gamets`);
- offers the alternative of adopting chevo's save-point model wholesale and retiring snapshots (open question 10).

---

## 10. UI and the edit API

SYNTH's `ui/` has about 73 files that use `$db->`, of which about 45 hold raw SQL (`FROM`/`INSERT`/`UPDATE` strings); they read and write tables directly,
which is what chevo forbids ("Top-level code never calls a DAO ..."). chevo's UI is a Slim/Twig app at `/ui/` that works only through `/api/v1/edit`
(`api/v1/editing/index.php`, `EntityCatalog`, `EntityEditor`, `ConnectorReadiness`, `NpcProfileRuleValues`), describing each entity so forms build from `GET /api/v1/edit`.

- **In each domain step (Phase 2):** the UI's raw SQL for that domain moves into system-entity methods; pages keep their present PHP rendering. This is required for the table to leave
  its old name.
- **Later phase (separate PR, not part of this redesign):** port `core/dbmanager/Editor/*` and `api/v1/editing` to SYNTH, register `EntityCatalog::ENTITIES` per table (writable, `write_only`,
  `references`, read-only for internal records), then convert pages one by one to call `/api/v1/edit`, replacing the per-page JSON endpoints in `ui/api`. Size: about 10 new files for the editor
  plus a conversion of about 45 pages. It is large and depends on every domain being on entities, so it follows step 8.
- Internal records (protocol events, checkpoints, leases, proofs) are **never** exposed in the catalog, per chevo.

---

## 11. Rollout order

The baseline cannot be both replaced and kept running in one commit, so Phase 2 regenerates the **single** baseline at every step: new `public.core_*` tables for the domains done,
the old `synth`/`synth_meta` tables verbatim for the rest. Because `search_path` is `synth, synth_meta, public` during the transition, a name that moves to `public` is removed from `synth` in
the same commit. Every commit installs on an empty disposable PG17, passes `schema-check` and the suite, and runs. The final step deletes the last verbatim legacy block. All work stays on
`codex/schema-redesign`; each step is a commit. Counts are production files; tests add roughly 30 %.

| Step | Scope | Prod files (est.) | Notes / risk |
|---|---|---|---|
| 0 | Scaffolding: `Database::boot()`, `Database::DAOS`, `Types/PreciseDateTimeType`, `core/entities/{base,system}` and `core/dbmanager/dao` layout (chevo conventions), new baseline id as byte-equivalent old DDL, schema-check retargeted ([section 8](#8-schema-check)), `DatabaseTestCase` builds the fresh baseline instead of `database_default.sql` plus the chain. No table renamed. | ~18 | Proves boot, fresh install and drift. Must keep `SharedSessionDriver` working with the ORM. |
| 1 | **Starts only on the command centre's go, after #62's rework lands; the key facade contract is frozen ([section 7](#7-api-keys)).** Connectors, profiles, settings: `core_*_connector` (`api_badge_id` renamed `local_api_key_id`, `api_key_id uuid` added, at-most-one CHECK; both NULL = provider default), `core_api_badge` kept as the local key table behind `ApiBadgeDao`, `ApiKeyRef`, `ApiBadge`, `ConnectorReferences`; `core_profiles` (+`itt_connector_id`), `core_config_setting`, `core_config_preset`, `core_prompt`; remove `SettingsRepository`, `GeneralSetting` (`ApiBadgeRepository` goes with #62's facade); #53/#54 behaviour retained. | ~80 (`conf_opts` ~34, `core_profiles` 28, `core_llm_connector` 19, `core_player` 14) | Largest UI fan-out; moves the UI SQL for these tables into the system classes and the facade. |
| 2 | Characters and plugins: `core_npc`, `core_npc_actor`, `core_npc_history`, `core_npc_profile_rule`, `core_npc_biography_*`, `core_plugins`, `core_character_generation*`. | ~65 (`actor_identities` 38, `npc_master` 38) | Identity model decision (question 3) lands here. |
| 3 | Catalogs: items, oghma/worldknowledge, locations + state, factions, quests, actions, catalog versions, media, visual. | ~60 | Installers `fallout_catalogue_sql.php`, `fallout_content_pack.php`. |
| 4 | Context, queue, logs: `core_context_event` (merging `eventlog`, `history_events`, `speech`, `actions_issued`), `core_message_queue`, `core_llm_request_log`, `core_dialogue_request`. Commits 4a events, 4b queue/log, 4c requests. | ~110 (`eventlog` ~40, `history_events` 31, `request_executions` 45, `speech` ~34) | Highest risk: merges two event ledgers; native dialogue tests, loopback. `save_rollback.php`, `legacy_row_management.php`. |
| 5 | Runtime/protocol: `core_runtime_session`, `core_runtime_context`, `core_protocol_event`, `core_actor_*`, `core_dialogue_*`, `core_worker_job`. | ~85 (`context_snapshots` 43, `runtime_sessions` 41, `protocol_events` 41) | |
| 6 | Memory, middle term, diaries. | ~40 | Vector columns DAO-only. |
| 7 | Relationships, narrator, rechat, action intents, social tasks, queues. | ~70 | Read-only mappings, DBAL writes. |
| 8 | Playthroughs/snapshots ([section 9](#9-snapshots)); delete the last legacy block, `lib/core/database_schema/*.sql`, the frozen `debug/db_updates.php` chain, `data/database_default.sql`, `SharedSessionDriver`; update `README.md`, `CONTRIBUTING.md`, `docs/SCHEMA-MIGRATIONS.md`, `docs/HANDOFF.md`; final search proving no old name remains. | ~30 | **Deletes whole files: needs the owner's approval (AGENTS.md).** |
| later | Edit API and UI conversion ([section 10](#10-ui-and-the-edit-api)). | ~10 new + ~45 pages | Separate PR. |

Total about **550 prod-file edits across roughly 140 distinct files** plus about 60 test files, and about 230 new entity, system and DAO classes (about 100 + 75 + 60). Every step ends with:
`php -l` on changed files, the PHPUnit suite on isolated PG17, `schema-check` match on a fresh install, the dashboard HTTP probe, the credential harnesses (step 1 onward) and a name search for the removed names.
Scratch under `D:\build\synth-schema-redesign`.

---

## 12. Risks

1. **ORM against a shared legacy session.** SYNTH's DAOs ride `SharedSessionDriver` on the legacy `pg_*` session; flushes inside legacy transactions or advisory-lock windows need explicit tests. chevo's rule applies: no inner flush, atomic SQL stays DBAL.
2. **Merging `eventlog` and `history_events`** (step 4) changes semantics, rollback pruning by `gamets` and every native subsystem that points at `history_events.id`; it needs its own review.
3. **Identity mismatch** between SYNTH's playthrough-scoped bigint actors and chevo's global `character_id` plus `(refid, plugin)` ([question 3](#13-open-questions)).
4. **Fallout vs Skyrim.** Oghma naming, plugin load-order fields and S.P.E.C.I.A.L.-style profile fields differ; columns SYNTH needs that chevo lacks are added to the chevo-shaped table, not forked.
5. **Volume of classes.** About 235 new classes under chevo's documentation rules (every file and method commented) is large; the DAO/system split is mechanical but must be reviewed in batches.
6. **ORM-generated DDL.** The Doctrine schema tool omits checks, cascades, generated and vector columns; never use it to create or diff (chevo rule). Tests use real migrations.
7. **Divergence from chevo.** `core/dbmanager` is a copy, not a shared package, so chevo changes must be ported by hand; unresolved in question 11.
8. **Key facade dependency.** Step 1 waits for #62's rework and the command centre's go. The contract is frozen, but the two-column binding diverges from chevo's single `api_badge_id integer`, so editors and `ConnectorReadiness` ported from chevo must split on it; resolving a key from the wrong store would be a credential mix-up, so the harnesses test both bindings, the both-NULL provider default and the no-fallback rule.
9. **Test fixture.** 111 test files, about 30 with per-table assertions; each step edits only its domain's.

---

## 13. Open questions

For Stu (CHIM 4 / chevo owner). **Questions 1 to 8: user decision (2026-11-17), chevo-aligned defaults; Stu may revise.** The defaults below are used instead of waiting for Stu, so no rollout step is blocked on these questions:

1. **Copy chevo's `core/dbmanager` layout and `Database` class; no shared package.**
2. **`core_context_event` stays as chevo has it.** SYNTH's extra fields (`delivery_state`, `synth_*` correlation) go in `context_metadata`.
3. **Identity: adopt chevo's `core_npc.character_id` plus `core_npc_actor(refid, plugin)`.** `playthrough_id` goes on events only.
4. **`playthrough_id` is a `guid`.**
5. **World knowledge uses neutral names, `core_worldknowledge_*`.**
6. **`diarylog` folds into `core_memory` with `source_type='diary'`.**
7. **A `core_prompt` table is acceptable.**
8. **Keep SYNTH's `core_worker_job` leases and evaluation pipeline; do not adopt `core_worker_unit` yet.** A parallel session builds a storage-agnostic `workers/` framework, so the worker tables stay behind a seam.

**Legacy upgrade chain (decided 2026-11-17): retire per step.** When a step moves a table out of `synth`/`synth_meta`, the legacy-chain tests that exercise upgrades through that table are retired in the same commit (there is no upgrade path, owner decision). The frozen `debug/db_updates.php` itself is deleted in step 8; until then it is not run against converted tables.

The original questions follow for the record.

1. **Is copying chevo's `core/dbmanager` layout and `Database` class right, or will they become a shared package?** (risk 7)
2. **`core_context_event`:** may SYNTH extend it with the few columns it needs (`delivery_state`, `synth_*` correlation) or should they live in `context_metadata`?
3. **Identity:** keep SYNTH's playthrough-scoped actor id, or adopt `core_npc.character_id` + `core_npc_actor(refid, plugin)` with `playthrough_id` on events only?
4. **`playthrough_id`:** adopt chevo's `guid` or keep SYNTH's `text`?
5. **Naming for knowledge:** chevo's `core_oghma_*` is Skyrim lore; keep that table name for SYNTH's world knowledge, or a neutral name (such as `core_worldknowledge_*`)?
6. **Legacy diary:** fold `diarylog` into `core_memory` (`source_type='diary'`) or keep `core_diary_legacy_entry`?
7. **Prompts:** does chevo store editable prompt text in a table anywhere? If not, is a `core_prompt` table acceptable?
8. **Workers and relationships:** is chevo's derived-from-data `core_worker_unit` and narrower relationship model intended to replace SYNTH's `core_worker_job` leases and evaluation pipeline?

Answers from the owner (recorded for the approved design at `9bcdbdf`):

9. **`ext/relationship_system` and its queues: kept.** Nothing is dropped there; `core_relationship_eval_queue` and `core_relationship_init_queue` stay.
10. **Snapshots: adopt chevo's save-point model and retire SYNTH's row snapshots.** The model is designed in step 8; snapshots stay paused until then, and `core_snapshot` (section 9) is no longer planned.
11. **Legacy `memory`, `memory_summary`, `diarylog` path: retired,** as its own clearly separated commit within step 6, because it is a behaviour change.
12. **Approved to delete in step 8:** `debug/db_updates.php`, `lib/core/database_schema/*.sql`, `data/database_default.sql` and `SharedSessionDriver`.
13. **Approved to drop** `moods_issued` and `audit_memory`.
14. **Baseline id `Version20261117000000` is approved** (and used by step 0).
15. **The edit API and UI conversion go in a later, separate PR;** each step still moves its UI SQL into system-entity methods.
16. **#62 facade contract: settled and frozen** (section 7): `ApiKeyRef` (`shared:<uuid>` / `local:<int>`), `ApiBadge`, `ApiBadgeDao`, `ConnectorReferences`, connector columns `api_key_id` / `local_api_key_id`.

Out of scope and unchanged by this design: the installed database, deployments, services, the game, legacy snapshots (stay paused), PR #41, ChimServer, HerikaServer, Stobe, Dialectic and chevo.
