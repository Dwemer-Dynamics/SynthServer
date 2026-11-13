# Private narrator storage groundwork

This additive migration and repository layer do not enable private narrator requests, advertise a capability,
change legacy Narrator controls, or change the SCTX co-save format. The paired runtime and protocol activation
are a separate change. Existing clients and saves continue to use their current paths.

`private_narrator_schema` 20261113001 creates immutable definition revisions, private transcript/state nodes,
session lane heads, context-to-state captures, pending outputs and state-bound transcript cursors. No existing
history, profile, journal or save rows are rewritten. Reapplying the migration preserves existing private rows.

The repository operations use the existing claimed-request session lock. Capture/restore helpers are inert
until the runtime explicitly calls them from context publication. Provider calls and audio delivery belong
outside these database transactions. Definition edits use a separate invalidation generation so a saved old
revision remains usable while pending work from before an edit can be rejected.

The runtime change must negotiate the capability, capture applied private state using the existing context
anchor, isolate private content from NPC readers, and accept replies only with exact completed-audio receipts.
Installing this groundwork alone supplies none of those user-facing controls or acceptance claims.
