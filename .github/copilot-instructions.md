# Synthserver Repository Instructions

- Synthserver is the backend and web UI for the native SYNTH Fallout 4/F4SE and Fallout 4 VR/F4SEVR clients.
- Start every feature branch from origin/alpha. Open every PR as a draft targeting alpha.
- Never use the other Dwemer repos' unstable-first flow. See CONTRIBUTING.md.
- Keep plugin/server contracts structured as JSON with explicit schema names.
- Database changes must be represented in the baseline or migration chain as
  appropriate and remain safe for existing installs.
- Do not commit runtime configuration, secrets, logs, caches, generated speech,
  database dumps, or vendored test dependencies.
- Preserve SYNTH-specific paths and naming; do not introduce HerikaServer
  runtime paths into active code.
- Run `tools/audit-release-tree.ps1` before publishing changes.
