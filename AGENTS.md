# Synthserver engineering rules

These instructions apply to the entire repository.

## Scope and lineage

- Implement the Fallout 4 Synthserver plan in this repository only.
- The inherited runtime lineage is HerikaServer via DialecticServer, recorded in
  `docs/provenance/DIALECTICSERVER-BASELINE.md` (earlier research: `docs/MIGRATION-SOURCE-AUDIT.md`).
- Preserve MIT attribution (`MIT-LICENSE`) for reused HerikaServer/DialecticServer code. Do not copy
  proprietary game assets, saves, credentials, database contents, or unreviewed third-party code.
- Use Fallout 4 product semantics deliberately (SYNTH, Commonwealth, Sole Survivor). Do not leave
  permanent aliases to source-product or source-game names.

## Runtime and data safety

- Bind the development service to loopback by default. Never expose Apache or PostgreSQL publicly
  as a convenience.
- Configuration samples are tracked; real credentials and provider keys are ignored. Redact
  secrets from logs, errors, exports, diagnostics, and the UI.
- Validate request schemas, IDs, sizes, encodings, enum allowlists, URLs, and media references at
  the trust boundary. Treat all client and model text as untrusted data.
- Use prepared queries and versioned, transactional migrations. The database is PostgreSQL; do not
  silently fall back to a different persistence model.
- Keep writable runtime state outside the Git checkout and grant Apache/workers only the paths they
  need. Backups and restore operations require explicit targets and verification.
- Model-generated actions are structured data and must pass the client/server action allowlists.
  Never add arbitrary shell, PHP evaluation, SQL, filesystem, console-command, or script proxies.
- Treat `runtime_variant=flat|vr` and the client's capability manifest as authoritative session
  inputs. Never assume VR can execute a flat-only action or presentation path.
- Store runtime lane and exact client/runtime versions with playthrough/evidence records. Do not
  split shared Fallout 4 memory unnecessarily, but never merge incompatible save/profile identity.

## Protocol and quality

- `docs/PROTOCOL.md` is the human-readable contract. Implement schemas and golden fixtures in a
  versioned `protocol/` tree, mirrored byte-for-byte in the SYNTH client repository.
- Breaking contract changes require a new protocol version and coordinated support on both sides.
- Add focused PHPUnit coverage for every migrated feature and negative tests for trust boundaries.
- CI must lint PHP, validate schemas, bootstrap/migrate PostgreSQL with pgvector, run tests, execute
  a fake-client E2E, and audit the release tree for secrets, runtime files, and legacy game names.
- Do not claim game integration from an HTTP test. Record Windows/WSL, flat in-game, and VR
  in-headset evidence independently using the exact artifacts and versions in the matrices.

## Workflow

- Start from `docs/HANDOFF.md` (baseline, topology, deferred work, retired ledgers). Track open
  checks and publication gates in `docs/ALPHA-ACCEPTANCE.md`. Find component notes and source
  entry points in `docs/README.md`; code and comment conventions are in `CONTRIBUTING.md`.
- Start all topic work from `origin/unstable` and target every draft topic PR at `unstable`.
  Promotions are only same-repository `unstable` to `dev` and `dev` to `synth`; `alpha` is a
  legacy branch and never a PR base or promotion source. Follow `CONTRIBUTING.md`.
- Do not rebuild a chronological progress ledger. Update the relevant feature doc, the handoff
  and the acceptance checklist. Keep workstation paths, deployment receipts and private backup
  locations out of tracked files.
- Run PHPUnit only against a disposable PostgreSQL database. New code is licensed under the
  GNU GPL v3.0 (root `LICENSE`). Keep `MIT-LICENSE` and every third-party notice.
- CI audits the tracked source tree and runs tests. It builds no release package.

## Layering and placement

The target layout follows chevo (the CHIM 4.0 server). The full map is
[docs/SERVER-FILE-LAYOUT.md](docs/SERVER-FILE-LAYOUT.md); status per area is
[docs/CHEVO-PARITY.md](docs/CHEVO-PARITY.md); style is [docs/CODING-STANDARDS.md](docs/CODING-STANDARDS.md).
This supersedes the old rule against `config/` and `workers/` roots.

- Calls go downward only: `api/` `bin/` `ui/` -> `managers/` and `workers/` -> `drivers/` and
  `core/entities/system` -> `core/dbmanager`.
- Only `core/dbmanager` touches PostgreSQL. New code uses `core/entities/system` classes, never the
  DAOs in `core/dbmanager/dao` or the `Abstract*` classes in `core/entities/base`. Add the missing
  method to the system class instead of reaching past it.
- **Legacy directories are frozen for new features: thin call-through hooks only.** That covers
  `lib/`, `processor/`, `connector/`, `tts/`, `stt/`, `itt/`, `service/` and `main.php`. Bug fixes and
  a few-line hook that calls new code are allowed; new logic, classes, SQL, providers and workers are not.
- Do not move or rename existing files in an ordinary PR. Domains move one at a time in dedicated PRs.
- New PHP uses `declare(strict_types=1)`, the `Synth\` namespaces and PSR-4 paths (see the layout doc).

| New code | Goes in | Namespace |
| --- | --- | --- |
| Feature logic, policy, lifecycle | `managers/<Name>/` plus its `README.md` | `Synth\Managers\<Name>\*` |
| HTTP endpoint | `api/v<N>/<area>/`, calling a manager | `Synth\Api\*` |
| Table, column, migration | `core/dbmanager/migrations/` (Doctrine) | `Synth\Migrations\*` |
| Entity or query | `core/entities/system` (system class), mapping in `core/entities/base`, DAO in `core/dbmanager/dao` | `Synth\Core\Entities\*`, `Synth\Core\DbManager\*` |
| Interface or value type shared across layers | `core/contracts/` | `Synth\Core\Contracts\*` |
| LLM, TTS, STT or ITT provider | `drivers/<kind>/Drivers/` behind the kind's Interface and Wrapper | `Synth\Drivers\<Kind>\*` |
| Background job | `workers/<Name>/`, started from `bin/` | `Synth\Workers\<Name>\*` |
| CLI or long-running process | `bin/` (thin script) | none |
| Configuration sample | `config/` | none |
| Extension | `extensions/<id>/` (see `managers/ExtensionManager/README.md`) | `Synth\Extensions\<Id>\*` |
| New automated test | `tests/` (`unittests/` stays for existing suites) | `Synth\Tests\*` |

## Working method

- Keep changes small enough to review and make migrations reversible where feasible.
- Update the implementation and parity matrices as features move from planned to proven.
- Do not deploy, publish, create a release, alter production systems, or import real user data
  without separate authorization.
