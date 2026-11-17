# Location catalog

**Status: design (DRAFT). The wire contract and the server's accept-and-log path are implemented; storage and prompt use are not.**
Nothing here is verified in game. The paired SYNTH change has the protocol, codec, negotiation and the engine-free export
logic, tested without the game; the game reader and the export trigger are **not written** (see that repository's
`docs/LOCATION-CATALOG.md`), and the capability is not advertised yet, so no server receives this event from a real client.

Today the server knows only the player's own place: [LOCATION-ANCESTRY.md](LOCATION-ANCESTRY.md) carries the current
`BGSLocation` and its parent chain inside a context. It knows nothing about any other place, so an NPC asked "where is
Sanctuary?" or "is Concord far from here?" has no catalog to answer from, and a destination typed by the player is matched by
name only. This design adds a **catalog of Commonwealth locations**, built from the game by the client and stored per
playthrough state, and a bounded, place-aware prompt line beyond the ancestry line.

References, read-only: chevo `managers/LocationManager/{README.md,PLAN.md}` (server) and CHIM_4
`src/features/locations/LocationExport.*`, `docs/design/location-export.md` (client). The ideas are ported; no file is copied.
Persistence follows [SCHEMA-REDESIGN.md](SCHEMA-REDESIGN.md) (PR #63): ORM entities, DAOs, `public.core_*` names, one baseline.

## 1. What is kept from chevo and what changes

| chevo | SYNTH | Why |
|---|---|---|
| Records keyed by plugin + local FormID, decoded from a runtime rid with a plugin manifest sent first | The **client decodes** and sends `(origin_plugin, local_form_id)` | The SYNTH client already decodes every form through the loaded-plugin manifest (`location_ref`, `location_ancestry`). The server never sees a load-order FormID in the catalog, so a manifest cannot be stale or missing. |
| Two HTTP endpoints, `batch` and `upsert` | One v2 event, `location_catalog`, with `mode` `batch` or `upsert` | SYNTH has one entry point (`main.php`), one envelope and one capability negotiation. A new endpoint would need its own authentication of session and lane. |
| `hold_location_id`, walled-city worldspaces sharing Tamriel's map | `region_location_id` (nearest `LocTypeCity`/settlement-class ancestor, below) and worldspace-level `uses_parent_map` | Fallout 4 has no holds. It has one large map (the Commonwealth) and separate DLC worldspaces with their own maps. |
| Curated and player-created rows (`kind` `curated`/`player`) | **Not in this design** | Authored locations are a later, separate feature. `kind` is kept as a column so they can be added without a migration, as chevo's plan does. |
| Position computed on the server, never `(0, 0)` | Same | Rules can be corrected without asking the player to export again. |
| `cleared` per playthrough in a state table | Same, plus `discovered` | The map marker's discovered/visible state is the other save-dependent fact the game exposes. |

## 2. Fallout 4 data mapping

All reads happen in the client's runtime adapter on the game thread, behind `IFalloutRuntime`; workers get copied snapshots.
Items marked **(confirm)** are my reading of the game's record model and must be checked against the pinned CommonLibF4 headers
and the game before the client export is called working.

### 2.1 BGSLocation (record `LCTN`)

| Game data | Wire field | Notes |
|---|---|---|
| The `BGSLocation` form | `ref` | Plugin-local identity `(origin_plugin, local_form_id)`, 24 low bits for a full slot, 12 for an `FE` light slot, as `location_ancestry` already does. A form created at runtime (`FF` prefix) has no plugin identity and is skipped by the client. |
| Full name | `name` | Required, at most 255 UTF-8 bytes, never empty. An unnamed location is not sent; a child of one is re-parented to the nearest **named** ancestor so the hierarchy skips it (depth bounded at 16). |
| Editor ID | `editor_id` | Only when the game keeps it (it often does not at runtime). Optional, never an identity. |
| `parentLoc` | `parent_ref` | Plugin-local, or null at a root. A parent may arrive after its child in a later batch; the server links by reference, not by arrival order. |
| Keywords (`LocType*` and others) | `keywords` | Keyword editor IDs, at most 64. The server derives tags from them; the client sends no derived value. |
| `IsCleared()` | `cleared` | Save-dependent: stored per playthrough, not in the catalog row. Omitted when the game cannot answer. |
| Map marker "visible / can travel" state **(confirm)** | `discovered` | Save-dependent, same storage. Optional: absent means unknown, never "no". |
| Special references (`BGSLocationRefType`) and the location's marker references | `markers` | Below. At most 32, each with its own `ref`. |

### 2.2 Markers

A marker is a reference the game associates with the location. The client sends what the game reports, never computed
coordinates.

| `type` | Source in the game | Used by the server for |
|---|---|---|
| `map_marker` | The location's `MapMarker` reference (the pip-boy world map pin) **(confirm: reached through the special-ref list or the persistent-reference list)** | The preferred map position. |
| `location_center` | The location-center special ref | A position when no map marker exists, and the cell of an interior. |
| `outside_entrance` / `inside_entrance` | The entrance special refs | Links an interior to its exterior door; fallback position outdoors. |
| `boss_treasure` | The boss/treasure special ref | Recorded only; not used for position or prompts. |
| `world_marker` | The location's world-location marker when the game has one | Fallback map position. |
| `workshop` | The settlement's workshop workbench reference (workshop settlements) **(confirm)** | Recognises a settlement and anchors "your settlement" statements. |

Each marker carries `ref`, optionally `cell_ref`, `interior`, `position {x,y,z}` and `disabled`. An **interior** marker has no
worldspace and its position is local to the cell; an **exterior** marker always has a `worldspace`. The protocol refuses a
marker that breaks that rule. A marker whose reference is not in memory is sent by `ref` only and then cannot have a position,
so the client omits it (a marker without a position is useless to the server).

### 2.3 Worldspaces and the map

`worldspace` is embedded in each exterior marker: `ref`, `editor_id`, `name`, `parent_ref`, `uses_parent_map`.

- **Commonwealth** is the main worldspace, and `Fallout4.esm`'s `Commonwealth` worldspace is the map almost every location
  uses (editor ID `Commonwealth`; the local FormID in the fixtures is a placeholder to **confirm**).
- **Far Harbor** (`DLCCoast.esm`) and **Nuka-World** (`DLCNukaWorld.esm`) have their own worldspaces and their own world
  maps. Their coordinates are **not comparable** with the Commonwealth's. The server compares positions only inside one map
  worldspace and never reports a distance across two.
- Interiors that belong to a worldspace map position (a vault, a building) have none of their own: they inherit through the
  parent chain (section 4.3).
- Mods may add worldspaces and locations; nothing is hard-coded to the vanilla list. `uses_parent_map` is the client's
  statement that a worldspace shows its parent's map (the equivalent of chevo's walled-city case), so a modded sub-worldspace
  works without a server table. Vanilla Fallout 4 does not need it for the Commonwealth.

