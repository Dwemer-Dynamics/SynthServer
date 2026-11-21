# ExtensionManager

`Synth\Managers\ExtensionManager`: the host for SYNTH server extensions (`extensions/<id>/`), modelled on
chevo's ExtensionManager but much smaller. An extension adds behaviour through hooks; core code never includes
extension files.

## Contract

- **Discovery**: `extensions/*/extension.json`, validated by `ManifestValidator` (required `id`, `name`, `version`,
  `api`, `entry`, `autoload`; optional `description`, `authors`, `license`, `homepage`, `hooks`, `settings`, `provides`, `permissions`).
  Unknown fields are refused and all problems are reported together. `id` equals the folder name; the autoload
  namespace is inside `Synth\Extensions\`.
- **State**: `conf/extensions.json` (git-ignored): `{"enabled": [ids], "settings": {id: {name: value}}}`. No
  table is used. A missing file means nothing is enabled; running hooks never writes it. Per-extension data
  goes through `ExtensionContext::store()` into `core_extension_store` (see below).
- **Manage**: `php bin/extensions.php list | validate | enable <id> | disable <id>`. Only a valid manifest can be
  enabled. Installing a folder changes nothing until it is enabled.
- **Trust**: an enabled extension runs inside the server's PHP process.

## Hooks (API 1.1)

API 1.1 adds the hooks marked 1.1; the four of 1.0 are unchanged, so an extension written for `^1.0` still loads. Filters get
a payload array and return one. Every result is checked by `HookResults`: a result that breaks its hook's rule is discarded,
counted as an extension failure, and the value continues unchanged. Events only observe.

| Hook | Kind | Fires | Payload and what a filter may change |
| --- | --- | --- | --- |
| `app.boot` (1.1) | event, inline | `api/index.php` before routing, and `bin/worker.php` | `entry_point` (`api`, `worker`). |
| `api.request` (1.1) | filter | `api/index.php`, before any route | `method`, `route`, `respond`. May set `respond` to `{status: 100-599, body: object}` to answer instead of routing; nothing else changes. The request body is not exposed (use `game.event.received`). |
| `api.response` (1.1) | filter | after a JSON route answered; buffered only when an extension listens; not for `dialogue/request`, `user/input`, `npc/add`, `ext/*` or non-JSON answers | `method`, `route`, `status`, `body`. May change `status` (200-599) and `body` (object, 1 MB). |
| `game.event.received` (1.1) | filter | `synth_decode_wire_event()`, before the closed-contract validation | `event`. The result goes through the same validation as a client event (an invalid change answers 422). `request_id`, `session_id`, `turn_id`, `type`, `schema`, `protocol_version` and `generation` cannot change, and an event cannot be dropped. |
| `dialogue.request.received` (1.1) | filter | `DialogueManager::admit`, after admission | `request_id`, `character_key`, `npc_name`, `player_name`, `text`. May change `text` (1 to 2,000 characters). |
| `dialogue.listener.resolved` (1.1) | filter | `parseDialogueListenerNames()`, the legacy pipeline's split of the model's `listener` field | `listener`, `listeners`. May change `listeners` (up to 12 names of up to 100 characters). |
| `dialogue.context.sections` | filter | `DialogueManager::run`, after the prompt is built | context (`character_key`, `npc_name`, `player_name`, `world`) + `sections` (name => list of lines). Lines are XML-escaped and appended to the system message; names, counts and sizes are bounded. |
| `dialogue.prompt.messages` | filter | right after the sections | context + `messages` (role/content list; shape checked). |
| `dialogue.response.schema` (1.1) | filter | `synthApplyJsonTemplateHooks()`, the legacy JSON answer format | `fields` (name => description). May add up to 8 snake_case fields; existing ones cannot change. |
| `dialogue.reply.parsed` (1.1) | filter | `DialogueManager::run`, once per parsed line, before it is recorded, voiced and sent | `request_id`, `character_key`, `npc_name`, `line_index`, `text`. May change `text` (up to 1,000 characters); empty drops the line. |
| `tts.text` (1.1) | filter | `SpeechManager::speak`, before a line is queued for synthesis | `character_key`, `line_id`, `text`. Changes what is voiced, not the line shown (non-empty, up to 1,000 characters). |
| `save.created` (1.1) | event, inline | `SaveState::created` | `playthrough_id`, `session_id`, `generation`. |
| `save.loaded` (1.1) | event, inline | after the `init` event is committed (the game announced a loaded save) | `session_id`, `generation`. |
| `game.context.received` | event, deferrable | `synth_protocol_admit` after a `context` event is committed | `session_id`, `snapshot_sequence`, `world`. |
| `dialogue.reply.completed` | event, deferrable | `DialogueManager::run`, after the reply completed | `request_id`, `character_key`, `npc_name`, `player_name`, `lines`, `world`. |

Only the 1.0 events can be deferred (`Hooks::DEFERRABLE`); the 1.1 events always run inline. chevo hooks SYNTH has no moment for
(`game.event.recorded`, `npc.registered`, `playthrough.started`) are not offered.

An extension lists every hook it uses in `hooks`; registering an unlisted hook stops it from loading.

## Isolation

- Call sites use `ExtensionManager::dialoguePrompt()` and `::publish()`, which never throw. With nothing enabled
  they cost one `is_file()` check and return the input.
- A handler that throws, or a filter returning an invalid value, is logged and skipped; the value continues
  unchanged and other extensions still run. A load failure disables only that extension (`failures()`).
- **Auto-disable**: every failure (throwing handler, invalid filter result, load failure) is counted per extension
  in `conf/extensions.json` (`failures`). A success resets the count. At `FAILURE_LIMIT` (5) consecutive
  failures the extension is removed from `enabled` and recorded in `auto_disabled`; an explicit `enable` clears
  both. The state file is only written when a count changes.
- Not built (chevo has them): handler time limits, uninstall with data removal, extension distribution.

## Management

- HTTP: `GET /api/v1/extensions`, `POST /api/v1/extensions/{id}/enable|disable` (`api/v1/extensions.php`). The id
  must be an installed folder or still listed as enabled; answers carry a status code
  (`enabled`, `disabled`, `invalid`, `load_failed`, `auto_disabled`), never exception text.
- UI: `ui/extensions.php` (Config Hub, World & Behavior > Extensions).
- CLI: `php bin/extensions.php`.

## Deferred events and the ExtensionEvents worker

`emit()` runs event handlers inline unless a `DeferredEventSink` is installed, in which case it calls
`defer($extensionId, $hook, $payload)` once per extension. `ExtensionManager::instance()` installs the
file-backed `FileEventQueue` (`data/extensions/events/`, capped at 500 events of 64 KB, a full queue drops the
new event) only when `conf/extensions.json` sets `"deferred_events": true`, because nothing delivers events
unless the worker runs: `php bin/worker.php extension-events` (or the supervisor). The worker claims events,
calls `ExtensionManager::deliver()`, and drops events whose extension was disabled meanwhile. Events are
best-effort and consumed once; the default stays inline delivery.

## Routes, workers, store and tables

An extension declares what it adds in its manifest: `permissions` (`http.routes`, `storage.tables`) and `provides`
(`routes`: `["GET notes/{name}"]`, `workers`: `["sync"]`). Registering something undeclared stops the extension from loading.

- **Routes**: `$registrar->route('GET', 'notes/{name}', $handler)` answers `/api/v1/ext/<id>/notes/<name>`
  (`api/v1/ext.php`). The id must be an enabled extension in `conf/extensions.json` (nothing else is ever loaded), ids and
  segments are plain names, the browser-origin and Host guards of the dashboard routes apply, a body is a JSON object up to
  64 KiB (`api/index.php` limit), and the handler gets an `ExtensionRoute` and returns an array body or `{status, body}`.
  A throwing handler or invalid result answers a generic 500 and counts as a failure (five switch the extension off).
  The route is under the existing `api/v1/**` rule of the request boundary, so no new web path opens.
- **Workers**: `$registrar->worker($worker)` (a `Synth\Workers\WorkerInterface`) runs as `php bin/worker.php <id>:<name>` and
  in the supervisor's general lane (`WorkerRegistry::names()` and `lane('general')` include enabled extensions' declared
  workers, read from manifests without loading them). `GuardedWorker` turns an exception into a failed report and an
  extension failure.
- **Store**: `$context->store()` (`get`, `set`, `delete`, `keys($prefix)`) is `core_extension_store` per extension id (values up to
  1 MB, 1,000 keys). The first use after upgrading imports the old `data/extensions/<id>.json` and renames it `.imported`.
  Without a database session the file store is used.
- **Tables**: `$context->database()` (needs `storage.tables`): `table('notes')` is `ext_<id>_notes`; `fetchAll`, `fetchOne`
  and `execute` take one checked statement with named parameters.
- **Migrations**: `extensions/<id>/migrations/NNNN_name.sql`. `MigrationChecker` allows only table, index and sequence
  statements and row changes, one statement at a time, and every relation must start with `ext_<id>_`; functions, triggers,
  views, schemas, COPY, GRANT, DO, subselects, comma joins, quoted identifiers and other schemas are refused. A refused file
  makes the manifest invalid. `php bin/extensions.php enable <id>` or `migrate <id>` applies pending files, each in one
  transaction with its `core_extension_migration` row (SHA-256); a changed applied file is refused. The web `enable`
  endpoint cannot run migrations (no booted database session): use the command line first.

## Writing an extension

See `extensions/talk-awareness/`: a manifest, an entry class implementing `ExtensionInterface` in namespace
`Synth\Extensions\<Id>\`, and `register()` that attaches handlers with `$registrar->filter()` / `->on()`.
