# Herika-style server file layout

Synthserver already uses the feature layout adopted by LorkhanServer in
`82fc0abfab1b027b43b18bb65594c90c455892df` (2026-09-05). Its earlier shared-stack
alignment, `d205825`, is an ancestor of the current parity branch. No second
layout migration or compatibility tree is needed.

## Mapping

| Responsibility | Synthserver and Herika-style location |
| --- | --- |
| HTTP entry points | Root PHP files, including `index.php` and `main.php` |
| Configuration samples and loader | `conf/` |
| Shared runtime helpers and core models | `lib/`, `lib/core/` |
| LLM connectors and templates | `connector/` |
| Dialogue processing | `processor/` |
| Prompt assembly and policies | `prompts/` |
| Speech adapters | `tts/`, `stt/` |
| Background services | `service/`, `service/processors/` |
| Model action definitions | `functions/` |
| Factory data and baseline SQL | `data/` |
| Schema updates and core table definitions | `debug/db_updates.php`, `lib/core/database_schema/` |
| Management pages, APIs and shared templates | `ui/`, `ui/api/`, `ui/core/`, `ui/tmpl/` |
| Styles, scripts and images | `ui/css/`, `ui/js/`, `ui/images/` |

Do not introduce `public/`, `src/`, `config/`, `resources/`, `database/` or
`workers/` as parallel runtime roots. The existing release audit enforces this
and requires the shared feature folders and representative canonical files.
CI already runs that audit; it does not require a sibling repository checkout.

## Deliberate product differences

- `main_synth_pipeline.php`, `lib/synth_*.php`, Fallout catalogs and the SYNTH
  theme remain product-specific. Matching location does not mean replacing code
  with Skyrim or Morrowind behavior.
- Versioned native contracts stay in `protocol/`; tests in `unittests/`; source
  documentation in `docs/`; deployment/bootstrap tools in `tools/`.
- SYNTH keeps its existing versioned schema update chain. LorkhanServer's
  `data/migrations/`, Composer classmap and worker names are not prerequisites
  for Herika folder parity and are not imported.
- No excluded Skyrim endpoint, asset, extension or feature is added merely to
  make file inventories identical. Existing optional `ext/` and `itt/` stay put.

## Local deployment and persistent state

`tools/deploy-synthserver-wsl.ps1` copies the source feature layout directly to
`/var/www/html/Synthserver` in the WSL distribution selected by `-Distro`; it does not introduce a nested
`public/` web root. The installed health endpoint is
`http://127.0.0.1:8087/Synthserver/health.php`. The standalone development server
in `start-dev.ps1` separately uses port 8085.

The existing deploy excludes and preserves `conf/conf.php`, `log/`, `logs/`,
`soundcache/`, `cache/`, `media/`, `db/`, `uploads/`, `data/pictures/gallery/`,
`data/plugin_packages/` and `data/voices/`. Runtime-created folders are not
evidence of source-layout drift. Do not move or delete persistent state for
folder parity. This layout check requires no database migration, provider call,
client rebuild or game startup.

## Evidence boundary

Compared on 2026-09-06 against HerikaServer
`1aa2021d854d7a2811b89c0a06c23e09284e2169` and Synthserver's pre-audit
`37a1be8`: 282 of 442 tracked files in the compared runtime feature directories
have exactly matching, case-sensitive Herika relative paths. The remaining
files include SYNTH-specific integrations and newer features, not a parallel
application layout. This is file placement evidence, not full feature parity
or in-game response/save-load validation.

Validation: release audit passed for 640 tracked files, 392 PHP files, 96 JSON
files and 12 canonical directories. Nine in-memory probes exercised the actual
audit guard: valid tree, all six rejected legacy roots, missing service directory
and incorrect directory casing. The installed server has all 12 directories,
none of the six legacy roots, and 13 representative entrypoint/helper files
matching the source byte-for-byte. Its health endpoint returned HTTP 200.
Only audit/documentation changed, so no runtime redeployment was necessary.
