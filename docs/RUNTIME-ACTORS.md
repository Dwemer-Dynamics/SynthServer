# Runtime (`0xFF`) actors, load-order continuity and deferred references

**Status: Phase A, design only. Nothing here is implemented.** No schema, migration, entity, protocol file or
caller changes with this document. The build waits for (a) the owner's answer to
[SCHEMA-REDESIGN.md](SCHEMA-REDESIGN.md) open question 3 (identity) and (b) the matching storage step of
PR #63 landing on `codex/schema-redesign`. Client, protocol and non-storage work may start earlier, on `unstable`,
behind a capability (see [Rollout](#8-rollout)).

Inputs, all read-only: SYNTH `origin/unstable` `15a326c` (server) and `63fdaac` (client);
`codex/schema-redesign` (`docs/SCHEMA-REDESIGN.md`); chevo `8ebe86e` (`managers/NpcRegistration/README.md`,
`managers/ReferenceResolver/README.md`, `managers/SaveState/README.md`); CHIM_4 `38090a7`
(`src/features/npcs`, `src/features/references`). Ideas are ported, not files.
Nothing was tested in game; every Fallout 4 engine behaviour below is **an assumption to verify** and is
listed in [section 9](#9-verify-before-build).

## 1. Problem

Today SYNTH only handles placed references:

- A native `/talk` turn aimed at a dynamic actor is refused with `native_turn_ineligible` /
  `target_not_placed_reference`; a second identity row for one plugin-local reference is refused with
  `load_order_identity_ambiguous` (`protocol/v2/README.md`). Dynamic `0xFF` references are also never
  marked for delivery receipts. Settlers, random-encounter spawns, leveled-list spawns, mod-spawned NPCs and
  recruited generic companions are all `0xFF` actors and are therefore unusable.
- Storage is keyed by the **runtime** full form ID: `synth.actor_identities` is unique on
  `(playthrough_id, game, origin_plugin, full_form_id)` where `full_form_id` is `0x` plus 8 hex digits **including
  the load-order prefix**. Inserting, removing or reordering a plugin changes the prefix of every placed actor of
  every plugin after it, so a continuing save gets new identity rows, new profiles and lost memory. The
  `chr:<40 hex>` character key already uses the plugin-local ID, but "existing storage remains keyed by runtime full
  form ID; this key does not repair continuity" (`protocol/v2/README.md`).
- `origin_plugin` comes from `reference.GetFile(0)` and falls back to the **base** object's file
  (`commonlib_capture.hpp`). A `0xFF` actor has no file, so it silently reports its base's plugin (for example the
  plugin that owns the leveled spawn) next to a `0xFF` form ID. The current guard is a refusal; it must become an
  explicit invariant ([section 2.4](#24-invariants)).
- References that arrive before the thing they name exists (an actor not yet registered, a plugin not yet in the
  manifest) have no deferred path.

## 2. Identifying an actor stably

### 2.1 Placed actors (plugin reference): key by plugin and local ID

A placed reference is identified by **`(plugin, local_form_id)`**, never by the full form ID:

- `plugin`: lower-cased file name of the plugin that owns the reference, decoded **from the manifest** using the
  load-order prefix of the wire `form_id` (`xx` for full plugins, `FExxx` for light/ESL plugins), not trusted from
  the client's `origin_plugin` field when the two disagree (`plugin_mismatch`, as chevo).
- `local_form_id`: the 24-bit (full) or 12-bit (light) local part, stored as 6 hex digits, the same value the
  `chr:` key already uses.

This is chevo's `core_npc_actor(refid, plugin)` and it makes placed actors independent of load order. The wire still
carries exact `0x` + 8 hex form IDs (they are what the game and the client use); the server converts through the
manifest on the way in and out. Placed actors need **no new client state**.

### 2.2 Runtime (`0xFF`) actors: a client-minted, co-save-persisted `runtime_actor_id`

A runtime form ID alone is not an identity: the game may reuse the ID of a deleted runtime form for the next one
(chevo observed this in Skyrim and treats "same rid, other name" as a reuse), and nothing ties an `0xFF` ID to the
playthrough. chevo's rule is that name decides. That fails here: SYNTH must survive renames of runtime actors
(a settler is renamed by the player or by a name mod), and a name is display data.

**Design: identify a runtime actor by an opaque `runtime_actor_id` that the client mints and persists in the F4SE
co-save, exactly as it already does for the playthrough identity** (`save_context.hpp`, fixed-size little-endian
records, hostile/truncated record tests).

1. **Mint.** When the client first needs a runtime actor on the wire (it becomes a talk target, an addressed or
   hearing audience member, an assigned follower/settler of interest, or the server asks, see
   [section 5](#5-protocol-changes)), it creates a random UUID `runtime_actor_id` (lower-case, 36 chars, same
   generator and failure behaviour as the playthrough id; no identity is minted if system randomness fails).
2. **Persist.** The co-save gets one new record type, `RACT` (version 1): a bounded list (at most 2048 entries) of
   `{runtime_actor_id, runtime_form_id, base_plugin_index, base_local_form_id, template_plugin_index,
   template_local_form_id, race_local_id, sex, flags}` with a string table for plugin **names** (never store prefix
   indexes: they change with load order). Because it rides the save, loading an older save restores the older set, so
   server-side links rewind in step with the game. This is the property chevo cannot get (its `core_npc_actor` links
   "are not save state").
3. **Restore.** On `PostLoadGame` the client resolves each entry: look up the form by `runtime_form_id`, require an
   actor whose base (and template) match the stored plugin-local base by **plugin name**, and require it is not
   known deleted. A match re-binds `runtime_actor_id` to the live reference. A mismatch (the form is gone, or
   another actor took the ID) **drops the entry**; the actor, if met again, gets a new `runtime_actor_id`. The
   server never has to guess about reuse because the client never lets two live actors share an id.
4. **Retire.** An actor that is permanently gone (deleted, or its runtime form no longer resolves after the cell
   unloads in a way the engine reports as deletion) is reported `retired`. A dead actor is **not** retired: a corpse
   keeps its identity for history and memory.
5. **No co-save.** A save made before this feature, a lost `.f4se` file, or a co-save record rejected as corrupt
   starts with an empty set. The client then runs the weak recovery below at most once per actor, or mints new ids.
   It never reuses an id it cannot prove.

**Recovery fingerprint (secondary, never primary).** The first time a runtime actor is registered the client sends a
`fingerprint`: SHA-256 over canonical `(base plugin name lower, base local id, template plugin name lower, template
local id, race plugin+local id, sex)`. It deliberately excludes name and form ID. The server may re-adopt an
existing, currently unlinked runtime actor only if **exactly one** unlinked actor of that playthrough has this
fingerprint and the client supplied a `claim_hint` (`last_form_id`); otherwise it creates a new actor. Two
indistinguishable raiders are never merged. Re-adoption is reported (`status: re_adopted`) and audited.

**Spawn context (descriptive, not identity).** The registration carries what the game knows about origin, used for
profile rules and context, not for matching: `spawn.kind` (`leveled_spawn`, `workshop_settler`, `quest_alias`,
`script_placed`, `summoned_or_cloned`, `unknown`), `spawn.cell` and `spawn.worldspace` as `(plugin, local id)` pairs,
`spawn.location` (the same, when known) and `first_seen_gamets`. `unknown` is always allowed; the server does not
reject a registration for missing context.

### 2.3 Fallout 4 specifics

All to be confirmed ([section 9](#9-verify-before-build)):

- **Companions are placed.** Named companions (and Dogmeat) are plugin references, so they already work and only
  gain load-order independence from 2.1. `0xFF` covers generic and spawned NPCs: workshop settlers, Minutemen
  and settlement guards, random encounters, raiders/gunners/Institute spawns, leveled actors, and mod-spawned NPCs.
- **A leveled actor's base is often itself a runtime copy.** CHIM_4 walks the template chain to the nearest plugin
  base; the client does the same and sends `base` (own base) and `template` (nearest plugin-owned record, with plugin
  name and local ID). Rules that match by plugin and local ID use the template; a base that is itself `0xFF` is
  sent as `form_id` only and has no stable identity (as chevo's `base_plugin`/`base_formid` null case).
- **Light (ESL) plugins** use the `FExxx` prefix (4095 slots) in current Fallout 4; Fallout 4 VR has no light-plugin
  support, so the VR manifest is expected to contain none. The server never assumes either: it decodes from the
  manifest it was sent.
- **Load order is fixed for the life of a process.** Fallout 4 cannot change plugins while running, so the manifest
  is sent **once per process** (at init). Load-order *change* is detected between sessions, by comparing the new
  manifest to the one stored for the playthrough ([section 3](#3-load-order-manifest-and-remap)).
- **Plugin references inside a save are remapped by the game** by plugin name; SYNTH does not have to remap
  anything the engine already remapped. It has to remap **its own stored numbers**, which is why nothing stored may
  contain a prefix.
- **VR and flat** share one memory model. Runtime lane and exact client version stay recorded with the playthrough as
  today; `runtime_actor_id` is playthrough-scoped and lane-independent so a save moved between lanes keeps identity
  when its co-save does.

### 2.4 Invariants

Server, on every identity it accepts:

1. A `reference_kind: "placed"` identity whose `form_id` has prefix `FF` is **rejected** (`invalid_identity`), even
   when `origin_plugin` names a real plugin. This closes the base-file fallback hole of section 1.
2. A `reference_kind: "runtime_actor"` identity must have an `FF` prefix, a valid `runtime_actor_id`, and no
   `origin_plugin`.
3. The `playthrough_id` is derived from the authenticated session, never from the body; a body value that differs
   is rejected (existing behaviour, kept).
4. The `runtime_actor_id` is the only lookup key for a runtime actor. The `form_id` is a **hint** used for display,
   logs and the active-actor index; a `form_id` that disagrees with the id's last known link updates the hint
   (`relinked`), it never selects another actor.
5. Names are display data. They are never used to match, merge or split identities.

## 3. Load-order manifest and remap

### 3.1 Manifest

The server already keeps the loaded-plugin table (`synth.game_plugins`, `lib/core/game_plugins.php`, fed by a
`plugins` payload) and chevo's `plugins.manifest` contract is the same data. This design:

- keeps one **current manifest**, mapped as chevo's `core_plugins` (`canonical_name` PK, `reported_name`, `is_light`,
  `compile_index`, `small_file_compile_index`, `partial_index`, `formid_prefix`);
- records **one manifest revision per playthrough per change** (new table, [section 7](#7-storage-addendum-for-63)):
  `manifest_sha256`, the ordered plugin list and when it was first seen;
- requires the manifest **before any request that carries a `form_id`**, as chevo does. A request that carries a
  form ID before a manifest is `unresolved` / `manifest_missing`, never guessed;
- never trusts `formid_prefix` on its own (it must agree with the index), as chevo.

### 3.2 Change detection and remap

On a manifest arrival the server computes the diff against the previous revision **of that playthrough**:

| Change | Detected as | Effect on stored data | Effect on references |
|---|---|---|---|
| Plugin index changed (insert/remove/reorder) | same name, different index | none: stored keys are `(plugin, local_id)` | rid decoding uses the new manifest; nothing is rewritten |
| Plugin added | name not in previous | none | `unknown_plugin_prefix` references that named it may now resolve ([section 4](#4-resolution-statuses-and-deferred-resolution)) |
| Plugin removed or disabled | name in previous, not in new | rows keep their data, marked `plugin_missing` in the status view | references to it become `unresolved` / `plugin_missing`; kept, not deleted |
| Plugin replaced by another version | same name, same index | not detectable from names alone | out of scope, see below |
| Runtime actor whose base plugin is missing | co-save restore sees `base_missing` | actor kept, `template` cleared, flagged | actor stays usable by `runtime_actor_id`; profile rules that need the base do not match |

"Remap" therefore means **recompute, not rewrite**: with plugin-local keys the server holds no number that a
load-order change can invalidate. The only stored full form IDs are `form_id` hints on runtime actors and the
events' recorded references (history is immutable evidence, kept as sent, with the manifest revision id it was sent
under so it can be re-decoded). There is **no migration** of old rows: SYNTH is pre-alpha with no upgrade path
(`SCHEMA-REDESIGN.md`); an install made before this change is recreated.

**Not covered:** a plugin replaced by a different version with the same name, or a `.esp` whose local IDs were
renumbered by its author. The manifest has no content hash. An optional per-plugin `file_size`/`crc` may be added
later; until then the server cannot tell, and says so.

### 3.3 Stale and mismatched manifests

The client sends the manifest at init and again whenever it reconnects after a failure. The server keeps the
revision with the session. A request whose `manifest_sha256` (carried in the typed identity envelope header, not
per reference) disagrees with the stored revision of the session is answered `manifest_stale` and the client resends
the manifest.

## 4. Resolution statuses and deferred resolution

Ported from chevo's `ReferenceResolver` and extended; the wire vocabulary stays compatible with chevo's
(`resolved` / `unresolved`) so shared tooling reads both.

### 4.1 Wire result (`status` on every returned typed reference)

| `status` | `reason` | Meaning |
|---|---|---|
| `resolved` | `null` | Identity found; server id returned |
| `unresolved` | `invalid_rid` | Not 8 hex digits, or numeric JSON |
| `unresolved` | `manifest_missing` | No manifest for the session yet |
| `unresolved` | `unknown_plugin_prefix` | No loaded plugin owns the prefix (chevo's name) |
| `unresolved` | `plugin_missing` | The named plugin is not loaded now but is known to this playthrough |
| `unresolved` | `unknown_reference` | Placed reference never seen |
| `unresolved` | `runtime_actor_unregistered` | `0xFF` actor with a `runtime_actor_id` the server has not registered |
| `unresolved` | `runtime_actor_stale` | The id is retired or its link was replaced (client should mint a new id) |
| `unresolved` | `ambiguous_reference` | More than one candidate (legacy rows only; cannot occur for new keys) |
| `unresolved` | `unsupported_reference_type` | Typed reference not supported for this type |
| `rejected` | `plugin_mismatch`, `invalid_identity`, `player_reference` | Nothing stored; correct and resend |

An unresolved reference **never** rejects the request that carried it (chevo's rule), except where that request type
already requires a resolved actor (a `/talk` target): that turn ends with `native_turn_ineligible` and the same
`reason` detail, replacing `target_not_placed_reference` and `load_order_identity_ambiguous` once the capability is
negotiated.

### 4.2 Deferred (internal) state

Chevo keeps `unresolved_references` in a context event's metadata and resolves it when the target registers. SYNTH
does the same, using existing storage (`core_context_event.context_metadata`, no new table): an array of
`{role, reference_type, rid, runtime_actor_id?, plugin?, reason, recorded_manifest_id}`.

- **Pending → resolved.** When (a) a runtime actor registers, (b) a manifest that adds a plugin arrives, or (c) an
  actor is first created for a placed reference, the server resolves matching pending references on events of the
  active playthrough within the retention window, writes the resolved `character_id`, and removes the entry. This
  runs inside the registering transaction (database only; no LLM/TTS/HTTP in it).
- **Pending → expired.** Entries older than the playthrough's retained context window (by `gamets`) are dropped, and a
  load that discards events after the save point drops theirs with them. An expired entry never resurrects.
- **Bounds.** At most 64 pending references per event and 4096 per playthrough; beyond that the event is stored with
  `unresolved_overflow: true` and nothing else. Resolution scans use the `(playthrough_id, rid)` index, never
  full-table scans (admin pages and read paths never trigger resolution).
- **Prompts and memory** read speaker names and ids through the resolved link at generation time (as chevo), so a
  rename or late registration is reflected in later prompts; recorded events keep the names they were recorded with.

## 5. Protocol changes

Capability **`identity.runtime_actors`** (v2 only; requires `dialogue.turn_ownership`; flat and VR may both
advertise). Only an init whose status carries `runtime_actors: true` grants it. Older clients and servers see no
change: without it the present refusals stand. New schemas are **new files** under `protocol/v2/schemas/`
(proposed names below), mirrored byte-for-byte in both repositories with a regenerated `manifest.sha256`, plus
valid and invalid fixtures. No existing schema is rewritten; v1 `identity.schema.json` is untouched, and the new
identity schema `oneOf`s over it.

### 5.1 Client to server

1. **Manifest** (`plugin-manifest.schema.json`, message `session.plugin_manifest`): sent once after init and when the
   server answers `manifest_stale`.
   ```json
   {"type":"session.plugin_manifest","manifest_sha256":"<64 hex>","game_version":"1.10.163.0","runtime_variant":"flat",
    "plugins":[{"name":"Fallout4.esm","compile_index":0},
               {"name":"SomeMod.esl","is_light":true,"small_file_compile_index":3}]}
   ```
   Limits: at most 255 normal + 4096 light entries; names `^[^/\\]+\.(esm|esp|esl)$`, at most 255 characters; the
   server recomputes the hash and rejects a mismatch. Light entries are rejected for `runtime_variant: "vr"`.
2. **Typed identity** (`actor-ref.schema.json`): `reference_kind` is `placed` (default; today's object unchanged) or
   `runtime_actor`:
   ```json
   {"reference_kind":"runtime_actor","runtime_actor_id":"0b9c...","form_id":"0xFF001234",
    "display_name":"Preston","playthrough_id":"..."}
   ```
   This replaces `origin_plugin` for `runtime_actor` only. Used wherever an actor identity appears today (target,
   audience, speaker, hearers, observation actors), so `DialogueManager` and `dispatch.php` change only where the
   identity is parsed (one accessor), not where it is used.
3. **Registration** (`runtime-actor-register.schema.json`, message `runtime_actor.register`, idempotent by
   `(playthrough, runtime_actor_id)`), sent before the first typed reference to that actor:
   ```json
   {"type":"runtime_actor.register","request_id":"<uuid>","runtime_actor_id":"...","form_id":"0xFF001234",
    "name":"Preston","previous_name":null,"race":{"plugin":"Fallout4.esm","local_id":"013746"},"sex":"male",
    "base":{"plugin":"Fallout4.esm","local_id":"01A2B3"},"template":{"plugin":"Fallout4.esm","local_id":"0A1B2C"},
    "fingerprint":"<64 hex>","claim_hint":{"last_form_id":"0xFF001111"},
    "spawn":{"kind":"workshop_settler","cell":{"plugin":"Fallout4.esm","local_id":"0A5F60"}},"gamets":123456}
   ```
   A batch form, `runtime_actor.register_batch` (at most 32 actors), is allowed for the post-load re-announcement.
   Names up to 255 characters; `previous_name` is accepted for runtime actors (a rename is now unambiguous, unlike
   chevo's "ignored for FF"): the stored name is overwritten and the old one kept in history.
4. **Retire** (`runtime_actor.retire`): `{runtime_actor_id, reason: "deleted"|"id_reused"}`; idempotent; the server
   refuses to retire an id of another playthrough or session lane.
5. **Post-load sync** (`runtime_actor.sync`): after `PostLoadGame` and co-save restore, the client lists the ids it
   re-bound `{runtime_actor_id, form_id}` (at most 2048, paged). The server updates `form_id` hints, marks ids absent
   from the list `not_in_save` (kept, inactive), and does nothing else.

### 5.2 Server to client

- `runtime_actor.result` (same envelope as other receipts; echoes `request_id`, the **current** `session_id`/lane,
  and the client's `runtime_actor_id`): `status` `registered` | `existing` | `re_adopted` | `rejected`, `reason`
  (`invalid_identity`, `plugin_mismatch`, `manifest_missing`, `rate_limited`, `playthrough_mismatch`, `registry_full`),
  and the server's `actor_ref` (opaque id; the client treats it as a string, never as a number).
- `rebind_required`: the server saw a `runtime_actor_id` it holds as retired or linked to a different form; the
  client mints a new id for that live actor and re-registers. Clients must drop any result whose session or request
  id is not the latest, as for npc.add.
- Typed references in `dialogue.*`, `speech_receipt` and action results echo the same identity shape and add the
  `status` / `reason` fields of [section 4.1](#41-wire-result-status-on-every-returned-typed-reference).
- `native_turn_ineligible` details: `target_not_placed_reference` is no longer produced when the capability is
  negotiated; `load_order_identity_ambiguous` becomes unreachable for new keys and is kept only for pre-existing
  legacy rows; new details `runtime_actor_unregistered`, `runtime_actor_stale`, `manifest_missing`, `plugin_missing`.
- Delivery receipts: a registered `runtime_actor` becomes eligible for `receipt_required` marking once registered
  and resolved. Unregistered or unresolved ones stay unmarked, as today.

### 5.3 Trust boundary

The client is untrusted: the server derives playthrough and lane from the session; validates UUID format, `FF`
prefix, plugin names against the manifest, name length and character set; applies an enum allowlist to `spawn.kind`
and `reason`; and caps registrations at 32 per request, 512 per session and a per-playthrough total (default 20000,
oldest retired first). **Rate limits for the untrusted write endpoint must be verified against the current ingress
limits before build**; if they are absent this must be reported, not silently expanded.

### 5.4 Registration policy (client)

Not every raider is registered. The client registers on demand: talk target, audience of an addressed or heard
turn, follower or assigned settler, and actors an observation names. A bounded pending list (256, as CHIM_4's
`MAX_PENDING_ACTORS`) holds actors waiting for a settled name; overflow is rediscovered by the next scan. Creatures
and non-speaking kinds follow the existing eligibility rules, unchanged.

## 6. Storage and rename handling summary

- Placed: `core_npc_actor(refid, plugin)`, plugin lower-cased, `refid` = local ID (6 or 3 hex digits stored as 6),
  chevo's exact shape.
- Runtime: a separate table keyed by `(playthrough_id, runtime_actor_id)` ([section 7](#7-storage-addendum-for-63)),
  because mixing a nullable `plugin = ''` row into the placed table's primary key (chevo's trick) gives the
  `FF` rid the identity role that this design takes away from it.
- Rename: placed actors follow chevo (a name change with the same `(refid, plugin)` overwrites the name, no new
  actor). Runtime actors rename by `runtime_actor_id` plus optional `previous_name`; "same rid, other name means
  reuse" is **not** applied because the id, not the name, decides.

## 7. Storage addendum for #63

For `docs/SCHEMA-REDESIGN.md` step 2 ("Characters and plugins"); it fills the "SYNTH identity is
`(playthrough_id, game, origin_plugin, full_form_id)`... see open question 3" gap in section 3.2.
All tables follow chevo's three layers (`Abstract*` mapping, system class, DAO), `public.core_*`, `bigint` ids for
event-like tables, `PreciseDateTimeType` for server times, JSON as `jsonb`, `CHECK`s for enumerations. No legacy
table or `$db->` SQL is added.

### 7.1 The two identity options (open question 3)

**Option 1. Keep SYNTH's playthrough-scoped actor id** (today's `actor_identities.id`, one character per actor per
playthrough).

- `core_npc_actor`: `id bigint PK`, `playthrough_id`, `kind` (`placed`|`runtime`), `plugin` (lower-case, NULL for
  runtime), `local_form_id char(6)` NULL for runtime, `runtime_actor_id uuid` NULL for placed, `character_id`
  (1:1 here), names/race/etc. Partial uniques: `(playthrough_id, plugin, local_form_id) WHERE kind='placed'` and
  `(playthrough_id, runtime_actor_id) WHERE kind='runtime'`; `CHECK` that exactly one family is set.
- Everything keyed by `actor id` stays playthrough-scoped (memory, relationships, diary, profile). A placed actor such
  as a named companion has a different profile per playthrough. Smallest change to the ~38 files that name
  `actor_identities`; keeps profile and biography edits playthrough-local.
- Cost: diverges from chevo's `core_npc_actor` shape and `character_id` meaning, so chevo's editor, profile-rule,
  biography-template and import code needs a mapping layer; a shared biography/profile for a named actor must be
  copied across playthroughs.

**Option 2. Adopt chevo's model: global `core_npc.character_id` plus `core_npc_actor(refid, plugin)`, with
`playthrough_id` on state and events only.**

- Placed actors: chevo's `core_npc_actor` exactly (`PK (refid, plugin)`, `character_id` FK, name, race, gender,
  `actor_class`, `base_plugin`, `base_formid`, `template_plugin`, `template_formid`, `replaced_*`). One character
  (profile, biography, voice) is shared by every playthrough; memory, relationships and events carry
  `playthrough_id`, as chevo's `core_memory` and `core_relationship_config` already do.
- Runtime actors: chevo's `plugin = ''` + rid row is **not** enough (shared across playthroughs, reuse-ambiguous,
  rename-hostile), so runtime actors get the extension in 7.2, and their `core_npc` row is **playthrough-scoped**
  (`core_npc.scope_playthrough_id` NULL = global).
- Cost: step 2 touches every consumer of `actor_identities.id`; "character" and "actor in a playthrough" become two
  concepts and joins gain `playthrough_id`. Benefit: placed-actor load-order independence is free (the key is
  `(refid, plugin)`), chevo tooling and Dwemer-wide content (biography templates, profile rules) apply unchanged.

**Recommendation: Option 2 with the 7.2 extension.** It is the target the owner already chose (chevo, not a fork),
it removes the full-form-ID key from the placed path with no new mechanism, and it keeps the only genuinely new
concept (runtime identity) isolated in one table. If Stu picks Option 1, 7.2 still applies unchanged except that
`character_id` is replaced by the actor id.

### 7.2 Runtime-actor and manifest tables (both options)

```
core_npc_runtime_actor                      -- one row per runtime actor per playthrough (mapped, editable read-only in UI)
  playthrough_id        text/guid  NOT NULL            -- type per open question 4
  runtime_actor_id      uuid       NOT NULL            -- client-minted, co-save persisted
  character_id          integer    NOT NULL  REFERENCES core_npc(character_id) ON DELETE RESTRICT
  last_form_id          char(8)    NOT NULL            -- hint, FFxxxxxx
  name                  varchar(255) NOT NULL          -- display only
  race_plugin / race_local_id, gender, actor_class
  base_plugin / base_local_id, template_plugin / template_local_id   -- all NULL-able, plugin lower-cased, local 6 hex
  fingerprint           char(64)                       -- sha256, recovery only
  spawn_kind            varchar(32) CHECK (spawn_kind IN ('leveled_spawn','workshop_settler','quest_alias','script_placed','summoned_or_cloned','unknown'))
  spawn_context         jsonb       NOT NULL DEFAULT '{}'   -- cell/worldspace/location as (plugin, local id)
  status                varchar(16) NOT NULL CHECK (status IN ('active','not_in_save','retired','replaced'))
  replaced_by_runtime_actor_id uuid NULL
  first_seen_gamets / last_seen_gamets bigint, created_at / updated_at timestamptz
  PRIMARY KEY (playthrough_id, runtime_actor_id)
  UNIQUE INDEX (playthrough_id, last_form_id) WHERE status = 'active'     -- active-actor index; hint only
  INDEX (playthrough_id, fingerprint) WHERE status <> 'retired'           -- recovery lookup

core_plugin_manifest_revision               -- history per playthrough; current rows stay in core_plugins
  id bigint identity PK, playthrough_id, manifest_sha256 char(64) NOT NULL,
  plugins jsonb NOT NULL (ordered name/index/light), runtime_variant text, game_version text,
  first_seen_at timestamptz; UNIQUE (playthrough_id, manifest_sha256)

core_npc (additions)                        -- Option 2 only
  scope_playthrough_id  NULL                -- set for runtime-actor characters; NULL = global (placed)
```

Notes:

- `core_context_event.context_metadata.unresolved_references` carries deferred references ([section 4.2](#42-deferred-internal-state)),
  and `context_metadata.manifest_revision_id` records the revision the identities were sent under. **No new
  table**; if #63 changes the event shape, these two keys move with `context_metadata`.
- `core_plugins` is chevo's table (`plugin_name` becomes `canonical_name`, already in the redesign); the revision table
  is the only new manifest storage.
- A DAO rule worth stating for #63: registration, re-adoption and `retire` are single-row writes inside one database
  transaction (the registering transaction also resolves pending references); no network or model call runs inside it.
- Optional `ConfigSetting` keys (existing table, no new storage): `runtime_actors.max_per_playthrough`,
  `runtime_actors.max_per_session`, `runtime_actors.pending_reference_cap`.
- If #63 uses `guid` for `playthrough_id` (open question 4), these columns follow.

## 8. Rollout

Order, with what may proceed before the storage step and what must wait:

| Phase | Work | Needs |
|---|---|---|
| **A** (this PR) | Design document. | nothing |
| **B** | Protocol: three new schemas, fixtures valid/invalid, `identity.runtime_actors` capability negotiation, byte-identical `protocol/` in both repos (`verify_protocol.py --peer`). Capability off by default. | A reviewed; can use `unstable` |
| **C** | Client: co-save `RACT` record and codec (hostile-input tests), `runtime_actor_id` minting, post-load restore and `sync`, template-chain reader, manifest sender. Fake-server tests only. | B; no storage |
| **D** | Server storage: entities, DAOs, handlers, resolver, deferred references. | Owner's Q3 answer **and** #63 step 2 landed on `codex/schema-redesign`, plus the event step for `context_metadata` (step 4) for deferred references; build on that branch |
| **E** | Flip eligibility: stop producing `target_not_placed_reference` when negotiated; mark runtime actors for delivery receipts. | D |
| **F** | Load-order continuity tests (reorder/insert/remove plugin against a recorded fixture), in-game evidence. | C, D, E |

Phase D's sub-steps can be split: D1 manifest + placed `(plugin, local)` key (fixes continuity for every placed
actor and needs no client change beyond the manifest), D2 runtime actor registry, D3 deferred resolution, D4 eligibility.
D1 is the highest value per line and independent of the runtime-actor client work.

Validation to report per phase: `php -l`, PHPUnit on a disposable PG17, `schema-check` fresh install, protocol
verification both sides, hostile co-save tests, fake-client E2E. **In-game behaviour (an actual settler or spawn
talking, a real load-order change on a real save, flat and VR) cannot be tested here and must be recorded
separately as acceptance evidence** (`docs/ALPHA-ACCEPTANCE.md`).

## 9. Verify before build

1. That a Fallout 4 runtime form ID can be re-used by a later object, and when (Skyrim behaviour is the only
   evidence). If it never happens, `runtime_actor_id` is still needed for rename and playthrough scoping, but the
   re-adoption and `id_reused` branches shrink.
2. That a runtime actor's form ID is stable across save/load of the same save, and that the F4SE co-save is the right
   persistence point (save-slot and autosave behaviour, `.f4se` handling when mods are removed).
3. Which engine event or state reliably reports a runtime reference deleted (for `retire`), and that a dead body is
   not reported deleted.
4. The template chain for settlers and leveled actors in the current CommonLib-F4 binding (flat and VR), and what
   `GetFile(0)` returns for a runtime reference on each.
5. That Fallout 4 VR has no light plugins, and the exact `compile_index` range in each lane.
6. Whether `plugins` payload fields already sent by the v2 client (`payloads.schema.json`) can be reused instead of a
   new `session.plugin_manifest` message. This design proposes a separate message so that protocol files stay
   separate, but reuse is cheaper if it fits.
7. Existing ingress rate limits for an untrusted write of this kind.

## 10. Decisions needed

For the owner: **Q3** (Option 1 vs Option 2; this document recommends Option 2 with the 7.2 extension) and
**Q4** (`playthrough_id` type, because 7.2 uses it).

For the design review (defaults proposed, not blocking): per-playthrough cap 20000 and per-session cap 512; whether
creatures register; whether to add a plugin content hash to the manifest; whether pre-existing placed rows keyed by
full form ID need any bridge (answer assumed no, per "no upgrade path").

Out of scope and unchanged: the installed database, deployments, services, game install and game, snapshots (paused),
PR #41, chevo and CHIM_4 (read-only).
