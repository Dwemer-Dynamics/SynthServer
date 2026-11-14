# Readable paper notes (server)

Wire contract: [protocol/v2/README.md](../protocol/v2/README.md) and `protocol/v2/schemas/note.schema.json`.
Source: `lib/synth_notes.php`, schema `lib/core/database_schema/synth_readable_notes.sql` (migration
`readable_notes_schema` 20261113002 in `debug/db_updates.php`).

## Flow

1. `note_read` (flat `1.11.240` only) is claimed like every owned v2 request, then re-proven on the locked
   handle: exact canonical bytes, active flat generation, bound snapshot and its player identity row. The stable
   key is derived from that generation's init plugin manifest. The body goes into `note_revisions` (deduplicated
   by key and SHA-256), and one `note_reads` row is granted to the bound player only. An NPC nearby gains nothing.
2. `note_share` needs the bound snapshot to be the lane's newest one, and the claimed mode must equal the saved
   `synth_mode`. Only `STANDARD`, `WHISPER` and `CLOSE` are allowed. It picks the player's newest read visible
   from that snapshot (highest ID) and grants exactly that read and revision to the snapshot's target identity.
3. Prompts: the native `/talk` path (`ContextManager::noteKnowledge` -> `PromptManager::messages` `$noteKnowledge`)
   and the ordinary pipeline (`main_synth_pipeline.php` after quest task injection -> `synth_note_legacy_prompt`)
   read only `note_shares` rows for the exact responder that are visible from the request's save ancestry. Narrator
   responses get nothing. Up to 4 notes, 4 KiB each and 12 KiB in total, rendered as escaped JSON inside
   `<shared_notes>`.

## Visibility

Every accepted read or share publishes one data-free `note_read`/`note_share` marker (eventlog row plus immutable
`history_events` row, no people, no body, title or key) inside the claimed request's session lock and transaction.
Its typed grant row is keyed by that `history_event_id`, with foreign keys to the request, the bound snapshot and,
for a share, the exact source read and revision. A trigger checks that the marker belongs to the same request, lane
and type, that a share hands over the sharer's own read, and that grants and revisions are never updated or deleted.

Visibility is the existing saved insertion frontier (`synth_protocol_history_cte` -> `native_history_scopes`,
`h.id <= cutoff`, current lane included). A bound snapshot never sees a marker published after it was acknowledged,
so a read or share becomes visible only from the next published context. The share picks the player's newest read
by marker ID inside the frontier; the recipient prompt shows the newest shared revision of each stable key, so an
older save keeps the revision it was given and a private re-read never changes what an NPC was handed. Older saves,
sibling branches, VR sessions and other recipients see nothing. A replayed claim confirms its first grant and
publishes no second marker.

## Deliberate limits

- The prompt block is shown only to the snapshot target that is responding. Rechat or action continuations whose
  responder is not the bound target get no notes.
- `synth_note_share_mode_refusal` allows only `STANDARD`, `WHISPER` and `CLOSE`, so `SHOUT` is refused. It sees
  modes only: the unmerged Everyone audience and frozen or uncertain audiences never reach it. The client refuses
  any audience other than Normal; combined integration with Everyone is held. That function is the seam to extend.
- Terminals, holotapes, rumours, operator tools and UI are out of scope. No model call is made for reads or shares.
