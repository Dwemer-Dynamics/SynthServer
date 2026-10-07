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
