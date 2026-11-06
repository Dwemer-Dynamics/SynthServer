# ChatGPT-only startup update

This update is for an existing configured local SYNTH runtime using the managed
ChatGPT bridge. Close Fallout 4 before changing startup files. Keep backups of the
files being replaced; keep private configuration, voices, database volumes and
managed ChatGPT sign-in in place.

The startup archive mirrors the existing local runtime layout:

| Archive path | Existing runtime destination |
| --- | --- |
| `codex/installed/codex_bridge.py` | same relative path |
| `codex/installed/codex_rpc.py` | same relative path; unchanged RPC implementation |
| `codex/installed/codex_isolation.py` | same relative path; new isolation checker |
| `codex/installed/codex_startup.py` | same relative path; new startup checks |
| `codex/run-bridge.sh` | same relative path |
| `Start-CodexBridge.ps1` | runtime root |
| `launcher/Start-SYNTH-Launcher.ps1` | launcher directory |

The existing `Start-SYNTH.ps1`, bridge stop helper, `SYNTH.exe`, launcher settings,
container setup and desktop shortcut are retained. The entry point expects the
existing `codex/stop-bridge.sh` helper and the existing private bridge configuration
under the WSL user's local SYNTH state directory. A fresh installation still needs
the normal local-runtime/provider setup; this archive contains no credentials.

Open the normal SYNTH Launcher shortcut with Fallout closed. Startup checks the
current desktop executable against a local isolation stub, verifies a real fresh
ChatGPT reply, then opens the launcher. It keeps the model on `gpt-6.1-sol` and has
no alternate LLM fallback. Startup failures show an actionable message and leave
the launcher closed. If the desktop updates during a running session, finish the
session, quit Fallout and restart SYNTH to recheck the new executable.

The bridge remains restricted to its configured private interface and existing
local credential. No managed-account bearer token is exported or copied.
