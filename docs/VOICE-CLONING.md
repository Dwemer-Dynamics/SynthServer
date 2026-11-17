# Voice sample imports

`POST /vsx.php` accepts multipart `metadata` JSON and an optional binary `file`.
New clients use `synth.voice_sample.v2`; legacy v1 metadata remains supported with stricter
path/size validation. The coordinated request and response schemas live in `protocol/v1/schemas`.

V2 `probe` checks whether a sample exists and its selected local backend is ready. V2 `import`
saves a missing sample, without replacing an existing sample. Both operations require an active
session and matching generation. Validation errors are clean JSON; stale sessions return 409,
missing samples 404, and an already-running import for the same voice 423.

Samples are bounded to 8 MiB before/after optional zlib decompression. FUZ headers and lip-block
bounds are checked before conversion. Linux ffmpeg conversion has a 25-second timeout and produces
mono 22.05 kHz PCM WAV, up to 15 seconds. Samples shorter than 3 seconds are rejected, not padded
with silence to make them appear long enough. A same-directory rename commits each WAV atomically.

Runtime state stays under `data/voices`: normalized WAV, source/checksum metadata, and per-voice
lock. Automatic candidates are not transcript-reviewed. Existing TTS Studio remains the place to
preview, replace, or assign voices. Do not package or distribute extracted game recordings.

Responses distinguish `saved` from `ready`. Local audio.cpp PocketTTS consumes readable WAV paths
via `voice_ref`; it does not support the Python `/upload_sample` route. No cloud cloning is enabled.
Detected `voice_type` fills a blank NPC voice through the existing profile upsert and never replaces
a manual voice choice. Speech-cache keys include the current sample SHA-256, and resolver misses
do not permanently hide samples imported by another worker.

Local extraction/upload/generation probes do not prove in-game playback or voice likeness.

## Silent samples

An import is refused with a 400 `Voice sample is silent` when the decoded WAV holds only zero samples (the whole
decode is streamed, however long), and
`vsx.php` stores nothing: an existing sample of that voice is left as it was. Any nonzero sample, however quiet,
passes (loudness normalisation runs before the check). A decode that yields no readable PCM WAV is refused the
same way. TTS Studio's own WAV upload applies the same check and reports the file by name.

## Stored sample list

`POST /api/v1/voice/samples.php` is a read-only management route: the Host allowlist, same-origin guard and the
dashboard session token apply, as for every management route. It is POST rather than GET because the answer includes
sample transcripts, so a cross-site page must not be able to read it; it writes nothing. Body (JSON, at most 4096
bytes): `{"csrf_token": "...", "voice_id": "<id>"?, "npc_id": N?, "include_hash": true?}`; `voice_id` and `npc_id` are
alternatives, and the token may instead be sent as `X-CSRF-Token`.

It answers `{"ok":true,"voice_samples":[...],"count":N}` with, per sample in `data/voices`: `voice_id`, `bytes`,
`duration_seconds`, `sample_rate`, `playable`, `stored_at`, `source` (the game file recorded by the import, when
known), `selection`, `has_reference_text` and `reference_text`. It returns no file paths, provider settings or keys.
The SHA-256 is not in the default list, because it reads every file: ask for it with `include_hash` together with
`voice_id` or `npc_id` (one voice). `npc_id` resolves that NPC's saved voice and adds
`npc: {id, name, voice_id, has_sample}`. Bad input answers 400, a wrong or missing token 403, an unknown NPC 404, a
body over 4096 bytes 413.

## Voice preview

`POST /api/v1/voice/preview.php` speaks one short line with an NPC's saved voice and answers the audio itself
(`200 audio/wav`, `Cache-Control: no-store`). Body: `{"npc_id": 12, "text": "...", "csrf_token": "..."}`; `text`
is optional (a fixed line is used) and has 1 to 300 characters without control characters. The token is the
dashboard session token the Voice Samples tab of TTS Studio embeds (`X-CSRF-Token` also works); a POST without it
is refused with 403 before any provider is contacted. The body is JSON of at most 4096 bytes with a
Content-Length, and no other field is accepted.

The connector is the one dialogue would use: the NPC's profile connector, else the preferred default. The voice is
the NPC's saved voice, resolved to a local sample as in dialogue. An NPC without a saved voice is spoken with the
connector's fallback voice, as in dialogue, and the reply carries `X-Voice-Fallback: 1`. Nothing is saved: no
speech row, memory, event or NPC change. The audio is written to `soundcache` under a random name, read back and
deleted with its request log at once.

Every preview is one provider call and may be charged, so one preview runs at a time (a second answers 429
`busy`) and the provider call is bounded by a 20 second socket timeout. Failures answer
`{"ok":false,"code":...,"error":...}` with fixed text, never the endpoint or provider reply: 400 `invalid_input`,
403 `csrf`, 404 `unknown_npc`, 411, 413, 415, 422 `tts_not_configured` (no connector, or driver `none`) or
`no_voice` (a silent-marker voice), 429 `busy`, 502 `provider_failed`, 503 `unavailable`.

## Reference text

A sample's transcript is kept as `reference_text` (up to 4096 bytes, no control characters) in the sidecar
`data/voices/<voice>.json` written by `vsx.php` and shown by the list. It is for cloning providers that take a
transcript with the sample. No connector shipped with SYNTH sends it with a synthesis request today (OmniVoice
transcribes imports itself with local STT), so it is stored and listed but not forwarded.
