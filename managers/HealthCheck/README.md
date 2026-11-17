# HealthCheck manager

Read-only health checks behind `health.php?check=live|db|api-keys|npcs|llm|all`
(`Synth\Managers\HealthCheck\HealthCheckManager`). Each check returns `[httpStatus, body]`, never throws, and never
writes, migrates, locks or sends a provider request. Routes, status rules and evidence are in
[docs/HEALTH-CHECKS.md](../../docs/HEALTH-CHECKS.md).

- `live` needs no configuration or database.
- `api-keys` asks the existing `ApiBadge` facade; `npcs` asks `CharacterRepository::availableCount()`.
- `llm` only opens a bare TCP connection to at most five distinct endpoints, in parallel, within two seconds.

Entry point: `health.php` (a thin dispatcher). Tests: `unittests/tests/HealthChecksTest.php`.
