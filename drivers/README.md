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

## Legacy

`connector/` (LLM connectors), `tts/`, `stt/` and `itt/` are frozen for new features. The existing
classes `drivers/llm/OpenAiCompatibleDriver.php`, `PlayerRewriteDriver.php` and
`drivers/tts/NarratorSpeechDriver.php`, `ProfileSpeechDriver.php` stay until the Interface and Wrapper
exist for their kind. New providers go to `drivers/<kind>/Drivers/`.
See [docs/SERVER-FILE-LAYOUT.md](../docs/SERVER-FILE-LAYOUT.md).
