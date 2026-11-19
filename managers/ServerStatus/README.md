# ServerStatus (health checks)

Read-only health checks behind `health.php?check=live|db|api-keys|npcs|llm|all`
(`Synth\Managers\ServerStatus\HealthCheckManager`). Each check returns `[httpStatus, body]`, never throws, and never
writes, migrates, locks or sends a provider request. Routes, status rules and evidence are in
[docs/HEALTH-CHECKS.md](../../docs/HEALTH-CHECKS.md).

- `live` needs no configuration or database.
- `api-keys` asks the existing `ApiBadge` facade; `npcs` asks `CharacterRepository::availableCount()`.
- `llm` reads only connector url and model, and opens a bare TCP connection to at most five distinct local endpoints (IP literals and localhost), in parallel, within two seconds, one request at a time. Hosted host names are never resolved.

Entry point: `health.php` (a thin dispatcher). Tests: `unittests/tests/HealthChecksTest.php`.