### 2.4 Settlements

Workshop settlements are locations whose keywords include the settlement class and that have a `workshop` marker **(confirm
exact keyword editor IDs from a real export; the first export is the source of truth, not this list)**. Settlements are the
places where the player's own state changes the world (population, ownership), but this catalog stores only the static
identity and the player's `cleared`/`discovered` state. Settlement population and happiness are not catalog data and are not
exported here.

Keyword editor IDs expected on vanilla locations, to be **confirmed** from the first real export and then fixed in the tag
table: `LocTypeSettlement`, `LocTypeWorkshopSettlement`, `LocTypeCity`, `LocTypeDungeon`, `LocTypeVault`, `LocTypeInhabited`,
`LocTypeMilitaryBase`, `LocTypeRaiderOutpost`. Unknown keywords are stored and ignored.

### 2.5 What is not exported

Quests, factions, items, NPC residence, settlement population, fast-travel graph, region (`REGN`) data, and the cell grid are
out of scope. A location's relationship to the player's current cell stays in the existing ancestry line.

## 3. Protocol

Contract files (mirrored byte-for-byte in the SYNTH client): `protocol/v2/schemas/location-catalog.schema.json`, the
`location_catalog` event in `event.schema.json`, the `location_catalog` init flag in `status.schema.json`, and the fixtures
`location-catalog-{batch,upsert}.json` (valid) and twelve `location-catalog-*.json` invalid cases.

