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
- Every schema change is a Doctrine migration in `core/dbmanager/migrations/` that works for both
  fresh installs and upgrades. The legacy `debug/db_updates.php` chain is retired; a database is built only by the baseline migration. See [docs/SCHEMA-MIGRATIONS.md](docs/SCHEMA-MIGRATIONS.md). Add the migration's entry to
  `core/dbmanager/migrations/schema-manifest.json` (one per migration) and follow the
  [schema conventions](docs/SCHEMA-MIGRATIONS.md#schema-conventions); `php tools/schema-check.php --offline`
  must pass.
- Never commit `conf/conf.php`, credentials, logs, caches, generated audio, voice samples,
  database dumps, `unittests/vendor/`, personal paths, or media without recorded provenance. The one
  database export is the curated, checksummed baseline install
  `core/dbmanager/migrations/Version20261117000000.fresh.sql` (schema and stock data only; provenance in
  [docs/SCHEMA-MIGRATIONS.md](docs/SCHEMA-MIGRATIONS.md#fresh-install)).
- Prefer the existing PHPUnit suites. Add a new test file only when existing suites cannot
  cover a high-risk change.

## Layering and placement

Read the [target file layout](docs/SERVER-FILE-LAYOUT.md); the placement table is in
[AGENTS.md](AGENTS.md#layering-and-placement). The rules, in short:

- New features go in the chevo-style directories: `managers/<Name>/` (with a `README.md`),
  `drivers/<kind>/`, `workers/<Name>/` with `bin/`, `api/`, and `core/{entities,dbmanager,contracts}`.
- Legacy directories (`lib/`, `processor/`, `connector/`, `tts/`, `stt/`, `itt/`, `service/`, `main.php`)
  are frozen for new features: bug fixes and thin call-through hooks only.
- Only `core/dbmanager` touches the database; other code uses `core/entities/system` classes.
- Calls go downward only (entry points, then managers and workers, then drivers and entities, then
  `core/dbmanager`).
- Do not move or rename existing files in an ordinary PR.
- Follow [docs/CODING-STANDARDS.md](docs/CODING-STANDARDS.md) for new PHP. Existing `snake_case`
  legacy code is exempt and is not reformatted.

## Code and comments

These apply to new and changed code. Do not reformat or re-comment untouched code.

- Start a new PHP file with a brief comment on what it owns when the file name alone does not
  say so. Put a brief purpose comment above a class or function only when its role is unclear
  from its name and signature.
- Comment intent where it is not obvious: trust-boundary checks, ownership and locking,
  save/playthrough scope, transaction and replay rules, deliberate refusals and fallbacks that
  are not taken. Do not restate the code. There is no comment-per-function or per-line quota.
- Use descriptive names and straightforward flow. Keep one owner per responsibility, as in
  [SERVER-FILE-LAYOUT.md](docs/SERVER-FILE-LAYOUT.md). Do not add a helper that is used once and
  makes the flow harder to follow.
- Match the file's existing formatting and naming: `snake_case` functions in `lib/`, namespaced
  classes in `managers/`, `core/` and `drivers/`. There is no repository-wide formatter.
- At the PHP boundary, validate client and model data before use, use prepared queries, keep
  writes inside the existing owner's transaction, and fail explicitly instead of returning a
  success-shaped default. Never log secrets.

Examples from the current source:

```php
// managers/SpeechManager.php: what the class owns and refuses
// Speech comes only from the NPC profile's own TTS connector and resolved voice; there is no server-wide
// fallback. A line that cannot be voiced is terminal for the turn, never a silent text-only line.

// lib/synth_wait_actions.php: intent above a function whose name does not show the limit
// Waiting changes only the addressed NPC. It is never an autonomous or narrator action.
```

## Documentation

Find component notes and their source entry points in [docs/README.md](docs/README.md). Update the
relevant feature note, [docs/HANDOFF.md](docs/HANDOFF.md) and
[docs/ALPHA-ACCEPTANCE.md](docs/ALPHA-ACCEPTANCE.md); do not start a chronological ledger. State
what was checked and what remains unchecked. Use relative links with the exact Git path case.

## Checks before opening a PR

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\audit-release-tree.ps1
git diff --check
```

Run `php -l` on changed PHP files and the relevant PHPUnit tests against a disposable
PostgreSQL database. To run one test file, from `unittests/` after `composer install`:

```bash
./vendor/bin/phpunit tests/NativeWaitActionTest.php
```

`SYNTH_CLIENT_ROOT` applies to `AutoGreetingRuntimeTest`, not the example above: it points that
test's cross-repository activation wiring check at your SYNTH checkout (default: a sibling `SYNTH`
directory next to the server root; the check is skipped if the client source is not found). For UI changes, check the affected pages at desktop and narrow widths.

## Licensing

Submit project contributions under the GNU GPL v3.0 (root `LICENSE`). Inherited code stays
under the root `MIT-LICENSE`, which names no SYNTH copyright holder. Preserve third-party
notices and record third-party material in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
