# Drivers

Provider integrations, one kind per folder: `llm/`, `tts/`, `stt/`, `itt/`. Namespace
`Synth\Drivers\<Kind>\*` (`Llm`, `Tts`, `Stt`, `Itt`).

## Shape of a kind

```text
drivers/<kind>/
  <Kind>DriverInterface.php   what every provider of this kind offers
  <Kind>Wrapper.php           picks the provider, applies settings, returns a provider-neutral result
  Drivers/<Provider>.php      one class per provider
```

Add request/response value types, exceptions and HTTP helpers beside the interface.

## What belongs here

Turning a provider-neutral request into one provider's call and back. A driver may call only
`core/contracts`. It never reads game state, settings or entities: the caller passes everything in. It
never writes to the database.

## Status

Every kind now has an Interface and a Wrapper (class names follow the PHP casing used here: `SttWrapper`,
`IttWrapper`, `LlmWrapper`, `TtsWrapper`).

- `stt/` and `itt/`: one driver per provider under `Drivers/`. They take plain config (URL, model, key) and a request
  value, and throw `SttException` / `IttException`. The wrapper creates the driver from the connector's `driver` value.
  The caller resolves settings and the API key through `ApiBadge` and the shared key store; drivers never do.
- `llm/`: `LlmWrapper` is the `SynthCorelmdriver` the managers receive and streams through the first driver that
  supports the stored connector row. `LlmWrapper::legacyConnector()` loads a `connector/*` class by plain name.
- `tts/`: `TtsWrapper` forwards to a `TtsDriverInterface` driver: `ProfileSpeechDriver` (queued NPC speech) or `NarratorSpeechDriver`
  (voiced inline, through `TtsSynthesisInterface::synthesize()`). Each provider is `Drivers/<Name>Driver.php`
  (`TtsProviderInterface`); `tts/tts-<key>.php` is a thin hook that registers it with `TtsProviderRegistry`. `SpeechAudio`
  (WAV validation, transcoding, publication into `soundcache/`) and `SpeechStorage` (status files, published media)
  hold the audio and storage handling; `lib/speech_cache.php` and `lib/npc_tts_status.php` are function delegates.

## Legacy

`connector/` (LLM connectors), `tts/`, `stt/` and `itt/` are frozen for new features; `stt/stt-*.php` and
`itt/itt-*.php` are thin hooks into the wrappers. `connector/*` and `tts/tts-*.php` still hold their provider logic
and `NarratorSpeechDriver` keeps its own contract. New providers go to `drivers/<kind>/Drivers/`.
See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).
