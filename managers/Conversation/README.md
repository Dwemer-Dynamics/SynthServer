# ConversationManager

Conversation continuity and the uniform line lifecycle (`dialogue.conversation`). The normative text is in
`protocol/v2/README.md`; the design is in the pull request that added it.

## Responsibilities

- Validate the optional `conversation_id` (lowercase UUID) on `input_text`, `input_audio` and every `trigger`, and the
  extended `cancel` payload (`scope`, `reason`, `conversation_id`), against the negotiated capability.
- Return the id a request carries (`idOf`), which `response_start` echoes on both dialogue lanes.
- Grant the init acknowledgement (`initGranted`).
- Cancel a whole conversation (`cancel`): revoke every in-flight or completed-undelivered request of that
  conversation in the session generation, like a single-request cancel. The reason is kept in the stored cancel event.
- Provide the history preference (`rankSql`): rows of the request's own conversation fill the row limit first.

## Boundaries

The id lives in the stored canonical events, so there is no table. `skipped` receipts are stored as `failed`
utterances (the receipt event keeps the outcome); an `interrupted` line is kept in context marked ` [cut off]` by
`ReceiptManager`. Legacy `lib/` code only calls into this class through thin hooks.