- **Event** `location_catalog`, protocol v2 only, **unbound** (`context_sequence` is 0) because it describes the game, not a
  scene. It carries the normal envelope (session, request, turn, generation, runtime variant, capabilities).
- **Capability** `world.location_catalog`. The init acknowledgement carries `location_catalog: true` only when the init listed
  it; an older server never receives the event, and an older client never sees the flag. Flat and VR use the same fields. The
  client advertises it only for a runtime lane whose adapter can read the data (flat first).
- **Payload** `mode`, `playthrough_id`, `locations`, and for a batch `export_id`, `sequence`, `final`.
  - `batch`: 1 to 50 locations; `export_id`, `sequence` and `final` required. The client sends the next batch after the
    answer, so a slow server paces the export; `final` marks the last one. Fifty keeps the worst case (50 locations x 32
    markers) far below the 1 MiB request limit.
  - `upsert`: exactly one location and none of the batch fields. Sent the first time the player enters a named location in a
    game session, before the context that names it, so locations added by a mod after the last export still appear.
- **Location** `ref`, `name`, `keywords`, `markers` required; `editor_id`, `parent_ref`, `cleared`, `discovered` optional.
- **Closed objects, bounds and identity** follow the rest of the protocol: every object is closed, forms are plugin-local with
  six uppercase hex digits and never zero, coordinates are finite and bounded to +/-10,000,000, keyword and editor-ID
  characters are restricted, nothing carries a runtime form ID or a Base64 body.

### 3.1 Answers

A shape violation refuses the whole request (`422`, `invalid_payload`, the same issue list as every other event). Otherwise the
server answers `200` with a `status` line, code `location_catalog_accepted`, whose `detail` is a JSON string:

```json
{"received":5,"valid":2,"markers":3,"refused":[{"index":2,"reason":"duplicate_ref"}],"stored":false}
```

`refused` lists at most 16 records, each with its index in the request and a reason that does not stop the others:
`duplicate_ref` (the location appears earlier in the request), `self_parent`, `duplicate_marker` (the same marker type and
reference twice). `stored` is `false` until storage exists; when it does, the counts become `stored`, `updated` and
`unchanged` and a refused reason list stays the same. The client treats any `200` as "send the next batch" and any other result
as a failed batch.

### 3.2 Idempotency and ordering

Until storage exists a request writes nothing, so it takes no request claim and replays freely. When storage exists the
operation is a set of keyed upserts (same location, same result), so a replayed batch is harmless; the request still gets the
shared claim so the generation and supersession rules apply. Batches of one export are applied in `sequence` order but each is
independent: a missing earlier batch never makes a later one invalid, because parents are linked by reference.

## 4. Storage addendum for PR #63

Implement **only** after step 3 (catalogs) has landed on `codex/schema-redesign`; this section is the request for that step.
It does not add a legacy table or legacy `$db->` SQL. The existing flat `synth.locations` table (name, formid, worldspace) is
what the step already replaces; this design says what it becomes.

Conventions from SCHEMA-REDESIGN: `public.core_*`, one `Abstract*` mapping, one system class and one DAO per table family,
integer ids for config-like rows, `bigint` for large sets, `jsonb` columns with `options: ['jsonb' => true]`, enumerations
with `CHECK`, foreign keys on durable rows, DAO-only specialized columns.

### 4.1 Tables and entities

`core_location` (`Location`, `LocationDAO`, system class `Location`). One row per game location, unique by plugin-local identity.

