# Synthserver developer handoff

Updated: 2026-10-06. This is the current entry point for Synthserver development. Older
migration and parity documents under `docs/` are kept as feature evidence.

## Historical feature baseline

| Item | Value |
|---|---|
| Server source | `RANGROO/Synthserver` `codex/chim-queue-parity` at `bcb0c06` (includes `daf4885` and two later live-input/rechat fixes) |
| Paired client | `RANGROO/SYNTH` `codex/chim-conversation-targeting` at `f34da88` |
| Server version | `.version_number.txt` (unchanged by this cleanup) |
| Protocol | Versions 1 and 2 under `protocol/`, mirrored byte-for-byte in the client. The client sends version 2. |
| Latest migration | `synth_protocol_schema` `20260909001` (`debug/db_updates.php`) |

The public repositories are `Dwemer-Dynamics/SYNTH` and `Dwemer-Dynamics/Synthserver`.
`synth` is the default branch; every development PR targets `alpha`. `synth` is the main/release
branch; `dev` and `unstable` are reserved lanes. See [CONTRIBUTING.md](../CONTRIBUTING.md).
Older branch names and hashes below identify historical private development checkpoints.

## Current cleanup source

The public server source comes from the completed private cleanup at `c12fb1b`, including
the inspector retirement, runtime-helper cleanup and blended scrolling banner.
The paired client source comes from `618e924`. Current work belongs on public `alpha`.
The baseline table above records the earlier feature snapshot; it is not the public source head.
This follow-up retires the management-only native inspector APIs and their exclusive helpers.
It does not change the client, protocol, database schema, providers or active workers.

## Runtime topology

- Windows hosts Fallout 4 and the SYNTH client. A WSL Linux distribution runs Apache with PHP
  (cli and Apache module; `curl`, `mbstring`, `pgsql` and `xml` extensions) and PostgreSQL
  with `pgvector` and `pg_trgm`.
- Apache serves `/var/www/html/Synthserver` on **`127.0.0.1:8087`** with
  `Require local` (`tools/apache/synthserver.conf`). This matches the client default
  `BaseUrl=http://127.0.0.1:8087/Synthserver/`.
- The database is `synth` on loopback PostgreSQL. The local role defaults to
  `dwemer`/`dwemer` (`conf/conf.sample.php`, `lib/db_connection_settings.php`). These are
  loopback-only development defaults. Never expose PostgreSQL or Apache beyond loopback.
- The background processor (`service/start.sh`, `service/manager.php`) runs memory,
  relationship and TTS workers. `health.php` reports the database, schema and processor state.
- `start-dev.ps1` is an **optional** standalone PHP development server. It defaults to port
  **8085**, so a client that uses it needs a matching `BaseUrl`. It serves the checkout's parent
  directory, so the checkout folder must be named `Synthserver`.

## Fresh install

