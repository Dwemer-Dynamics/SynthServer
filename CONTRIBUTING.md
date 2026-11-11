# Contributing to Synthserver

Read [AGENTS.md](AGENTS.md) for the engineering rules and [docs/HANDOFF.md](docs/HANDOFF.md)
for the current state.

## Branches

A PR is either a **topic PR** or a **promotion**.

- **Topic PR.** Start the branch from the latest `origin/unstable` and open it as a **draft
  targeting `unstable`**, from this repository or a fork. A fork branch of any name, including
  one named `unstable`, `dev`, `synth` or `alpha`, is ordinary topic work and must target
  `unstable`. Do not target `dev`, `synth`, the legacy `alpha` branch, or an old feature branch.
  `synth` is the GitHub default branch, so change the preselected base to `unstable`.
- **Promotion.** Only this repository's managed branches `unstable`, `dev`, `synth` and `alpha`
  have promotion semantics. Maintainers promote one lane at a time: `unstable` to `dev`, then
  `dev` to `synth`. Any other PR from a same-repository managed branch, including reverse or
  skipped promotions and anything from `alpha`, is rejected. In this repository, use a topic
  branch for ordinary work, never a managed branch.

`unstable` is the integration lane, `dev` the testing lane and `synth` the main/release lane.
For dependent work, merge prerequisites into `unstable` before opening the next focused PR.

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
