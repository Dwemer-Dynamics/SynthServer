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

## Cancel and receipts

A receipt for a line of a request that a cancel revoked is refused (`receipt_owner_mismatch`, 409): nothing from a
revoked origin enters history. So a line cut off by an explicit conversation cancel is not kept as ` [cut off]`; only a
line interrupted without a cancel is. The client never retries a receipt, so the refusal is final.

## History preference cost

`rankSql` ranks only dialogue rows; other row types count as preferred so recent info, quest and memory rows are never
displaced. The correlated `EXISTS` is evaluated per candidate row through two unique-index lookups (see the pull request
for the measured plan).
