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
  checks and publication gates in `docs/ALPHA-ACCEPTANCE.md`.
- Start all work from `origin/alpha` and target every draft PR at `alpha`. Do not use
  the other Dwemer mods' unstable-first workflow. Follow `CONTRIBUTING.md`.
- Do not rebuild a chronological progress ledger. Update the relevant feature doc, the handoff
  and the acceptance checklist. Keep workstation paths, deployment receipts and private backup
  locations out of tracked files.
- Run PHPUnit only against a disposable PostgreSQL database. New code is licensed under the
  GNU GPL v3.0 (root `LICENSE`). Keep `MIT-LICENSE` and every third-party notice.
- CI audits the tracked source tree and runs tests. It builds no release package.

## Working method

- Keep changes small enough to review and make migrations reversible where feasible.
- Update the implementation and parity matrices as features move from planned to proven.
- Do not deploy, publish, create a release, alter production systems, or import real user data
  without separate authorization.
