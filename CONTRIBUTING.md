# Contributing to Synthserver

Read [AGENTS.md](AGENTS.md) for the engineering rules and [docs/HANDOFF.md](docs/HANDOFF.md)
for the current state.

## Branches

Start every feature branch from the latest `origin/alpha`. Open every PR as a **draft targeting
`alpha`**. Do not target `unstable`, `dev`, `synth`, or an old feature branch. SYNTH uses an
alpha-first workflow, unlike the other Dwemer mods. `synth` is the GitHub default branch.

`unstable` and `dev` are reserved testing lanes; `synth` is the main/release lane. Maintainers
control promotions explicitly. For dependent work, merge prerequisites into `alpha` before
opening the next focused PR. All PRs still target `alpha`.

Explain the concrete behavior change, list actual checks and remaining limits, and link paired
client/server changes. A build or HTTP check does not prove in-game behavior.

## Rules

- Keep each change focused. Link the paired SYNTH client PR for protocol changes, and mirror
  `protocol/` byte-for-byte.
- Every schema change needs an idempotent migration that works for both fresh installs and
  upgrades. Use a version above the latest one in `debug/db_updates.php`.
- Never commit `conf/conf.php`, credentials, logs, caches, generated audio, voice samples,
  database dumps, `unittests/vendor/`, personal paths, or media without recorded provenance.
- Prefer the existing PHPUnit suites. Add a new test file only when existing suites cannot
  cover a high-risk change.

## Checks before opening a PR

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\audit-release-tree.ps1
git diff --check
```

Run `php -l` on changed PHP files and the relevant PHPUnit tests against a disposable
PostgreSQL database. For UI changes, check the affected pages at desktop and narrow widths.

## Licensing

Submit project contributions under the GNU GPL v3.0 (root `LICENSE`). Inherited code stays
under the root `MIT-LICENSE`, which names no SYNTH copyright holder. Preserve third-party
notices and record third-party material in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
