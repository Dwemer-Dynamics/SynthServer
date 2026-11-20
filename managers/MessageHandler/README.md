# MessageHandler

Outbound delivery of completed NPC lines to the game, through a durable queue.

## The queue

`core_message_queue` (migration `Version20261120110000`, entity `MessageQueueEntry`, `MessageQueueDao`) holds one row per
result the client fetches by id. The lifecycle is chevo's:

```
pending -> completed -> delivered        completed or pending -> cancelled | expired
```

- `MessageHandler::admit()` creates the row `pending` when a speech job is admitted. A row is owned by a session, a request
  and a generation and keyed by the utterance, so admitting the same utterance again returns the same id. A new generation
  first cancels the session's work from older generations, so a stale result is never returned.
- `MessageHandler::complete()` stores the payload (the `synth.tts_status.v1` document: ready with media, or failed) and
  only then announces the id (persist, then notify). A failed announcement is recorded, never loses the message, and is
  retried by `bin/message-maintenance.php` up to five times; the client polls meanwhile.
- `MessageHandler::deliver()` is one `UPDATE ... WHERE status = 'completed' ... RETURNING`: of any number of concurrent
  fetches exactly one gets the row. `GET /api/v1/messages/<id>` answers it with 200; every later fetch gets 409
  `{"status":"delivered"}`, a message still being produced gets 409 `{"status":"pending"}`, and a cancelled, expired or
  unknown id gets 404 with that status. `HEAD` reports the same code without consuming the message. The 20-parallel-fetch
  proof is `MessageQueueTest::testTwentyParallelFetchesDeliverTheMessageExactlyOnce`.
- `cancelRequest()`, `cancelOlderGenerations()` and `cancelCancelledWork()` (a cancelled turn or halt revokes the messages of
  the speech jobs it cancelled), `expire()` and `purge()` retire work. A message past `expires_at` (600 seconds after
  admission) is never completed or delivered, even before the maintenance run records it.

`SpeechMessages` is the speech edge: `admit()` runs in `ProfileSpeechDriver::queue()` before the worker launches, and
`settle()` runs in `service/tts_worker.php` once the job is final (ready completes the message with the status document,
failed completes it as failed, cancelled cancels the request's messages).

## The switch and the legacy path

The queue is used only when both sides agree: the server's `SYNTH_MESSAGE_QUEUE` is on (`MessageHandler::DEFAULT_ENABLED`
when unset) **and** the session listed the `message.queue` capability. Then the dialogue line's `speech` block carries
`message_id`, and the client fetches `GET /api/v1/messages/<id>`. Anything else keeps the legacy response path unchanged:
the plugin polls `GET /api/v1/messages/<cache key>` (`tts_status.php`, repeatable, `MessageStatusReader`). Both id kinds
share the route: a positive integer is a queue id, 32 hex digits is a cache key.

## Not here

Request contexts and scopes (SYNTH scopes by session and generation), other message types than `speech`, and a 202
acknowledgement for dialogue (dialogue is a streamed reply). Retention: `bin/message-maintenance.php --apply` deletes
delivered, cancelled and expired rows older than `--days` (default 7); active work is never deleted.
