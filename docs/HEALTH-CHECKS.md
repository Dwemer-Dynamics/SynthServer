# Health checks and the status page

`health.php` answers one readiness question and several granular ones. All answers are JSON, read-only and safe to poll: no check writes,
migrates, takes the migration lock, loads settings or sends a provider request, and `Logger::suspendFileWrites()` keeps
every one from writing or rotating a SYNTH log file. Failures are one fixed, actionable sentence; driver and
configuration text (which can carry connection details) goes only to the web server error log. Optional pieces that are
absent (no keys, no NPCs, no connector, no `vendor/`) are a normal answer, never a PHP warning.

`health.php` and its checks live outside `ui/`, so the dashboard Host allowlist
([HANDOFF.md](HANDOFF.md#dashboard-request-safety-candidate)) does not apply and the plugin can always reach
them. The Apache `Require local` rule still limits the client address.

## Routes

| Request | Meaning | 200 when | Otherwise |
| --- | --- | --- | --- |
| `health.php` | Full readiness (database, UTF8 encoding, runtime schema, migration ledger, advisory background processor). Unchanged. | everything is ready | 503 with one `error` ([SCHEMA-MIGRATIONS.md](SCHEMA-MIGRATIONS.md#readiness)) |
| `health.php?check=live` | Liveness: the web server and PHP answer. Reads no configuration and opens no connection. The client polls this. | always | none |
| `health.php?check=db` | The database accepts a query and uses a supported encoding. | yes | 503 |
| `health.php?check=api-keys` | `api_keys_configured`: how many stored API keys are non-empty (a count, never a key). Asked of the existing `ApiBadge` facade, so it follows wherever keys are stored. | the store was read | 503 |
| `health.php?check=npcs` | `npcs_available`: how many NPC profiles exist (`CharacterRepository::availableCount()`). | the table was read | 503 |
| `health.php?check=llm` | `llm_connectors_configured` (connectors with an http(s) URL and a model), `llm_endpoints_checked` and `llm_endpoints_reachable`. Only a bare TCP connect to each distinct `host:port` (at most 5, in parallel, 2 s total): no key, prompt or token is sent. | at least one endpoint accepts a connection | 503 with `llm` = `not_configured` or `unreachable` |
| `health.php?check=all` | `db`, `api-keys`, `npcs` and `llm` in one request and one connection, as `checks`. Without a database the others are `skipped`. This feeds the status page. | overall `status` is `ok` or `warning` | 503 when any check is `error` |

An unknown `check` answers 400.

Every body has `check` and `status`: `ok`, `warning` or `error`. A count of zero keys or zero NPCs is a `warning`
(HTTP 200): a local provider needs no key, and a new install has no characters yet. An unreachable database or provider
is an `error` (HTTP 503). `api-keys` and `npcs` also answer 503 when their store cannot be read.

## Status page

The Control Panel **Health** tab (`ui/system_health.php`) keeps its readiness cards and adds a compact table of the
granular checks from `health.php?check=all`: Database, API keys, NPCs and LLM provider, each with a status badge and a
one-line detail. It refreshes every ten seconds and shows `unreachable` if the server does not answer.

## Client notice

The SYNTH client polls `health.php?check=live` (every 20 seconds while the server answers, 5 seconds after a failure
doubling to 60 seconds) and shows one in-game notice when the server stops answering, one when it returns, and one
reminder after a game load if it is still down. See the client repository's `docs/HANDOFF.md`. The `[Server]
ConnectionNotice` setting in `SYNTH.ini` turns it off; polling stops with it.

## Evidence

HTTP probes of every route on a disposable PostgreSQL 17 database (healthy, no keys, no NPCs, LLM down, LLM not
configured, database down) with a `pg_dump` digest before and after each scenario proving no write; a provider
stand-in received zero bytes. No deployment, installed database, game or in-headset run is involved.
