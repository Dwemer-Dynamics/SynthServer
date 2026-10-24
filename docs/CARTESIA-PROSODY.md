# Cartesia request prosody

This change is a source candidate, not deployment or audible acceptance evidence.
The Cartesia driver uses `lib/cartesia_generation.php` to build requests for Sonic 3,
3.5, 3.6, dated Sonic 3 variants and `sonic-latest`. Earlier models keep their existing
API version, authentication header, voice object and top-level speed field.

Modern requests use `Cartesia-Version: 2026-08-14`, bearer authentication, the existing
voice ID and `generation_config.speed`. The five existing speed choices map to 0.6,
0.85, 1.0, 1.15 and 1.5. Numeric input is bounded to the provider's 0.6–1.5 range.
The WAV format, media handling, clone ownership and cancellation path are unchanged.

The connector's **use_emotions** option is disabled by default. When enabled for
English speech, the current line's supported mood supplies `generation_config.emotion`.
Existing compound moods use the first component; recognized synonyms use provider
vocabulary. Automatic, unknown, malformed and non-English moods omit the guidance,
letting Cartesia interpret the transcript. Spoken text is never wrapped in emotion tags.
This change consumes the existing mood; it does not add diary-based mood inference.

Quickstart retains an already configured, supported provider in its service choices.
This prevents saving unrelated quickstart settings from replacing Cartesia or Local
Whisper merely because they are outside the recommended lists.

Queued speech continues to use its captured provider settings. Changing the current
connector after enqueue cannot alter that reply's language, speed, voice or mood.
The existing plan digest already distinguishes mood and connector settings.

## Validation

`unittests/tests/CartesiaProsodyTest.php` covers modern and legacy wire formats,
English-only opt-in guidance, natural delivery, input bounds, actual driver requests
through a fake HTTPS stream, captured worker settings and speech-cache identities.
These tests need no credentials, provider network access or database.

Before deployment acceptance, use an isolated SYNTH profile and verify real provider
responses and audible results with one assigned voice: natural, calm, sad and angry
lines; emotion disabled/enabled; slow/normal/fast; a queued reply while changing the
connector; cancellation and save reload. Provider success alone does not prove in-game
playback, lip sync, tone quality or clone identity.

Provider references:

- [Bytes API](https://docs.cartesia.ai/api-reference/tts/bytes)
- [Speed and emotion guidance](https://docs.cartesia.ai/build-with-cartesia/capability-guides/volume-speed-emotion)
