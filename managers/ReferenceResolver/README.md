# ReferenceResolver

Resolves the typed references a client sends, and keeps the load-order manifest of each playthrough. Design:
[`docs/RUNTIME-ACTORS.md`](../../docs/RUNTIME-ACTORS.md) (sections 3 and 4). A placed reference is keyed by **plugin plus
plugin-local form id**; the load-order prefix is decoded through the manifest on the way in and never stored, so inserting,
removing or reordering a plugin changes no stored key.

## Classes

- `PluginManifest`: the validated plugin list of one session (at most 255 full and 4096 light plugins, unique names and indexes,
  no light plugin for `vr`). `decode()` turns a runtime form id into a lower-cased plugin and a six-digit local id, `encode()`
  does the reverse, `sha256()` is the canonical hash the server recomputes, `diff()` reports added, removed and moved plugins.
- `ReferenceResolver`: `resolve()` answers `resolved`, `unresolved` or `rejected` with the reasons of section 4.1
  (`invalid_rid`, `manifest_missing`, `unknown_plugin_prefix`, `plugin_missing`, `unknown_reference`,
  `runtime_actor_unregistered`, `runtime_actor_stale`, `unsupported_reference_type`, `plugin_mismatch`, `invalid_identity`,
  `player_reference`). `resolvePlaced()` resolves a stored plugin-local reference. `acceptManifest()` validates a
  `session.plugin_manifest` message, refuses a hash the client got wrong, records a revision through
  `PluginManifestRevisionDao` and returns the diff against the previous revision. `currentManifest()` and `isStale()` serve the
  `manifest_stale` answer.
- `DeferredReferences`: pure functions over an event's context metadata (`unresolved_references`): bounded add (64 per event,
  then `unresolved_overflow`), and `resolve()` that removes the entries that now resolve. The caller reads and writes the event
  row inside the registering transaction.
- `Resolution`: the immutable result and its wire fields.

An unresolved reference never rejects the request that carried it, except where the request type needs a resolved actor.

## Not wired yet

- No endpoint calls it: `session.plugin_manifest` and the typed identity are protocol work (RUNTIME-ACTORS phases B and C).
- `DeferredReferences` is not attached to context events: `core_context_event` has no `context_metadata` column yet (the
  context-event entity step adds it). Until then nothing persists pending references.
- Nothing yet re-resolves pending references when an actor registers or a manifest adds a plugin.
- The legacy `core_plugins` table and `ExtensionManager/Native/game_plugins.php` helpers still serve the current code; they are
  not replaced here.
- A form id that disagrees with a runtime actor's stored hint is not relinked by the resolver (it is a read path).
