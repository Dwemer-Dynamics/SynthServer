# Request boundary

SYNTH answers the web by **deny by default**, as chevo does. `.htaccess` is the single list: a request that
matches no allowed rule goes to `index.php`, which answers 404 (the bare root redirects to `ui/`). `router.php`
(the `php -S` development server, `start-dev.ps1`) reads the same rules from `.htaccess`, so the two cannot
disagree. Both send `/` to `<base>/ui/`, the page the game client opens.

Unreachable from the web, and kept that way: `debug/` (including the 5,000-line frozen `db_updates.php`),
`bin/` (including `install-db.php`, moved here from `ui/cmd/`), `tools/`, `conf/`, `log/`, `service/`, `workers/`,
`lib/`, `core/`, `managers/`, every other root script, every other `processor/` script, `data/` except the
media listed below, and the dashboard's include-only folders (`ui/tmpl`, `ui/lib`, `ui/tmp`, `ui/data`).

Apache's `Require local` (`tools/apache/synthserver.conf`) still limits the client address, and
`lib/management_request_guard.php` still limits the Host and origin of every `ui/` script. The guard now
delegates to `Synth\Lib\LocalAccess` (loopback, forwarding headers, Host allowlist) and
`Synth\Lib\SameOriginRequest` (Origin, Referer and Sec-Fetch-Site evidence); `Synth\Lib\RequestBody` bounds a
body. The development router answers the dashboard to a direct loopback client only.

## Allowlist

| Path | Why |
| --- | --- |
| `index.php` | the front controller (root redirect, 404) |
| `main.php` | the game client's event endpoint (`/main.php`) |
| `itt.php` | the client's visual-context capture (`/itt.php`) |
| `diary_status.php` | the client's diary status poll |
| `tts_status.php` | the client's external TTS status poll (`?cache_key=`) |
| `vsx.php` | the client's voice sample upload |
| `media.php/media/<64 hex>` | the client's media upload and download; the id shape is fixed |
| `processor/npc_tts_play.php` | the client's external TTS play request |
| `health.php` | readiness probe used by the dashboard (system health, quickstart) and the launcher |
| `api/v1/**` -> `api/index.php` | the version one API, including the dashboard's `voice/preview.php` and `voice/samples.php` |
| `api/index.php/v1/**` | the same routes where mod_rewrite is off |
| `ui/` and `ui/<page>.php` | dashboard pages, except the include-only `ui/profile_loader.php` |
| `ui/core/<page>.php` | dashboard core pages (profiles, connectors, NPC master, API badges) |
| `ui/cmd/<script>.php` | dashboard actions (delete row, gallery tools, AI profile actions), except the include-only `ai_profile_generation_*` and the retired `install-db.php` |
| `ui/api/<script>.php` | dashboard JSON endpoints |
| `ui/addons/pmstt/index.html`, `pmstt_speech.php` | the push-to-talk add-on |
| `ui/tools/check_url.php`, `server_version.php` | URL check and version display used by the dashboard |
| `ui/tests/{itt-test,stt-test,tts-test}.php` | connector test frames opened from the ITT, STT and TTS connector pages |
| `ui/tests/vector-compact-chromadb.php`, `vector-delete-memory_summary.php` | the Memories page's confirmed POST actions |
| `ui/core/tests/{apikey_test,llmtest}.php` | the API key and LLM connector test buttons |
| `ui/{css,js,images,addons/pmstt}/**` static files | dashboard styles, scripts, images and fonts |
| `soundcache/**.{wav,mp3,ogg}` | cached speech the dashboard plays (control panel, cache browser, previews) |
| `data/pictures/**` images | image gallery, Pip-Boy vision gallery and NPC portraits |
| `data/fonts/*.{ttf,otf,woff,woff2}` | the diary font |

## Closed on purpose

- `gamedata.php` and `stt.php` are no longer web entry points. The game client never calls them; the API
  reaches them by include (`/api/v1/npc/add`, `plugins/manifest`, `locations/save`, `speech/transcribe`).
  `unittests/fixtures/legacy_reference_router.php` serves them to the parity test only.
- `data/voices/` is no longer browsable. Voice samples are private; the "View Cache Directory" link is gone.
- `ui/cmd/install-db.php` moved to `bin/install-db.php` (command line only, as before). An installer outside
  this repository that still calls the old path must use the new one.
- `debug/` stays where it is: `core/dbmanager/SchemaMigrations.php` includes the frozen `debug/db_updates.php`
  and `service/processors/middleterm/entrypoint.php` runs `debug/util_memory_subsystem.php` on the command line.
  Neither is reachable from the web now, and both go when their owners move them.
- The `ui/tests` and `ui/core/tests` pages the dashboard opens stay, each by name. Any other file there is closed.

## Request bodies

`api/index.php` settles the declared `Content-Length` before a handler runs: missing answers 411, above the
limit 413, then reads are bounded through `Synth\Lib\RequestBody`.

| Route | Limit |
| --- | --- |
| `POST speech/transcribe` | 16 MiB recording plus 64 KiB multipart framing |
| `POST vision/describe` | 1 MiB (the event; the image goes to `media.php`) |
| `POST voice/preview.php`, `voice/samples.php` | 4096 bytes |

`vsx.php` (the voice sample upload, not routed through the API) applies the same check: 8 MiB plus 64 KiB.

## Errors

No script turns `display_errors` on for a web request. Errors go to the log. `debug/util_memory_subsystem.php`
is command line only and keeps its own setting.
