# api/v2: native dialogue dispatch (internal)

**Status: stays. It is not a second public HTTP version and `api/v1` does not replace it.**

`api/v2/` is the internal dispatch layer behind `main.php`. `main.php` decodes and validates the wire event once,
asks `synth_api_v2_route($event)` (`routes.php`) which events have an explicit native route, and for those calls
`synth_api_v2_dispatch()` (`dispatch.php`, output through `ProtocolOutput`). Every other event keeps the legacy
pipeline (`main_synth_pipeline.php`).

| `routes.php` route | Wire events | Handled by |
| --- | --- | --- |
| `conversation` | `input_text` with `payload.intent = conversation` | `DialogueManager` through `Bootstrap` |
| `speech_receipt` | `speech_receipt` | `ReceiptManager` |
| `save_created` | `save_created` | `SessionManager`, `SaveState` |
| `location_catalog` | `location_catalog` | `LocationManager\LocationCatalog` |
| `narrator` | `narrator_command`, `narrator_turn`, `narrator_receipt` | `Narrator\PrivateNarratorManager` |

## Relationship to `api/v1`

- `api/v1/<area>/` routes are thin adapters that include the root entry point (`main.php`, `gamedata.php`,
  `stt.php`, `itt.php`, `tts_status.php`). For the event routes (`dialogue/*`, `user/input`, `save/*`,
  `narrator/checkpoint`, `locations/save`) the work still reaches `api/v2/dispatch.php` through `main.php`, so v1
  *calls* v2; it does not duplicate it.
- The F4SE client posts to the root `main.php` (client `tests/fake_server` asserts `/main.php`) and does not call
  `/api/v1` yet. Removing or moving `api/v2` today would break live client traffic.
- `api/v2/*` files are not reachable over HTTP: `/api/v2/dispatch.php` answers 404 through the request boundary
  (`RequestBoundaryTest`).

## When it can go

`api/v2/dispatch.php` can be folded into managers behind `api/v1` handlers once (a) the client posts to the `api/v1`
routes, (b) each route's logic sits behind its manager (`ConversationManager`, `MessageHandler`, ...), and (c)
`main.php` is only a compatibility shim. Until then `routes.php` is the single place that decides native versus
legacy, and the `Synth\Api\V2\` PSR-4 mapping in `composer.json` stays.