| Column | Type | Notes |
|---|---|---|
| `location_id` | `bigint` identity PK | |
| `kind` | text, `CHECK IN ('game')` | Only `game` now; widened by the authored-location feature. |
| `plugin` | text, FK `core_plugins.canonical_name` | Same plugin identity as `core_npc_actor`. The export's `origin_plugin` is resolved to it; an unknown plugin refuses the record (`unknown_plugin`, new reason, below). |
| `baseid` | `integer` | The local FormID (24 bits). Unique `(plugin, baseid)`. |
| `name` | text not null | Last export wins. |
| `editor_id` | text null | |
| `parent_plugin`, `parent_baseid` | text, integer null | The parent as received, so a link can be made when the parent arrives later. |
| `parent_location_id` | `bigint` FK self, null | Computed after every batch over the whole catalog. |
| `region_location_id` | `bigint` FK self, null | Computed (4.3). |
| `keywords` | jsonb | List of keyword editor IDs as received. |
| `tags` | jsonb | Derived by the server from `keywords` (4.3). |
| `map_worldspace_id` | `integer` FK `core_worldspace`, null | Computed. |
| `map_x`, `map_y` | double precision, null | Computed. Never `(0, 0)`. |
| `position_source` | text, `CHECK IN ('map_marker','world_marker','center_marker','parent','none')` | |
| `last_catalog_sha256` | char(64) | Hash of the normalized record, so an unchanged record is `unchanged` and no write is made. |
| `created_at`, `updated_at` | `PreciseDateTime` | |

