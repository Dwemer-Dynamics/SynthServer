# LLM request log

Every request the server sends to an LLM provider is stored in `public.core_llm_request_log`, so it can be inspected and sent again later. This is chevo's `core_llm_request_log`, designed for SYNTH's two driver shapes.

## What is logged

| Call | Logged by | Row |
| --- | --- | --- |
| Typed dialogue stream (`LlmWrapper::stream` through `OpenAiCompatibleDriver`) | `LlmWrapper` | `call_kind = stream`, `purpose = dialogue`. `request` holds the connector summary (id, driver, model, url, label; no key) and the messages. The reply is the concatenated text deltas. |
| Stateful provider drivers (`open`/`process`/`close`, `fast_request`) | `LoggedLegacyConnector`, returned by `LlmWrapper::legacyConnector()` (and so by `LLMConnector::getConnector()`) | A streamed request starts at `open()` and ends at `close()`; a request never closed ends as `failed`. `fast_request()` is one row, with its call name as `purpose`. `request` holds `call`, `messages` and `parameters`, exactly what the driver received. |

Each row also has the driver, model, connector id, turn or request id as `correlation_id`, `replay_of_id`, timing (`created_at`, `finished_at`, `duration_ms`), `status` (`running`, `completed`, `failed`, `cancelled`), the provider `url`, the exact provider JSON body (`request_body`), the HTTP status, the reply (`response_content`), `usage` and token counts, and `error_class` / `error_message`.

The provider URL, body and status come from the one place every provider call passes through, `lib/provider_stream.php` (`LlmRequestScope`). Headers, which carry the key, are never captured. Calls outside a logged request, such as memory embeddings, are not captured.

Token counts and `usage` are read from the provider body, so they are only filled for non-streamed requests. A streamed request's reply is the assembled text.

## Safety

- **Redaction** (`LlmRequestSanitizer`): values under secret-looking keys (`api_key`, `authorization`, `token`, `password`, `player2_game_key`, and the like; counters such as `max_tokens` are kept), the exact keys of the connector in use, bearer tokens, provider key prefixes (`sk-`, `AIza`, `gsk_`, `xai-`) and `key=` query parameters.
- **Bounds:** each string is cut at 64 KiB, a payload over 1 MiB is replaced by a size marker, a reply is cut at 256 KiB, an error message at 2,000 bytes. A cut request has `request_truncated = true` and cannot be replayed.
- **Never breaks a request:** a storage error is logged (class only) and ignored. If the first write fails (for example a database that is not migrated yet), logging stops for that process.
- **Switch:** the `meta` setting `LLM_REQUEST_LOG_ENABLED` (default on).

## Retention

`LLM_REQUEST_LOG_RETENTION_DAYS` (default 14) and `LLM_REQUEST_LOG_MAX_ROWS` (default 5,000; at least 100), both `meta` settings. Pruning also ends rows still `running` after an hour (a worker killed mid-request) as `failed`/`abandoned`. It runs with the periodic log trim (`initializeLogTrimming`) and on demand: `php bin/llm-request-log.php prune --apply`.

## Replay

```
php bin/llm-request-log.php list [--limit=N] [--status=failed]
php bin/llm-request-log.php show ID
php bin/llm-request-log.php replay ID [--connector=ID] --send
```

`replay` without `--send` prints what it would send. With `--send` it loads the connector as stored now (the original, or another by id), calls the same driver through `LlmWrapper` with the stored messages and parameters, and logs the replay as a new row with `replay_of_id`. The report says whether the new provider body equals the original's (`body_matches`; null when either body is missing). A replay is a real provider call and costs what the original cost.

## Files in `log/`

The per-request files (`context_sent_to_llm.log`, `context_sent_to_llm_fast.log`, `output_from_llm.log`, `output_from_llm_fast.log`, `debugStream.log`) are **kept**. The database log does not replace them: they hold the raw streamed lines and the Server Logs page and the log trim read them. The database log adds structure, redaction, replay and retention; the files stay the unredacted debugging trail.

## Schema

Migration `Version20261120100000` (with its structure file in `schema-manifest.json`). The entity is `AbstractLlmRequestLog` / `LlmRequestLog` and the DAO is `LlmRequestLogDao` (`start`, `finish`, `find`, `recent`, `prune`).
