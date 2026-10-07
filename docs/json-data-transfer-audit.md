# SYNTH JSON Transfer Contract

SYNTH no longer supports old delimiter-based game envelopes. The Fallout 4 and
Fallout 4 VR F4SE clients send closed JSON event objects and receive correlated
NDJSON response lines only.

The canonical contract lives under `protocol/v1/` in both repositories. Keep
schemas, fixtures, and `manifest.sha256` byte-identical whenever a payload shape
changes.

## Event Request

Game events are sent as `POST` requests with `Content-Type: application/json`.

```json
{
  "schema": "synth.event.v1",
  "protocol_version": 1,
  "type": "context",
  "session_id": "session-flat-001",
  "request_id": "request-context-001",
  "turn_id": "turn-context-001",
  "generation": 7,
  "game": "fo4",
  "runtime_variant": "flat",
  "client_version": "0.1.0",
  "runtime_version": "1.10.163",
  "capabilities": ["context.actor_identity", "context.world"],
  "payload": {
    "playthrough_id": "save-001",
    "snapshot_sequence": 12,
    "player": {
      "form_id": "0x00000014",
      "origin_plugin": "Fallout4.esm",
      "playthrough_id": "save-001",
      "display_name": "Sole Survivor"
    },
    "target": {
      "form_id": "0x0001A4D7",
      "origin_plugin": "Fallout4.esm",
      "playthrough_id": "save-001",
      "display_name": "Preston Garvey"
    },
    "audience": [],
    "active_quests": [],
    "nearby_items": [],
    "points_of_interest": []
  }
}
```

## Event Response

Server responses use generation-bound `synth.response.line.v1` NDJSON. The
response starts once, ends once, and carries the same request, turn, generation,
game, and runtime lane on every line.

```json
{"schema":"synth.response.line.v1","protocol_version":1,"type":"response_start","line_id":"line-1","request_id":"req-text-1","turn_id":"turn-text-1","generation":2,"game":"fo4","runtime_variant":"flat","payload":{"server_version":"0.7.2"}}
{"schema":"synth.response.line.v1","protocol_version":1,"type":"dialogue","line_id":"line-2","request_id":"req-text-1","turn_id":"turn-text-1","generation":2,"game":"fo4","runtime_variant":"flat","payload":{"speaker":{"form_id":"0x0001A4D7","origin_plugin":"Fallout4.esm","playthrough_id":"save-1","display_name":"Preston Garvey"},"text":"Sanctuary is holding together.","speech":{"schema":"synth.speech.v1","utterance_id":"utterance-1","cache_key":"0123456789abcdef0123456789abcdef","status":"pending","deadline_ms":30000}}}
{"schema":"synth.response.line.v1","protocol_version":1,"type":"response_end","line_id":"line-3","request_id":"req-text-1","turn_id":"turn-text-1","generation":2,"game":"fo4","runtime_variant":"flat","payload":{"status":"complete"}}
```

Actions are typed, capability-gated response lines:

```json
{"schema":"synth.response.line.v1","protocol_version":1,"type":"action","line_id":"line-action-1","request_id":"req-text-1","turn_id":"turn-text-1","generation":2,"game":"fo4","runtime_variant":"flat","payload":{"action":{"schema":"synth.action.v1","action_id":"action-1","idempotency_key":"idem-action-1","name":"inspect_actor","capability":"action.inspect_actor","actor":{"form_id":"0x0001A4D7","origin_plugin":"Fallout4.esm","playthrough_id":"save-1"},"target":{"form_id":"0x0001A4D7","origin_plugin":"Fallout4.esm","playthrough_id":"save-1"},"arguments":{},"deadline_ms":30000}}}
```

## Actor And Gameplay Metadata

Runtime metadata belongs in JSON payloads, not delimiter strings:

- `gamedata.php` accepts JSON for inventory, equipment, stats, voice metadata,
  skills, activity, transformation, market stock, and plugin manifests.
- `stt.php` accepts microphone audio as `multipart/form-data` with the WAV in
  `file` and all game metadata in a JSON `metadata` part using
  `schema: "synth.stt.v1"`. Responses use `synth.stt.response.v1`.
- `vsx.php` accepts voice uploads as `multipart/form-data` with the binary
  sample in `file` and all game metadata in a JSON `metadata` part using
  `schema: "synth.voice_sample.v1"`.
- Processor events such as `funcret`, `setconf`, `captured_dialogue`, and
  `updateprofiles_batch_async` carry JSON payloads in the event `text` field.
  Non-JSON payloads are rejected or ignored instead of parsed as fallback text.
- `addnpc` and actor snapshot requests should carry structured actor fields for
  identity, SPECIAL, skills, equipment, health/AP/XP/karma, factions,
  reputation, voice data, and profile hints.
- Nearby people and audience data should be JSON actor arrays. Internal fallback
  text fields may exist for prompt/search compatibility, but they must be
  derived from the JSON source instead of transferred as the source of truth.