`core_location_marker` (`LocationMarker`, owned by the `Location` family DAO): `location_id` FK cascade, `marker_type` (`CHECK`
over the seven types), `plugin` + `baseid` of the reference, `cell_plugin` + `cell_baseid` null, `interior` boolean,
`worldspace_id` FK null, `x`, `y`, `z` double precision, `disabled` boolean. Unique `(location_id, marker_type, plugin, baseid)`.
A record replaces its markers (delete and insert inside the DAO's one DBAL transaction; no external call inside it).

`core_worldspace` (`Worldspace`, `WorldspaceDAO`): `worldspace_id` serial, unique `(plugin, baseid)`, `editor_id`, `name`,
`parent_worldspace_id` self FK null, `map_worldspace_id` FK self (the worldspace whose coordinates it uses: itself unless
`uses_parent_map`). Filled from the markers' `worldspace` objects; no seed list.

`core_location_state` (`LocationState`, `LocationStateDAO`): PK `(playthrough_id, location_id)`, `cleared` boolean null,
`discovered` boolean null, `updated_at`. `playthrough_id` is the same column type the runtime tables use (open question 4 in
SCHEMA-REDESIGN). Last observation wins; a null never overwrites a known value.

These are catalog rows: they are never archived with a save snapshot (state rows are, by `playthrough_id`) and never written by
a model or a prompt.

### 4.2 DAO surface (what the protocol handler and prompt need)

- `LocationDAO::upsertBatch(array $records, string $playthroughId): UpsertResult` returns `inserted`, `updated`, `unchanged`,
  `refused[]` (index and reason) and `recomputed`. It writes the catalog and, for the records' `cleared`/`discovered`, calls
  `LocationStateDAO::observe()`. It recomputes parents, region and position over **the whole catalog** after the batch (as chevo
  does), so arrival order never matters.
- `LocationDAO::findByRef(plugin, baseid)`, `ancestry(location_id, limit)`, `describe(location_id, playthroughId)`.
- `LocationDAO::nearest(map_worldspace_id, x, y, limit, tagFilter)` for "near X" (4.4); it never crosses map worldspaces.
- `LocationStateDAO::observe(playthroughId, location_id, ?cleared, ?discovered)`.

Reasons added to the shared list when storage lands: `unknown_plugin` (the plugin is not in `core_plugins` for this
playthrough's loaded-plugin manifest) and `runtime_form` is not needed because the protocol already excludes it.

### 4.3 Computed fields

- **Parent**: from `parent_plugin` + `parent_baseid`; null if absent.
- **Region** (`region_location_id`): the nearest ancestor, the location itself included, whose tags include `city` or
  `settlement`; else the nearest ancestor whose parent is a root; else null. This replaces chevo's hold. Walk bounded at 16.
- **Position** (`LocationPositionResolver`-style): an enabled `map_marker` in a worldspace with a map; else `world_marker`;
  else `location_center` or `outside_entrance` outdoors; else the nearest ancestor's position (`parent`, up to 16 links);
  else `none`. Never `(0, 0)`. The position's map is `core_worldspace.map_worldspace_id`.
- **Tags**: from `keywords` through one table in code (`LocationTags`): `city`, `settlement`, `dungeon`, `vault`, `inhabited`,
  `military`, `raider`, `inn`/`shop` where the keyword says so. Unknown keywords produce no tag.

### 4.4 What the addendum does not decide

Edit API and UI for the catalog, curated/authored locations, the generic "where is X" resolver, and moving the current
`data_functions.php` destination lookup (`FROM locations ORDER BY sim`) onto `LocationDAO` are later steps; they need the
catalog first.

## 5. Prompt use (after storage; not implemented)

All additions are **turn-scoped, bounded and read-only**, like the ancestry line.
`ContextManager::situation()` today adds `location` from `synth_location_prompt_line()`. The catalog extends that, never
replaces it, and falls back to it when the catalog has nothing.

1. **Place line.** When the current location resolves in the catalog (by `location_ref`, never by name), the line gains the
   region and kind: `Current location: Dugout Inn, within Diamond City (interior: CellarA). Diamond City is a city.` Interior
   and exterior wording follows the existing helper. State is the playthrough's, not the catalog's.
2. **"Near X" outside any location.** When the player is in an exterior cell with no location and has a position, one line:
   `The player is in the Commonwealth, near Concord (about 180 m).` The nearest named location by map position, same map
   worldspace only, with a distance cap and one place only. Nothing is said across worldspaces (Far Harbor, Nuka-World).
3. **Destinations in dialogue.** When a typed turn names a place the catalog can resolve **unambiguously by name within the
   player's discovered locations**, one line carries what the catalog says (name, region, relative direction and rough
   distance). Names are only a lookup into the catalog, never an identity; two matches mean no line.
4. **What NPCs may know.** Only `discovered` locations and the NPC's current region are offered to the prompt. The catalog does
   not give an NPC knowledge of a place the player has not found, and an NPC's own knowledge stays governed by its profile and
   world knowledge, not this table.
5. **Safety.** Catalog names, keywords and tags are untrusted game text: inserted as quoted data in a fixed template, length
   bounded, never as instructions, never written to NPC memory, profiles or the world-knowledge store, and never used to add an
   audience or witnesses (the same rule as the ancestry line). The whole addition is capped at a few hundred bytes.

## 6. Phases

1. **This document** (DRAFT SynthServer PR).
2. **Client protocol and export logic, server validation without storage** (paired DRAFT SYNTH PR, same protocol bytes). Server
   accepts, reviews and logs; the answer says `stored: false`. The client's game reader (the **(confirm)** items of section 2)
   is a separate step that needs the pinned engine headers and a real export.
3. **Stop.** Storage waits for step 3 of #63 and a go message; then the addendum above is implemented on `codex/schema-redesign`
   and the prompt use follows storage.

## 7. Risks and open questions

- **First real export decides the keyword list, the marker sources and the Commonwealth worldspace ID.** The doc marks each
  assumption **(confirm)**; none is tested.
- **Untrusted write endpoint.** The event carries no secret and writes nothing yet. When storage lands it is an untrusted
  write path: a request cap per session/playthrough and a ceiling on catalog size need deciding with the unclaimed-request
  question (a bound event would use the claim; this one is unbound by design). Not added in this phase.
- **Cleared/discovered after load.** Save-dependent values can be stale after a load until the next visit or export; last write
  wins and nothing treats them as live.
- **Playthrough identity.** `playthrough_id` is required on every request so a state row cannot attach to the wrong save; the
  catalog rows themselves are playthrough-independent.
- **VR.** The fields are identical, but the VR adapter's ability to read these records is unproven; the capability is simply not
  advertised there until it is.
- **Question for the owner:** should the catalog be re-exportable automatically after a game update or mod change (a hash of the
  loaded-plugin manifest), or only from the manual export plus first-entry upserts?