The [README](../README.md#install) has the steps. Safeguards:

- `tools/create-synth-db-wsl.ps1` creates the database only if it is missing. It requires
  UTF-8, enables the extensions, imports `data/database_default.sql` only when the baseline
  schema is absent, then runs the idempotent migration chain. It drops data **only** when the
  explicit `-Reset` switch is passed. The default never resets.
- `tools/deploy-synthserver-wsl.ps1` refuses any target other than
  `/var/www/html/Synthserver`, then stops before any change unless the read-only
  `tools/check-php-runtime-security.sh` passes in the chosen distro (patched PHP CLI and Apache
  PHP runtime; floors in the [README](../README.md#install)). Its `rsync --delete` excludes configuration, voices, caches,
  uploads, logs and runtime state. It creates `conf/conf.php` from the sample only when
  `conf.php` is missing, protects it with mode `0640`, runs the database bootstrap, and installs
  the Apache site only after `apache2ctl configtest` passes.
- `tools/migrate-synth-db-utf8-wsl.sh` creates a safety dump and a rollback database, then
  compares row counts before it switches the database.
- The helper scripts assume the default local role password. If you change it, update
  `conf/conf.php` and run `tools/bootstrap-database.php` directly.

## Tests

- `tools/audit-release-tree.ps1` lints every tracked PHP file, validates JSON, checks the
  World Knowledge checksums and the TTS/STT/ITT drivers, and rejects runtime files and
  unverified assets. It is a source safety audit, not packaging.
- CI policy (2026-10-06): hosted `server.yml` runs the source-tree audit, the protocol
  manifest check and PHPUnit on disposable PostgreSQL. It builds no release package. The
  last hosted run before this note passed at `d171ec7`.
- PHPUnit (`unittests/`, run `composer install` first) needs a **disposable** PostgreSQL with
  pgvector and the `dwemer` role, as in `.github/workflows/server.yml`. Never run it against a
  database that holds real data.
- `unittests/tests/AutoGreetingRuntimeTest.php` reads the client source through
  `SYNTH_CLIENT_ROOT` (default `../SYNTH`). If the client source is missing, the test is skipped.
- `ui/tests/stt-test.php` needs a locally supplied WAV (`debug/data/test.wav` or a CLI path).
  No sample or expected transcript ships. Without a sample it reports that the test is
  unavailable and makes no provider call.

## Deferred work

- **Native embedding-space subsystem.** Not alpha-ready. It is preserved on
  `codex/dialectic-parity-core` at `3808838` (`lib/synth_embedding_*`,
  `service/embedding_dispatch.php`, the native embedding processor, rollback SQL). Its own
  documentation records it as not deployed and not at semantic-memory parity. Its six
  migrations, `20260908001` to `20260908006`, sort **below** the baseline's `20260909001` and
  must be re-evaluated and renumbered before any merge. The other commits on that branch have
  reworked equivalents in the baseline. No exhaustive per-hunk deletion reconciliation was
  performed.
- Scope amendment of 2026-09-08: companion affinity is excluded. TTS scope is XTTS,
  Chatterbox, PocketTTS, Cartesia and Inworld.
- Vision connectors are disabled.
- Client-side gaps (pickup disabled, VR-only gaps, in-game acceptance) are listed in the
  SYNTH client's `docs/HANDOFF.md`.

## Retired ledgers

The 2026-10-06 cleanup removed `docs/DIALECTIC-PARITY-GOAL.md` (the chronological parity goal
ledger) and two near-duplicate dead-code audits of an old snapshot
(`docs/synth-parity-dead-code-audit.md` and `docs/provenance/stale-snapshot-dead-code-audit.md`).
They remain in Git history at `bcb0c06` and in a private archive. The ledger held
workstation-specific deployment receipts, hashes and backup paths. It was not a current
specification. Documents that cite it for evidence or remaining gates now point here. Current
open requirements are listed above and in [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md).

## Historical receipts and placeholders

Feature docs keep dated deployment and validation receipts as historical evidence. Their dates,
commit hashes, file hashes, migration IDs and the fixed install path `/var/www/html/Synthserver`
are original. Workstation-specific locations were replaced with these placeholders:

| Placeholder | Meaning |
|---|---|
| `<wsl-distro>` | The WSL distribution that hosted Synthserver |
| `<MO2-mods>` | The Mod Organizer 2 `mods` folder of the test modlist |
| `<Fallout4-install>` | The Fallout 4 game folder |
| `<scratch>` | Ad-hoc probe scripts and scratch output (Windows or WSL view), retained outside the repository |
| `<private-backup>`, `<server-backup>` | Pre-deployment backups (Windows and WSL), retained privately |

The probe scripts and backups named in those receipts are external evidence. They are not part
of the repository and are not needed to install or test Synthserver.

## Baseline evidence

These checks ran on the baseline commit, not on the cleanup head:

- The release audit passed: 428 PHP files, 206 JSON files and 3 World Knowledge checksums.
- Full PHPUnit stopped at about test 300 of 472 with a pre-existing fatal error: a repeated
  `_relGetConversationListener()` declaration when a test includes
  `ext/relationship_system/postrequest.php` a second time. The cleanup guards that file's
  helpers with `function_exists`, following the file's existing pattern. The hook still runs
  its per-request side effects.

## Cleanup working-tree evidence (2026-10-06)

These ran on the uncommitted cleanup working tree, before the final documentation-only pass:

- Full PHPUnit in an isolated, disposable PostgreSQL 15 cluster: 472 tests, 6605 assertions,
  all passing.

No in-game or live-provider evidence is implied.

## Roleplay page cleanup

Roleplay follows CHIM's standard Events, AI Responses, Adventure Log, Memories and Diaries
navigation, with PipVision and Active Quests retained for Fallout. The separate Native Memories,
Narratives, Relationships, Diaries and Visual Corrections inspectors have been retired, along
with their exclusive JavaScript and styles. Their old URLs redirect to the standard memory,
diary, relationship-log or gallery pages. The nine management-only `ui/api/native*.php`
endpoints and six exclusive inspector/control libraries are retired. The remaining dormant
inspector functions have also been removed. Active relationship locks
and write checks live in `synth_relationship_control.php`; regeneration scope validation lives
in `synth_middleterm_scope.php`. Snapshot data, processors and migrations remain intact;
this cleanup does not migrate
save-owned records into the standard memory tables. Historical feature docs describe the retired
management UI; they do not imply those APIs are available in the current tree.

## Licensing and publication

Synthserver's own additions are licensed under the GNU GPL v3.0 (root `LICENSE`, the
unmodified GPLv3 text, chosen 2026-10-06). The root `MIT-LICENSE` is the unmodified inherited
HerikaServer/DialecticServer notice ("Augusto Beiro and others"). It names no SYNTH copyright
holder, and the GPLv3 does not narrow those MIT terms. Keep `MIT-LICENSE` and the bundled
CC BY-SA 4.0 and OFL notices. History and publication decisions remain open. See
[THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md) and the
[publication gates](ALPHA-ACCEPTANCE.md#publication-gates).
