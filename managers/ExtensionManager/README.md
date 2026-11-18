# ExtensionManager

`Synth\Managers\ExtensionManager`: the host for SYNTH server extensions (`extensions/<id>/`), modelled on
chevo's ExtensionManager but much smaller. An extension adds behaviour through hooks; core code never includes
extension files.

## Contract

- **Discovery**: `extensions/*/extension.json`, validated by `ManifestValidator` (required `id`, `name`, `version`,
  `api`, `entry`, `autoload`; optional `description`, `authors`, `license`, `homepage`, `hooks`, `settings`).
  Unknown fields are refused and all problems are reported together. `id` equals the folder name; the autoload
  namespace is inside `Synth\Extensions\`.
- **State**: `conf/extensions.json` (git-ignored): `{"enabled": [ids], "settings": {id: {name: value}}}`. No
  table is used. A missing file means nothing is enabled; running hooks never writes it. Per-extension data
  goes through `ExtensionContext::store()` into `data/extensions/<id>.json`.
- **Manage**: `php bin/extensions.php list | validate | enable <id> | disable <id>`. Only a valid manifest can be
  enabled. Installing a folder changes nothing until it is enabled.
- **Trust**: an enabled extension runs inside the server's PHP process.

## Hooks (API 1.0)

| Hook | Kind | Fires | Payload |
| --- | --- | --- | --- |
| `dialogue.context.sections` | filter | `DialogueManager::run`, after the prompt is built | context (`character_key`, `npc_name`, `player_name`, `world`) + `sections` (name => list of lines). Lines are XML-escaped and appended to the system message; names, counts and sizes are bounded. |
| `dialogue.prompt.messages` | filter | right after the sections | context + `messages` (role/content list; shape checked). |
| `game.context.received` | event | `synth_protocol_admit` after a `context` event is committed | `session_id`, `snapshot_sequence`, `world`. |
| `dialogue.reply.completed` | event | `DialogueManager::run`, after the reply completed | `request_id`, `character_key`, `npc_name`, `player_name`, `lines`, `world`. |

An extension lists every hook it uses in `hooks`; registering an unlisted hook stops it from loading.

## Isolation

- Call sites use `ExtensionManager::dialoguePrompt()` and `::publish()`, which never throw. With nothing enabled
  they cost one `is_file()` check and return the input.
- A handler that throws, or a filter returning an invalid value, is logged and skipped; the value continues
  unchanged and other extensions still run. A load failure disables only that extension (`failures()`).
- Not built yet (chevo has them): auto-disable after repeated failures, handler time limits, per-extension
  migrations/tables, UI and HTTP management.

## Seam for the ExtensionEvents worker

`emit()` runs event handlers inline unless a `DeferredEventSink` is passed to the constructor, in which case it
calls `defer($extensionId, $hook, $payload)` once per extension. The future `workers/ExtensionEvents` worker
stores those rows and calls `ExtensionManager::deliver($extensionId, $hook, $payload)`. Nothing in this PR queues.

## Writing an extension

See `extensions/talk-awareness/`: a manifest, an entry class implementing `ExtensionInterface` in namespace
`Synth\Extensions\<Id>\`, and `register()` that attaches handlers with `$registrar->filter()` / `->on()`.
