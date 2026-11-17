# BookManager

Owns what the player reads (protocol v2 `context.reading_events`): the bounded title and text of a
book, note, magazine, terminal entry or holotape. Client side: the SYNTH repository's
`docs/READING-EVENTS.md`. Schema: `protocol/v2/schemas/reading-event.schema.json`.

## Public methods

- `ReadingEvent::validate()` checks a `payload.reading_event` against the closed contract.
- `ReadingEvent::isOffered()` and `isAllowedOn()` say whether an init or an event may use it (v2, flat,
  capability listed).
- `ReadingEvent::describe()` writes the prompt sentence, quoting the text as game content.
- `ReadingHistory::persist()` writes one `reading_event` row in the existing observation timeline.

## What it refuses

VR, v1 or un-negotiated readings; unknown keys or kinds; titles over 128 or text over 4000 characters;
control characters other than newline in text; invalid UTF-8; a truncation flag without text; the same
event ID with different content (conflict).

## Storage

No table of its own. `ReadingHistory` holds the only SQL (the lookup of an earlier row with the same
event ID); it moves into a system entity class when the persistence redesign lands one. An optional
read registry for dedup and full text is described in the notes filed with that work.

Legacy hooks (thin, no logic): `lib/synth_ingress.php`, `lib/synth_protocol_state.php`, `lib/response.php`.
