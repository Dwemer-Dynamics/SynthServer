# SYNTH API v1 (`/api/v1/<route>`)

Entry shape of chevo: `api/index.php` -> `api/routes.php` -> `api/v1/<area>/`. Every handler is a thin
adapter. It checks that the request belongs to its route (the wire `type`), then includes the legacy root
entry point that already implements it, so the new surface and the old one answer the same way and share
every validation, size limit and rate limit. The plugin still posts to the root scripts; `protocol/` is
unchanged and the client does not call these routes yet.

| Route | Method | Delegates to | Accepted `type` |
| --- | --- | --- | --- |
| `health`, `health/{live,db,api-keys-configured,npcs-available,llm}` | GET | `health.php` (`?check=`) | n/a |
| `context/event` | POST | `main.php` | `context` |
| `dialogue/request` | POST | `main.php` | `activate`, `trigger`, `diary_request`, `action_result`, `social_task_request`, `social_task_progress`, `halt` |
| `dialogue/spoken` | POST | `main.php` | `speech_receipt` |
| `dialogue/cancel` | POST | `main.php` | `cancel` |
| `user/input` | POST | `main.php` | `input_text`, `input_audio` |
| `save/loaded` | POST | `main.php` | `init` |
| `save/created` | POST | `main.php` | `save_created` |
| `narrator/checkpoint` | POST | `main.php` | `narrator_command`, `narrator_receipt` |
| `locations/save` | POST | `main.php` | `location_catalog` |
| `books/read` | POST | `main.php` | `context` with `payload.reading_event` |
| `npc/add` | POST | `gamedata.php` | `actor_profile` |
| `plugins/manifest` | POST | `gamedata.php` | `loaded_plugins` |
| `speech/transcribe` | POST | `stt.php` | multipart, validated by `stt.php` |
| `vision/describe` | POST | `itt.php` | JSON, validated by `itt.php` |
| `messages/<id>` | GET | `tts_status.php` (`cache_key`) | n/a |
<<<<<<< HEAD
=======
| `tts/studio` | POST | `ui/api/player_tts_preview.php` | none; management guard and dashboard token |
| `tts/voice-preview` | POST | `voice/preview.php` | none; management guard and dashboard token |
| `tts/voice-samples` | POST | `voice/samples.php` | none; management guard and dashboard token |
| `tts/voice-sample` | POST | `voice/samples.php` with `voice_id` or `npc_id` required | none; management guard and dashboard token |
| `save/created` | POST | none | 501: the client sends no save-created signal |
>>>>>>> origin/unstable
| `edit`, `edit/...` | any | none | 501: the generic edit API is a later PR |
| `extensions` | GET | `ExtensionManager` | n/a (list) |
| `extensions/{id}/enable`, `extensions/{id}/disable` | POST | `ExtensionManager` | n/a (id must be in the registry; same-origin guard) |

A known route with another method answers 405 with `Allow`; an unknown route answers 404; a request of another
route's type answers 422 `route_type_mismatch`. Errors use the legacy `synth.error.response.v1` envelope with
generic text.

Serving: the `.htaccess` rewrite sends `/api/v1/*` that is not a real script (for example
`api/v1/voice/*.php`) to `api/index.php`; `router.php` does the same for the development server; without
rewriting, `/api/index.php/v1/<route>` works.

Tests: `unittests/tests/ApiV1RoutesTest.php` sends the same probe to the legacy script and the new route and
requires the same answer.

TODO (separate PRs): the generic edit API (`edit`), and moving each
handler's logic behind a manager as its domain moves (see `docs/CHEVO-PARITY.md`).
