# MessageHandler

Outbound delivery of completed NPC lines to the game.

Chevo keeps a `core_message_queue` table and a `MessageHandler` that creates, completes, delivers and cancels messages.
SYNTH already has its durable outbound queue: the TTS job in `core_worker_job` (created by `lib/synth_tts_jobs.php`, run by
`workers/Speech/SpeechWorker`), the per-key status file and the media registry (`drivers/tts/SpeechStorage`). The plugin polls
`GET /api/v1/messages/<id>` (`api/v1/messages.php`, via `tts_status.php`) where the id is the TTS cache key, and
`managers/Notification` can nudge it to poll sooner.

So this folder is a read-side manager over what exists, with no new table:

- `MessageStatusReader::status($id)` returns the `synth.tts_status.v1` state (`pending`, `ready`, `failed`, `unknown`, `invalid`).

Not implemented, on purpose: request contexts, scopes, cancel-older-requests, expiry, a `delivered` transition and the
`message_available` notification payload. They need a queue table (schema redesign) and are deferred until it exists;
`api/v1/messages.php` and `tts_status.php` are unchanged.
