# NpcRegistration

Stores the actors a client names and handles the `actor_profile` game-data record. Design: [`docs/RUNTIME-ACTORS.md`](../../docs/RUNTIME-ACTORS.md)
(sections 2, 5 and 6).

## Classes

- `NpcRegistration`: placed and runtime (`0xFF`) actors over `NpcActorDao`, `NpcRuntimeActorDao` and `NpcDao`.
  - `registerPlaced()`: keyed by plugin and plugin-local id, never a load-order prefix. A form id with the `FF` prefix is refused
    (`invalid_identity`) even when a plugin is named. The character must exist already; a second registration of the same key
    updates the actor and keeps its character.
  - `registerRuntime()` / `registerRuntimeBatch()`: keyed by the client-minted `runtime_actor_id` in one playthrough. Idempotent;
    a second call renames the actor and updates the form-id hint, the character never changes. A new id whose fingerprint matches
    exactly one actor that left the save, with a claim hint equal to that actor's last form id, takes over its character
    (`re_adopted`) and marks the old row `replaced`. Caps: 32 per batch, 20000 per playthrough (`registry_full`).
  - `retireRuntime()` (`deleted`, `id_reused`) and `syncRuntime()` (post-load: refresh the re-bound ids, mark the rest `not_in_save`).
- `ActorProfileRegistration`: the `actor_profile` record, moved out of `gamedata.php` with unchanged behaviour. `gamedata.php`
  and `api/v1/npc/add.php` are thin adapters over it.
- `RegistrationResult`: `registered`, `existing`, `re_adopted` or `rejected` with a reason.

Every input is untrusted: UUID format, `FF` prefix, plugin names, name length and control characters, the `spawn.kind`
allowlist and the fingerprint format are checked here; the playthrough comes from the caller (the session), never the body.

## Not wired yet

There is no wire message for `runtime_actor.register`, `.retire` or `.sync` (they need the paired protocol and client PRs of
RUNTIME-ACTORS phases B and C), and `actor_profile` does not register placed actors yet because it has no manifest to decode a
form id into plugin and local id (see `ReferenceResolver`). Until then the manager is called only by tests.

## Known limits

- Runtime characters are `core_npc` rows with `scope_playthrough_id` set. The legacy unique index on `npc_name` where
  `actor_identity_id` is null allows one such row per name, so a second same-name actor gets its form id appended to the
  character name; the real display name lives on `core_npc_runtime_actor`. The identity redesign should replace that index.
- A rename keeps no name history yet (the design keeps the old name).
- The per-session cap (512) belongs to the endpoint that knows the session; it is not enforced here.
- Creating the character and the actor row are two statements without a transaction: the shared-session DAO adapter refuses
  transactions. A failure between them leaves an unused scoped character, nothing inconsistent.
