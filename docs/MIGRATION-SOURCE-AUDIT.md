# Synthserver migration and source audit

Research date: 2026-07-18

Use `REFERENCE-SERVER-DATAFLOW.md` to understand how the source paths below cooperate at runtime.
The migration unit is an owned data flow with tests, not an isolated file copied because its name
resembles a target component.

## Inspected baselines

| Repository | Commit | Use |
| --- | --- | --- |
| `Dwemer-Dynamics/DialecticServer` | `f447a9c6b59bfc689c788fb0139a0d13c6c6dc51` | Primary MIT source and current Fallout NV server feature baseline. |
| `Dwemer-Dynamics/Dialectic` | `eddbdc77a8347128b5cf5bcd68df0c2404fbf074` | Client event/action/context vocabulary and integration behavior. |
| `abeiro/HerikaServer` | `0dbfa3eb4d3197d8159b5ff2c77bfdb5bf98b4d0` | Original PHP lineage and comparison reference. |
| `Dwemer-Dynamics/CHIM` | `77c73ffb6bb32c226340bbda93b3aac5a7ad49f8` | Mature Skyrim client/server design reference; not the code baseline. |

Before copying, re-check the exact license files at the pinned commits. Preserve the MIT license,
copyright notices, attribution, and material third-party notices. Keep an import ledger mapping
each copied top-level path to source commit and later rename/refactor commit. Do not copy generated
runtime data, local configuration, credentials, logs, database dumps, user media, or game assets.

Prefer a history-preserving subtree/filter migration when it can be reviewed cleanly. If a clean
source import is used instead, make the first source-bearing commit an unmodified attributed
baseline, then perform mechanical product/schema renames in a separate commit, then behavior
changes. Never obscure provenance by mixing the import and rewrite.

## Current feature baseline to retain where applicable

### Core pipeline and persistence

- Strict JSON/NDJSON event pipeline and PostgreSQL event log.
- Conversation/request correlation, prompt assembly, response parsing, action output, rechat, and
  unfinished-response handling.
- Long-term memory, middle-term memory, relationships, dynamic profiles, diary, narrator,
  automatic greetings, active quest context, world knowledge, and playthrough snapshots/rollback.
- Request/response logs, queue/job handling, health, backups, schema/bootstrap tools, and release
  audit.

### Connectors

- LLM: OpenAI-compatible JSON, OpenRouter, Google, Groq, KoboldCpp, and Player2.
- TTS: OmniVoice, PocketTTS, Chatterbox, XTTS, Inworld, Cartesia, Piper, ElevenLabs, and Kokoro.
- STT: Deepgram, Parakeet, Whisper, LocalWhisper, Gemini, Azure, Inworld, and explicit none.

Connector parity means a typed, testable adapter with current configuration and failure semantics;
it does not mean every external provider is exercised with a paid key. Verify provider APIs from
official current documentation during implementation because endpoints/models can change.

### Management UI

- Quickstart and configuration.
- Profiles and dynamic prompts/actions.
- Request/response logs and queue status.
- Memory, world knowledge, relationships, and playthrough management.
- Statistics/audit/health, connector status, backup/restore controls.

### Test baseline

The inspected Synthserver audit reported 131 passing PHPUnit tests. Treat that as historical
inventory evidence, not a SYNTH target number. Port applicable assertions, add Fallout 4 and v1
contract coverage, and keep a requirement matrix; a raw test count cannot prove parity.

## Required semantic conversion

| SYNTH/FO4 concept | Synthserver/FO4 target |
| --- | --- |
| Product name `SYNTH` | `SYNTH` client / `Synthserver` backend |
| `synth.*` protocol/schema | `synth.*.v1` protocol and `synth.*` database schema |
| game identity FO4/TTW | exact `game=fo4` plus `runtime_variant=flat|vr`; reject alternatives |
| Commonwealth/Wasteland defaults | Commonwealth/Fallout 4 locations from client context |
| Sole Survivor/player defaults | Sole Survivor/player data from client context |
| 2281/FO4 date assumptions | Fallout 4 context beginning in 2287; actual game state wins |
| xNVSE/temp-file client behavior | native F4SE loopback HTTP/NDJSON contract |
| FO4 form/plugin assumptions | Fallout 4 full form ID plus origin plugin/context identity |
| FO4 action success semantics | FO4 capability negotiation and typed results; disable unsupported/ESP-dependent paths |

After the mechanical pass, CI and release audit must scan source, migrations, fixtures, UI text,
configuration, documentation, SQL, and packaged output for accidental `synth`, `fo4`, `ttw`,
`mojave`, `courier`, xNVSE, old schema names, old ports, and old URLs. Keep explicit names only in
this migration document, license/attribution, or purpose-labeled migration tests.

## Migration sequence

1. Verify licenses and create provenance/import ledger.
2. Import source without runtime data or secrets; prove its original tests in an isolated setup.
3. Establish current Composer/PHP/CI baselines and pin dependencies.
4. Make a reviewable mechanical product/namespace/path rename.
5. Add new `synth.*` migrations and an explicit development-data conversion path if one is useful;
   do not make old production data a requirement for first boot.
6. Replace FO4 defaults/fixtures/prompts/actions/context with FO4 semantics.
7. Implement and freeze the shared v1 contract plus flat/VR fake-client E2E and capability
   filtering tests.
8. Port connectors and UI incrementally behind tests.
9. Run forbidden-pattern, attribution, dependency-license, secret, and release-tree audits.
10. Record deviations from baseline with rationale and required later evidence.
