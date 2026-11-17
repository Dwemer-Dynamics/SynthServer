# Player reading events

Flat-only `context.reading_events` (protocol v2). When the player reads a book, note, magazine or
terminal entry, the client sends the bounded title and text as `payload.reading_event` on its own
context. The server admits it, writes one history row and lets nearby NPCs refer to it. The client
side is described in the SYNTH repository's `docs/READING-EVENTS.md`; the schema is
`protocol/v2/schemas/reading-event.schema.json` (mirrored byte for byte).

Source: `lib/reading_event.php`; hooks in `lib/synth_ingress.php` (closed payload key, validation,
capability gate), `lib/synth_protocol_state.php` (persist inside context admission, history types)
and `lib/response.php` (`status.reading_events` grant).

## Behavior

- The grant: an init that is v2, flat and lists `context.reading_events` receives
  `status.reading_events: true`. VR and older clients never do, and a `reading_event` from either is
  rejected before it touches storage (HTTP 422).
- Validation is closed and strict: `event_id` `reading:<n>`, kind from the allowlist, title at most
  128 characters, optional text at most 4000 characters, no control characters except newline,
  valid UTF-8, a plain plugin file name. `text_truncated` without text is rejected.
- Storage reuses the observation timeline: one `synth.eventlog` row of type `reading_event` plus its
  `history_events` row, written inside context admission's session lock with the same frontier rules
  as quest and player events. There is no new table, column or migration. The context snapshot does
  not keep the text; the history row is the only copy.
- Idempotency: the same `event_id` in the same session, generation and playthrough with the same
  content is a no-op; different content is a conflict (HTTP 409) that rolls the admission back.
- Prompt: the row reads `<Player> read the <kind> "<title>". It reads: "<text>"` (or says that only the
  title is known) and appears in history for readers who are in the capture's audience, like other
  observations. The text is quoted game content and is never executed or treated as an instruction.
  Nothing marks that any NPC read it themselves.

## Not stored

No per-playthrough read registry, no full text beyond 4000 characters and no content-hash dedup.
Those need storage and belong to the persistence redesign; see the addendum filed with that work.
