# Scoped settings (registry, resolution, `explain()`)

**Status: Phase A, design only. Nothing here is implemented.** No registry, table, entity, DAO or caller changes with
this document. Phase B is implemented on `codex/schema-redesign` ([PR #63](https://github.com/Dwemer-Dynamics/SynthServer/pull/63),
`docs/SCHEMA-REDESIGN.md` on that branch) only after its step 1 (connectors, profiles, settings) has landed there.

Ported from the CHIM Evolution (chevo) `ConfigManager` (`managers/ConfigManager/{README.md,PLAN.md,ConfigManager.php}`,
chevo PRs #269 and #272, read-only at `8ebe86e`). Ideas are ported, not files.

## 1. Problem today

SYNTH has three settings mechanisms that agree only by convention:

| Mechanism | Where | Weakness |
|---|---|---|
| Runtime globals | `conf/conf.php` (from `conf.sample.php`), then `$GLOBALS` | Untyped, mutated per request, no record of where a value came from. |
| Server-wide key/value | `synth.general_settings` (`id`, `value text`, `description`), read by `SettingsRepository` / `GeneralSetting` (Doctrine, read-only pilot), written by `synthSetGeneralSetting()` | Everything is a string; booleans are parsed by `synthSettingsNormalizeScalar()` from the JSON schema type; keys are `UPPER_SNAKE` or `A@B@C` for nested. |
| Overrides | Profile `core_profiles.metadata` (applied by `CoreProfile::setOldGlobals()`), NPC row (applied by `NpcMaster::setOldGlobalsFromCurrentNpcData()`) | Keys are the legacy global names, values are unvalidated JSON. Applied by assigning `$GLOBALS`, skipping `null` and `''`, so an explicit blank or a stored `false`/`0` is treated unevenly. |

Which keys may be overridden is not declared anywhere. `synthGetOverrideableGeneralSettingsCatalog()` derives it at
request time from `synthGetManagedGeneralSettingIds()` plus whatever rows exist, minus `synthGetGlobalOnlySettingIds()`
and a profile-native list. `conf/conf_schema.json` has a `scope` attribute on 104 of its 332 leaves (102 `global`,
2 `profile`); the other 228 carry none, and 187 of those are connector, TTS/STT/ITT driver and `FEATURES` blocks that are global in fact.
`synthDescribeInheritedSettings()` already answers "what would this override replace" for the editor, but it is a
second implementation of the runtime order and can drift from it.

## 2. Goals and non-goals

Goals:

1. Declare each setting once: key, type, range, default, allowed scopes, description, legacy alias.
2. One resolver, **Global → profile → NPC**, narrowest valid value wins; invalid overrides are skipped, not applied.
3. `explain()` reports value and source, so the editor, the dashboard and logs share one answer.
4. Typed JSON storage in `core_config_setting` (chevo's shape), replacing the string table.
5. Dashboard pages read and write only through the registry, and reject unknown keys, wrong scopes and invalid values.

Non-goals (Phase B or later): moving connector selection (`core_profiles.*_id`) into settings, rewriting every
`$GLOBALS['X']` reader (a compatibility projection keeps them, [section 8](#8-rollout)), prompt-text editors, snapshots,
and in-game behavior. Provider endpoint/driver blocks (`CONNECTOR.*`, `TTS.*`, `STT.*`, `ITT.*`) stay in the connector
tables and are **not** registry settings.

## 3. Registry format

One PHP class, `ConfigManager` (chevo's name; it lives beside the other flat manager classes as
`managers/ConfigManager.php`). Each setting is declared once in a `SETTINGS` constant, with a public string constant for the key:

```php
public const CONTEXT_HISTORY = 'context.history';

private const SETTINGS = [
    self::CONTEXT_HISTORY => [
        'type'        => 'int',
        'range'       => [1, 500],
        'default'     => 50,
        'scopes'      => self::EVERY_SCOPE,      // global | profile | npc
        'legacy'      => 'CONTEXT_HISTORY',      // UPPER_SNAKE or A@B@C name, for aliases and the projection
        'description' => 'Past dialogue and events sent to the LLM.',
        'label'       => 'History size',         // UI; optional, falls back to a title-cased key
        'category'    => 'context',              // UI grouping; defaults to the key's first part
    ],
];
```

Rules, all from chevo:

- **Keys** are dotted lowercase; the first part is the group (`context`, `dialogue`, `memory`, `worldknowledge`, `rechat`,
  `prompt`, `relationship`, `llm`, `vision`, `player`, `server`). A key is never reused for a different meaning.
- **Types** and their validators (`validateValue()`, one `if` per type, no nested ternaries): `bool` (JSON `true`/`false` only on write),
  `int` with `range`, `float` with `range`, `enum` with `values`, `text` and `multiline_text` with a length cap and a control-character
  check, `url` (http/https only, trailing `/` removed), `connector_id` (integer `>= 0`, checked against the connector table; `0` means none),
  `list` (JSON array of strings, with item cap), plus a few named types where a closed set is needed (`rechat_mode`, `history_format`).
- **Scopes** are a subset of `global`, `profile`, `npc`. `global` is always present. A setting without `profile`/`npc` is global-only
  and a stored override for it is ignored (and reported by `metadataWarnings()`).
- **Defaults** are the default in the registry, not in `conf.sample.php`. `conf.sample.php` stays the source of installation values that
  the first-run seeding copies into `core_config_setting` ([section 8](#8-rollout), step 3); a missing row afterwards resolves to the registry default.
- **Unknown keys are rejected**; there are no silent fallback settings.
- `definitions()` returns each entry plus a JSON Schema, which is what the dashboard builds its forms from, and what
  `conf/conf_schema.json` entries are generated from (the schema file stops being hand-maintained for registry keys).
- The class has no dependency on `$_GET`, `$_POST`, `$GLOBALS` or HTTP.

### Legacy aliases

`normalizeKey()` accepts the old name (`CONTEXT_HISTORY`, `FEATURES@MEMORY_EMBEDDING@ENABLED`, and the space-separated
form `synthApplyOverrideValueToGlobals()` accepts) and returns the dotted key, case-insensitively. Stored rows are renamed once, at the
baseline seeding step, so aliases are only needed for old profile/NPC metadata, saved presets and third-party code. A dotted key wins
over its alias when both are present in one object.

## 4. Initial key list

Source: `conf/conf.sample.php`, `conf/conf_schema.json`, `synthGetManagedGeneralSettingIds()` and the profile fields of
`core_profiles`. "Scopes" uses **G** global, **P** profile, **N** NPC. A **P N** entry here means "overridable today": it is in
the managed list or has no `scope` in the schema and is applied from profile or NPC metadata. Entries marked `?` are overridable
only because nothing forbids it today; Phase B step 1 reviews each one before it keeps P/N. The list is the starting registry; it is
not frozen until Phase B.

### Context and prompt

| Key | Legacy | Type | Default | Scopes |
|---|---|---|---|---|
| `context.history` | `CONTEXT_HISTORY` | int 1-500 | 50 | G P N |
| `context.history_diary` | `CONTEXT_HISTORY_DIARY` | int 0-500 (0 = use `context.history`) | 100 | G P |
| `context.history_dynamic_profile` | `CONTEXT_HISTORY_DYNAMIC_PROFILE` | int 0-500 | 50 | G P |
| `context.history_clean_focus` | `CLEAN_CONTEXT_FOCUS_CHAT_HISTORY` | int 0-500 | 25 | G |
| `context.compact_npc_history` | `COMPACT_NPC_CONTEXT_HISTORY` | bool | true | G |
| `context.short_term_memory_in_compact` | `SHORT_TERM_MEMORY_IN_COMPACT_CHAT` | bool | true | G |
| `context.timestamps` | `PROMPT_TIMESTAMP` | bool | false | G |
| `context.event_type_filter` | `EVENT_TYPE_FILTER` | list | `[]` | G |
| `context.hide_ambient_combat` | `HIDE_AMBIENT_COMBAT` | bool | false | G |
| `context.location_blacklist` | `LOCATION_BLACKLIST` | list | `[Glowing Sea, Mass Fusion Building]` | G |
| `context.item_blacklist` | `ITEM_BLACKLIST` | list | `[]` | G |
| `context.nearby_items.shorter_list` | `SHORTER_NEARBY_ITEM_LIST` | bool | false | G |
| `context.nearby_items.descriptions_only` | `GROUND_ITEMS_DESCRIPTIONS_ONLY` | bool | false | G |
| `context.inventory.descriptions_only` | `INVENTORY_ITEMS_DESCRIPTIONS_ONLY` | bool | false | G |
| `prompt.head` | `PROMPT_HEAD` | multiline_text | sample text | G P N (NPC `prompt_head` field wins as today) |
| `prompt.markdown_headings` | `PROMPT_HEAD_MARKDOWN_ENABLED` | bool | true | G |
| `prompt.context_sections` | `PROMPT_CONTEXT_OPTIONS` | json (section → bool) | stock map | G (global only by decision) |
| `prompt.emote_moods` | `EMOTEMOODS` | list | stock list | G P N |
| `prompt.summary` | `SUMMARY_PROMPT` | multiline_text | sample | G |
| `prompt.diary` | `DIARY_PROMPT` | multiline_text | sample | G |
| `prompt.dynamic_profile.<field>` (5) | `DYNAMIC_PROMPT_*` | multiline_text | sample | G |
| `prompt.enforce_actions` | `ENFORCE_ACTIONS_PROMPT` | bool | false | G (the profile loader forces it false today; it stays global-only) |
| `prompt.json_reorder` | `JSON_DIALOGUE_FORMAT_REORDER` | bool | false | G ? |

### Dialogue, rechat, comments

| Key | Legacy | Type | Default | Scopes |
|---|---|---|---|---|
| `dialogue.max_words` | `MAX_WORDS_LIMIT` | int 0-500 (0 = off) | 0 | G P N |
| `dialogue.http_timeout` | `HTTP_TIMEOUT` | int 5-300 s | 15 | G ? |
| `dialogue.animations` | `SYNTH_ANIMATIONS` | bool | true | G P ? |
| `dialogue.inline_narration` | `INLINE_NARRATION_MODE` | enum `disabled narrator npc text_only` | disabled | G (narrator-managed today; stays out of profile/NPC, as `setOldGlobals()` skips it) |
| `dialogue.strip_asterisks.player_input` / `.player_autochat` / `.npc_output` | `REMOVE_ASTERISKS_FROM_PLAYER_INPUT` etc. | bool | true | G (narrator-managed; as above) |
| `dialogue.player_respeech` | `PLAYER_RESPEECH` | bool | true | G |
| `dialogue.player_speech_style` | `PLAYER_SPEECH_STYLE` | multiline_text | `''` | G |
| `dialogue.emotions` | `use_emotions_expression` | bool | false | G |
| `rechat.rounds` | `RECHAT_H` | int 0-10 | 2 | G P (profile-native today: `core_profiles` default, kept as the G/P pair) |
| `rechat.probability` | `RECHAT_P` | int 0-100 | 50 | G P |
| `rechat.allow_actions` | `RECHAT_ALLOW_ACTIONS` | bool | true | G P |
| `rechat.mode` | `RECHAT_MODE` | enum `random` + the sample set | random | G P N |
| `rechat.strict_response` | `ENFORCE_STRICT_RECHAT_RESPONSE` | bool | false | G P N |
| `rechat.bored_probability` | `BORED_EVENT` | int 0-100 | 30 | G P |
| `rechat.bored_serverside` | `BORED_EVENT_SERVERSIDE` | bool | false | G |
| `comments.quest.enabled` / `.chance` / `.cooldown` | `QUEST_COMMENT`, `QUEST_COMMENT_CHANCE`, `QUEST_COMMENT_COOLDOWN` | bool / int 0-100 (today a `"10%"` string) / int s | false / 10 / schema | G P N |
| `comments.rpg.events` / `.chance` | `RPG_COMMENTS`, `RPG_COMMENTS_CHANCE` | list of events / int 0-100 | stock / 20 | G P |
| `diary.cooldown` | `DIARY_COOLDOWN` | int s | 120 | G |
| `diary.auto` / `diary.auto_wait` | `AUTO_DIARY_ENABLED`, `AUTO_DIARY_WAIT_ENABLED` | bool | schema | P N (schema `scope: profile` today; no global row, so the registry gives them a G default) |

`QUEST_COMMENT_CHANCE` is a percent string today; the registry stores the integer and the projection re-adds `%`.

### World knowledge, relationships, awareness

| Key | Legacy | Type | Default | Scopes |
|---|---|---|---|---|
| `worldknowledge.amount` | `WORLDKNOWLEDGE_AMOUNT` | enum/int 1-5 | 1 | G P N |
| `worldknowledge.result_limit` | `WORLDKNOWLEDGE_RESULT_LIMIT` | int 1-10 | 3 | G P N |
| `worldknowledge.infinium` | `WORLDKNOWLEDGE_INFINIUM` | bool | true | G P N |
| `worldknowledge.race` / `.faction` / `.location` / `.custom` | `RACE_…`, `FACTION_…`, `LOCATION_WORLDKNOWLEDGE`, `WORLDKNOWLEDGE_CUSTOM` | bool | true / true / true / false | G P N |
| `worldknowledge.extractor.fallback` / `.timeout_ms` | `WORLDKNOWLEDGE_EXTRACTOR_*` | bool / int 100-30000 | false / 1500 | G |
| `relationship.enabled` | `RELATIONSHIP_SYSTEM_ENABLED` | bool | false | G P N |
| `relationship.never_clear_data` | `NEVER_CLEAR_RELATIONSHIP_DATA` | bool | false | G (global-only today) |
| `power_awareness.enabled` | `POWER_AWARENESS_ENABLED` | bool | false | G P N |
| `scene_classifier.enabled` | `SCENE_CLASSIFIER_ENABLED` | bool | true | G |
| `actions.jev.enabled` / `.timeout_ms` / `.min_confidence` | `JEV_ACTIONS_ENABLED`, `JEV_ACTION_TIMEOUT_MS`, `JEV_ACTION_MIN_CONFIDENCE` | bool / int / float 0-1 | false / 350 / 0.8 | G |
| `memory.embedding.enabled` / `.use_text2vec` | `FEATURES@MEMORY_EMBEDDING@ENABLED`, `…@USE_TEXT2VEC` | bool | schema | G |
| `memory.summary.auto` / `.interval` | `…@AUTO_CREATE_SUMMARYS`, `…@AUTO_CREATE_SUMMARY_INTERVAL` | bool / int | schema | G |

### Model routing

Connector choices that are columns of `core_profiles` (`llm_primary_id` … `llm_fallback_id`, `tts_connector_id`,
`diary_connector_id`, `itt_connector_id`) **stay columns**: they are foreign keys to connector tables and chevo keeps them there
([PR #272 body](https://github.com/abeiro/chevo/pull/272) left the profile connector IDs untouched). The `CORE_CONNECTOR_*` globals become global `connector_id` settings:

| Key | Legacy | Scopes |
|---|---|---|
| `llm.director_connector_id` | `CORE_CONNECTOR_DIRECTOR` | G |
| `llm.player_connector_id` | `CORE_CONNECTOR_PLAYER` | G |
| `llm.summary_connector_id` | `CORE_CONNECTOR_SUMMARY` | G |
| `llm.middleterm_connector_id` | `CORE_CONNECTOR_MEDIUMTERM` | G |
| `llm.scene_classifier_connector_id` | `CORE_CONNECTOR_SCENECLASSIFIER` | G |
| `llm.profiles_connector_id` | `CORE_CONNECTOR_PROFILES` | G |
| `llm.worldknowledge_custom_connector_id` | `CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM` | G |
| `llm.decision_connector_id` | `CORE_CONNECTOR_DECISION` | G |
| `llm.relationship_connector_id` | `RELLLM_CONNECTOR` | G |
| `stt.connector_id` | `GLOBAL_STT_CONNECTOR_ID` | G |
| `vision.connector_id` | `GLOBAL_ITT_CONNECTOR_ID` | G (profile `itt_connector_id` column overrides, as chevo) |
| `player.tts_connector_id` / `player.tts_voice` / `player.tts_voice_id` / `player.tts_language` | `TTSFUNCTION_PLAYER*` | G |

`$CONNECTORS` / `$CONNECTORS_DIARY` (the old service lists) are not registry settings; they are connector rows.

### Vision, server and bookkeeping

| Key | Legacy | Type | Default | Scopes |
|---|---|---|---|---|
| `vision.scene_ttl_minutes` | `VISUAL_CONTEXT_SCENE_TTL_MINUTES` | int 1-1440 | 10 | G |
| `vision.prompt_max_chars` | `VISUAL_CONTEXT_PROMPT_MAX_CHARS` | int 200-20000 | 1800 | G |
| `vision.image_quality` / `.request_timeout_s` | `PIPVISION_*` | int | 88 / 60 | G |
| `server.auto_backup` | `AUTOMATIC_DATABASE_BACKUPS` | bool | schema | G |
| `profile.auto_lock` / `profile.autofill_custom` / `profile.autofill_trigger` | `AUTO_LOCK_PROFILE`, `AUTOFILL_CUSTOM_PROFILES`, `…_TRIGGER` | bool / bool / int 10-100 | true / true / 40 | G |
| `player.name` | `PLAYER_NAME` | text (server-set, read-only in the UI) | `Sole Survivor` | G |
| `player.world_name`, `narrator.name` (`SYNTH_NAME`) | `SYNTH_WORLD_NAME`, `SYNTH_NAME` | text | stock | G |

### Not settings

These are **not** registry keys, and stay where they are:

- Per-NPC identity text (`SYNTH_PERS`, `SYNTH_PERSONALITY`, `SYNTH_BACKGROUND`, `SYNTH_APPEARANCE`, `SYNTH_OCCUPATION`, `SYNTH_SKILLS`,
  `SYNTH_SPEECHSTYLE`, `SYNTH_GOALS`, `WORLDKNOWLEDGE` tags, `LOCK_PROFILE`, `DYNAMIC_PROFILE`, `DYNAMIC_PROFILE_FIELDS`): NPC and
  profile content, not behavior. They belong to #63 step 2 (`core_npc`, `core_npc_actor`).
- Bootstrap values that must be known before the database is: `SYNTH_DB_*`, `DBDRIVER`, `SYNTH_GAME_ID`, `ALIVE_MESSAGE`.
  They stay in `conf.php`. The registry never reads `conf.php` for anything else after Phase B step 3.
- Driver blocks `CONNECTOR.*`, `TTS.*`, `STT.*`, `ITT.*` and API keys (PR #62 and the connector tables).

## 5. Resolution rules

For a character `c` and key `k`, the effective value is the first valid one of:

1. `c`'s own value, when `k` allows scope `npc`;
2. the profile value, when `k` allows scope `profile`. The profile is the NPC's `profile_id`, else the **default profile**
   (the row with `default_npc` set, which is what `CoreProfile` already uses; for the narrator, `default_narrator`);
3. the stored global value (`core_config_setting`);
4. the registry default.

Details:

- **Presence, not truthiness.** A key present in an override object counts, so `false`, `0` and `''` are real values (this is the
  change from `setOldGlobals()`'s `!== null && !== ''` check, which silently ignored an explicit blank). Absent key means inherit. There is no
  `null` override: **Inherit removes the key.**
- **Invalid is skipped.** A stored override that fails validation (wrong type, out of range, scope not allowed, unknown key) is skipped and the
  next level decides; `explain()` lists it under `ignored`. Writes are stricter: the edit path rejects an invalid or wrong-scope value outright.
- **Legacy words.** For `bool` the reader also accepts `"1" "true" "on" "yes"` and `"0" "false" "off" "no"` in old metadata. New writes are JSON booleans.
- **Narrator-managed keys** (`dialogue.inline_narration`, the asterisk filters) are global-only in the registry. The existing carve-out in
  `setOldGlobals()` ("skip narrator-managed keys") becomes a consequence of the declared scopes, not a hard-coded list.
- **Scenes and the player's own lines** (no responding NPC) use the global value; the player has no profile.
- **Consistency within a turn.** The resolver reads the NPC owner (npc overrides, profile overrides, profile id) once per instance per
  character, like chevo's `ownerOf()`. A worker that must see a just-committed value uses a `getLocked()` variant that reads the table directly
  inside a transaction, as chevo does for its workers.
- **Cheap reads.** `get()` for the global value is one `core_config_setting` lookup, cached for the request by the DAO and refreshed by any write in the same request.

### API

```php
ConfigManager::get(string $key): mixed                              // global value (stored or default)
ConfigManager::getForCharacter(string $key, int $characterId): mixed
ConfigManager::getForProfile(string $key, ?array $profileMetadata): mixed   // profile editor preview
ConfigManager::isOn(string $key, ?int $characterId = null): bool
ConfigManager::explain(string $key, ?int $characterId = null): array
ConfigManager::set(string $key, mixed $value, ?array $expected = null, bool $present = true): mixed  // global write
ConfigManager::validateMetadata(string $scope, ?array $metadata, ?array $stored): ?array             // profile/NPC writes
ConfigManager::definitions(): array
ConfigManager::normalizeKey(string $key): string                    // alias → dotted key; throws on unknown
```

`$expected` is an optimistic-concurrency token `{present, value}`: the write fails with "changed, reload" when the stored value no longer
matches. `set(..., present: false)` deletes the row (back to the default).

## 6. `explain()`

```json
{"key": "context.history", "value": 25, "source": "profile", "profile_id": 3, "ignored": ["npc"]}
```

- `source` is one of `npc`, `profile`, `global`, `default` (chevo's four). `profile_id` is the profile consulted (the default profile's id
  for an unassigned NPC), so the UI can say "Global: 50, profile *Companions*: 25".
- `ignored` is present only when a lower level held an invalid override, e.g. `["npc"]` for an NPC value of `9999` against a 1-500 range.
- Without a character, only `global` and `default` are possible.
- A sibling `explainProfile($key, $profileId, $metadata)` serves the profile editor, which has a profile but no NPC.

`explain()` is the **only** source of the inherited-value text. `synthDescribeInheritedSettings()` is deleted once the editor calls it; the
editor's "replaces: Global (50)" line is `explain($key)` evaluated one level up. The compatibility projection ([section 8](#8-rollout)) and
the request log both use it, so a log line like `context.history=25 (profile "Companions")` is exact.

## 7. Dashboard read/write path

Pages never name tables or build SQL for settings.

| Page | Reads | Writes |
|---|---|---|
| `ui/global_settings.php` (and quickstart, local LLM setup, presets) | `ConfigManager::definitions()` for the form, `all()` for stored globals | `set($key, $value, $expected)`; one request writes a set of keys and reports a per-key result (the partial-success model of chevo PR #269). |
| Profile editor (`ui/core/core_profiles.php`, override editor with `mode=profile`) | `definitions()` filtered to scope `profile`; stored `settings` object; `explainProfile()` per key for "inherited from" | Writes the object `{"settings": {...}}` after `validateMetadata('profile', …)`. Inherit removes the key. |
| NPC editor (`ui/core/npc_master.php`, `mode=npc`) | `definitions()` filtered to scope `npc`; `explain($key, $characterId)` for source badges | Same, `validateMetadata('npc', …)`. |
| Narrator management (`ui/core/narrator_management.php`) | The narrator-owned keys | `set()` of global-only keys. |

The three editors already share `ui/core/tmpl/override_editor.php`. Phase B keeps that template, replaces its `$settingsCatalog` and `$inherited` inputs with
`definitions()` and `explain()`, and moves its key-name rules out of the page. API: a thin JSON route
(`GET /api/v1/config`, `GET/PATCH /api/v1/config/{key}`, `GET /api/v1/config/explain?key=&character_id=`) is the contract the pages call (the
edit-API plan of SCHEMA-REDESIGN.md section 10); Phase B step 1 ships the PHP class and the pages call it in-process first.

Rules at this boundary: the key is looked up in the registry, the value is validated, the scope is checked against the endpoint
(a page for NPC overrides cannot write a global-only key), the client's `character_id` is only an input, never authorization
(AGENTS.md trust boundary). Secrets are never settings; API keys stay behind the #62 facade.

## 8. Storage addendum for #63

This is the section the schema-redesign design needs. It adds **no new table beyond what #63 section 3.1 already lists**: it
fixes the shape of `core_config_setting` and says what #63 `core_profiles` and NPC metadata must carry.

### 8.1 `core_config_setting` (entity `ConfigSetting`, base `AbstractConfigSetting`, DAO `ConfigSettingDAO`)

Same as chevo, which #63 already adopts:

```sql
CREATE TABLE public.core_config_setting (
    config_key   text PRIMARY KEY,
    config_value jsonb NOT NULL,
    updated_at   timestamp(6) without time zone NOT NULL
);
```

- `config_key` is the dotted registry key (not the legacy name).
- `config_value` is the typed JSON value (`true`, `50`, `"narrator"`, `["a","b"]`, `{...}`), never a string-encoded scalar.
- Entity: `#[ORM\Entity] #[ORM\Table(name: 'core_config_setting')]` with columns `key`, `value` (`type: 'json', options: ['jsonb' => true]`),
  `updatedAt` (`PreciseDateTimeType`). System class `Synth\Core\Entities\System\ConfigSetting` has `findByKey`, `readValues(keys)`,
  `findLockedValues(keys)` and `saveValue(key, value, expected, present)`.
- DAO: `find`, `findAll`, `save`, `update` (one transaction; `pg_advisory_xact_lock(hashtext('synth.config'), hashtext(key))`; reload under
  the lock; compare with `$expected`; connector-id check before the lock for `connector_id` keys), `readValues`, `findLocked`, as chevo's
  `ConfigSettingDAO`. Run inside the legacy-shared session as the rest of #63's DAOs do (no separate connection).
- No `scope` column and no per-profile rows. Global values only. That matches chevo and keeps `getLocked()` a primary-key read.

### 8.2 Profile and NPC overrides

- **Profile:** the `settings` object of `core_profiles.metadata` (jsonb; the column exists in SYNTH today). `{"settings": {"context.history": 25}}`.
- **NPC:** the `settings` object of the NPC's metadata. **Open point for #63 step 2:** today the NPC editor saves its overrides to
  `npc_master.extended_data` (`ui/core/npc_master.php`, `fieldName => 'extended_data'`) while `synthDescribeInheritedSettings()` reads
  `$npcRow['metadata']`. #63 step 2 must say which column `core_npc` keeps for NPC overrides; this design needs only "one jsonb object
  per NPC that holds `settings`". Until step 2 lands, the Phase B reader reads whichever of the two the runtime reads today.
- Legacy flat form (`{"CONTEXT_HISTORY": 25}` at the top of metadata) is read by `overridesOf()` through `normalizeKey()` for a transition
  period and rewritten into `settings` on the next save. #63 recreates the database from the new baseline, so
  no data migration is part of Phase B (SYNTH is pre-alpha; see the no-upgrade-path decision in `docs/SCHEMA-REDESIGN.md` on #63); only the reader tolerates the old form.
- `core_profiles` gains **no** setting columns. It does gain `itt_connector_id` (#63 section 3.1), which `vision.connector_id` resolves against.

### 8.3 Replaces, drops

| Today | After |
|---|---|
| `synth.general_settings` (`id`, `value text`, `description`, `updated_at`) | `core_config_setting`, keyed by registry key. `description` moves to the registry. |
| `SettingsRepository`, `GeneralSetting` (read pilot) | `ConfigSetting` / `ConfigSettingDAO` (#63 section 4). |
| `synth.conf_opts` keys that are settings (`synth_profile_model`, `CurrentParty`, Fallout stats rows, …) | Per #63: `player.*` / `narrator.*` keys where they are values, caches elsewhere. This design adds no mapping of its own for those. |
| `synthGetAllGeneralSettings()`, `synthGetGeneralSetting*()`, `synthSetGeneralSetting()` | Thin deprecated wrappers over `ConfigManager::get/set` until the last caller moves, then deleted. |
| `synthGetOverrideableGeneralSettingsCatalog()`, `synthGetGlobalOnlySettingIds()`, `synthGetManagedGeneralSettingIds()`, `synthDescribeInheritedSettings()` | `ConfigManager::definitions()` / `explain()`. |
| `core_config_preset` (#63 section 3.1, `snapshot jsonb`) | Unchanged: a preset is a `{key: value}` object of global values, validated through `set()` on apply; invalid keys are reported, not stored. |

## 9. Rollout

Phase A is this document only. Phase B is on `codex/schema-redesign`, **after step 1 lands**, one commit per item, each with its own
`php -l`, PHPUnit on isolated PG17 and `schema-check`.

| # | Item | Notes |
|---|---|---|
| B1 | `ConfigManager` with the registry for the keys in [section 4](#4-initial-key-list), `normalizeKey`, `validateValue`, `definitions()`; `AbstractConfigSetting`, `ConfigSetting`, `ConfigSettingDAO`; `core_config_setting` in the baseline | Global `get/set/getLocked/all` only. `SettingsRepository`/`GeneralSetting` removed in the same commit (#63 step 1). |
| B2 | `explain()`, `getForCharacter()`, `getForProfile()`, `overridesOf()`, `validateMetadata()`, `metadataWarnings()` | Reads profile `metadata.settings` and the NPC object. Registry-only; no callers yet. |
| B3 | Seeding and the compatibility projection | First-run seeding copies the stock `conf.sample.php` values into `core_config_setting` through `set()`. `ConfigManager::applyToGlobals(?int $characterId)` assigns `$GLOBALS[legacy]` for every registry key from the effective value, replacing `synthLoadGeneralSettingsIntoGlobals()` + `CoreProfile::setOldGlobals()` + the metadata half of `NpcMaster::setOldGlobalsFromCurrentNpcData()`. All the `$GLOBALS['X']` readers keep working, unchanged, and so does `conf.php` for bootstrap values. |
| B4 | Dashboard: global page, profile editor, NPC editor, narrator page switch to `definitions()`/`explain()`/`set()`/`validateMetadata()`; `synthDescribeInheritedSettings()` and the catalog helpers deleted | Follows the partial-success and stale-write (`$expected`) behavior of chevo PR #269. HTTP probe and a visual check at desktop and 390 px. |
| B5 | Move readers one group at a time from `$GLOBALS['X']` to `ConfigManager::getForCharacter()` (context, rechat, worldknowledge, relationship, …) | Each group a separate PR; the projection is deleted when the last reader moves. Optional; not needed for the dashboard to be correct. |
| later | Settings-edit API routes; the chevo "Settings Phase 2" style reviewed-preview/migration is **not** needed, because there is no upgrade path. | |

### Validation Phase B will need

- PHPUnit: type and range matrix per validator; resolution order (npc > profile > default-profile > global > default) including explicit
  `false`/`0`/`''` overrides; invalid override skipped and listed in `ignored`; wrong-scope write rejected; alias normalization;
  `getLocked()` sees a value committed by another connection; `$expected` conflict. Disposable PG17 only, isolation checks of the probe harness first.
- Projection test: for a fixture profile and NPC, `applyToGlobals()` equals the globals the old loaders produced. This is the parity check
  that lets B3 land before any reader moves.
- Dashboard HTTP probe plus browser check of the three editors. **No in-game testing is possible from this environment**; the
  projection parity test is the substitute for readers, not for gameplay.

### Risks

- **Overridable-by-accident keys** (`?` above). Today anything not in the global-only list can be put in profile metadata. Making that
  explicit may take overrides away from users who set one by hand; Phase B reports each such stored key (`metadataWarnings`) rather than deleting it.
- **Explicit blank now wins.** Profile text overrides that are `''` used to be ignored and will now blank the value. Only for
  `multiline_text` keys marked blank-able in the registry (`prompt.head` stays non-blank, as today).
- **Name churn.** About 100 keys are renamed. The alias map keeps old metadata and presets readable; the projection keeps `$GLOBALS` names.
- **Dependency on #63 step 1** for the connector table and `Database::boot()`; **dependency on step 2** only for the NPC override column.
- **Sibling work.** Other open work adds new `conf.php`/general settings (voice, health notice, conversation lifecycle). Those
  should be registered as keys here, not as new `general_settings` rows; the cutover command centre decides ordering.

## 10. Open questions

1. **Key names.** Group prefixes above (`context`, `rechat`, `comments`, `worldknowledge`, …) are a proposal; chevo's prefixes are fixed
   only where SYNTH has the same concept (`context.max_events`/`max_tokens` do not exist here: SYNTH uses one history count).
   Accept as is, or align `context.history` to chevo's event-limit and token-budget pair?
2. **NPC override column** (section 8.2): `metadata` or `extended_data` on the new NPC table?
3. **`?` keys:** review list before B1, per section 4.
4. **Settings presets** (`core_config_preset`): global values only, or also a profile `settings` object?
