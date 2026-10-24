# Local Windows launcher

`tools/windows-launcher/` contains the source for a Windows `SYNTH.exe` launcher
for an existing SYNTH installation in WSL/Docker. Opening it resumes the configured
database, backend and local Whisper containers, checks readiness, and opens the
web dashboard once. **Launch Fallout 4** is a separate, explicit F4SE button.
Closing the launcher leaves the services running.

This first implementation supports the existing three-container topology with
project ownership labels, two named data volumes and one owned network. The
backend shares the database container's network namespace. HTTP and speech ports
must bind to literal `127.0.0.1`; PostgreSQL has no published host port. It is a
launcher for a configured installation, not a fresh-machine installer.

## Build and configure

Windows, the .NET 8 SDK and its Windows Desktop reference pack are needed to build;
the resulting application needs the .NET 8 Desktop Runtime. No NuGet packages are
downloaded. Generated files must stay outside the checkout:

```powershell
& .\tools\windows-launcher\build.ps1 -OutputDirectory C:\SYNTH-Build
```

The script builds the application and runs its deterministic console tests before
copying the runnable files to the output's `launcher` directory. It preserves an
existing `SYNTH.launcher.json` there. Keep the executable, DLLs and runtime JSON
files together; the executable is not a self-contained runtime bundle.

Edit the adjacent `SYNTH.launcher.json` using the provided sample. It accepts only
the documented fields: WSL distribution, resource names, project ownership label,
local backend/speech URLs, startup deadline and an optional F4SE loader path.
The game path must be a local absolute path to `f4se_loader.exe`; command arguments,
UNC paths, remote endpoints and arbitrary startup commands are rejected. When the
loader is absent or unconfigured, the game button stays unavailable. Configuration
contains no provider keys. Keep machine-specific configuration outside source.

The optional Codex bridge retains its existing separate startup script. This
launcher does not start or validate that optional route, contact dialogue/TTS
providers, or transcribe microphone audio. It checks core backend and local speech
readiness. Backend readiness requires `database`, `schema` and
`background_processor` to be true as well as the expected service/status fields.

## Startup and failure behavior

All required containers, named volumes and the network are inspected before any
start operation. The launcher verifies ownership, saved-data mounts, network
identity and local port bindings, then starts only stopped containers by immutable
Docker ID, in database/backend/speech order. Running containers are left running.
It does not create resources, deploy source, initialize configuration, run
migrations, delete files, stop containers, or replace an installed game plugin.

Missing, paused, foreign-owned or mismatched resources produce a bounded failure.
Cancel ends the launcher's pending operation; a service that has already started
remains running. Readiness polling has a configurable deadline. Raw Docker errors,
environment variables, HTTP bodies and provider settings are not displayed.

The dashboard opens once after successful startup when its checkbox is enabled.
The game button performs a fresh read-only readiness check and refuses a duplicate
game launch. Neither readiness checks nor dashboard opening launch Fallout.

A command-line check performs only metadata inspection and local health reads:

```powershell
dotnet C:\SYNTH-Build\launcher\SYNTH.dll --check
```

It returns JSON with independent backend/speech readiness and exits zero only when
both are ready. `--config <path>` selects another local configuration file.

## Validation boundary

Deterministic tests use fake process and health adapters to exercise ownership,
missing resources, mount/network/port mismatches, ordered immutable starts,
idempotence, health failures, deadlines, cancellation, configuration rejection and
error redaction. They do not touch a real database or call providers.

Compilation, a synthetic form rendering and a read-only check against an existing
installation establish separate evidence. Manual Windows interaction, actual
stopped-service startup, browser opening and the explicit F4SE game launch still
need user acceptance. They do not establish Fallout dialogue, radio or VR behavior.
