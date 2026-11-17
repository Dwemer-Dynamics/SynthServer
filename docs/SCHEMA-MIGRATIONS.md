# Schema migration owner

Doctrine Migrations owns versioned schema changes through `core/dbmanager/SchemaMigrations.php`.
`debug/db_updates.php` is frozen as the legacy bootstrap baseline. The first migration recognizes that
baseline on an existing install after verifying it, changing no schema and no row. On an empty database it
installs that baseline itself ([Fresh install](#fresh-install)). Later schema changes are new classes in
`core/dbmanager/migrations/` (namespace `Synth\Migrations`), never new `db_updates.php` blocks. This
foundation adds no migration beyond the baseline. It does not redesign actor identity, application writes,
transactions or ORM use.

## Source

- `core/dbmanager/SchemaMigrations.php`: the owner (connection, locks, legacy step, Doctrine run, revert).
  `connect()`'s optional `$additionalMigrations` is a test seam only: already-declared classes registered
  beside the tree's, so a later migration can be proven without shipping one.
- `core/dbmanager/migrations.php`: Doctrine configuration. The version table is
  `synth_meta.doctrine_migration_versions`, outside the application schemas that playthrough snapshots
  copy. A snapshot therefore does not carry its own migration version: restoring an older schema would
  keep a newer ledger, and Doctrine would skip migrations that copy never received. This baseline-only
  change alters no application schema, so no such gap exists yet (see Limits).
- `core/dbmanager/migrations/Version20261115000000.php`: the frozen baseline, its fresh install and its
  verification. `Version20261115000000.structure.txt` is generated data: the structure manifest of the
  baseline. `Version20261115000000.fresh.sql` is generated data too: the statements that install the baseline
  on an empty database.
- `lib/runtime_bootstrap.php` (`synthRuntimeApplySchemaMigrations`): the entry used by
  `tools/bootstrap-database.php` and by `debug/db_updates.php`. `synthRuntimeSchemaMigrationStatus` is the
  read-only counterpart used by `health.php` (see Readiness).
- `composer.json` / `composer.lock`: adds `doctrine/migrations` 3.9.8 and `symfony/stopwatch` v7.4.8; every
  other locked package is unchanged.

## Entries

All of them reach `SchemaMigrations::migrate()` under the same lock:

- `tools/bootstrap-database.php` (`synthRuntimeEnsureDbUpdates`).
- `debug/db_updates.php` is a compatibility entry. Requiring it (the dashboard home, Quickstart, the STT, ITT and
  TTS connector pages, older tests) delegates to the owner and returns. Only the owner's own include, flagged
  by `SchemaMigrations::isRunningLegacyBody()`, continues into the frozen chain, so there is no recursion
  and no unlocked chain run.
- `applyLegacyChainWithoutLedger()` runs only the chain, under the same locks, and records nothing. It
  refuses a database that has any migration record. `DatabaseTestCase` uses it to build a true pre-ledger
  legacy fixture. `SchemaMigrationOwnerTest` builds its fixture in a fresh child process instead: in the
  PHPUnit process, `synth_ensure_synthnpcs_view()`'s per-process flag skips the view (and its version row)
  for every class after the first.

## Run order

1. The owner opens its own PostgreSQL connection (DBAL `pgsql`, `search_path` `synth, synth_meta, public`).
   Host, port and database come from the legacy session's own connection and user and password from the
   `SYNTH_DB_*` settings. The shared-session read adapter refuses transactions and must never end a
   legacy-owned one, so the owner does not use it. Connection failures report a fixed message.
2. It takes the session advisory lock `hashtextextended('synth-schema-migrations', 0)`, polling for up to 30
   minutes; after that it fails with "still running".
3. It refuses a legacy session that has an open transaction: the chain would join that transaction and could
   end it. Both sessions must report the same database, server port and postmaster start time.
4. It refuses any recorded version this tree does not contain (code older than the database). This happens
   before the legacy chain or any migration runs.
5. It takes `synth-schema-migrations-legacy` on the legacy session for the steps below. A run killed
   mid-statement leaves an orphaned backend that keeps this lock until its statement ends, so a rerun waits.
   - No record yet, empty database ([Fresh install](#fresh-install)): no legacy chain; step 6 installs it.
   - No record yet, anything else: if `database_versioning` is below any of the 47 frozen version floors, the
     legacy chain runs first. If the floors are already met, the
     install is verified as it stands, and damage is refused rather than half-repaired by version-gated steps.
   - Baseline is the latest record: the baseline is re-verified against its frozen contract, never the current
     runtime gate (which may already expect a pending migration's work). Damage is refused, never repaired by
     the chain.
   - Later migrations recorded: they own the schema; the baseline checks no longer apply.
6. In one owner transaction it creates the version table if missing (on an empty database, the `synth_meta`
   schema first) and migrates to the latest version all-or-nothing. Doctrine's all-or-nothing and
   per-migration transactions nest inside it as savepoints. The baseline migration verifies in `postUp()`,
   after its statements and before its record, and the version is recorded only if verification passes. Any
   failure rolls everything back, the version table included, so a rerun starts from the same state.

The bootstrap then rechecks the runtime gate and fails unless the baseline is recorded. Errors print their
message only, without a stack trace. `revertTo('first')` (API only, no CLI yet) removes the baseline record
under the same lock. The schema and its rows stay, whether recognized or freshly installed, and the next run
verifies and records again.

## Fresh install

An empty database is installed by the baseline migration, not by the legacy chain and not by importing
`data/database_default.sql`. Normal bootstrap (`tools/bootstrap-database.php`, every `debug/db_updates.php`
caller) and `tools/create-synth-db-wsl.ps1` take this path; the provisioner no longer imports any SQL itself.

- Empty means (`Version20261115000000::isEmptyDatabase()`): no schema besides `public` and `synth_meta`, and in
  those two no relation, function or standalone type that is not an extension member, apart from the Doctrine
  version table. Extensions may exist. Any other database, including one holding only an unrelated table in
  `public`, keeps the legacy path above. Nothing is reset or dropped automatically.
- `up()` re-checks emptiness inside the transaction and adds the statements of
  `Version20261115000000.fresh.sql` one by one. DBAL's `pgsql` driver reads only the first result of a
  multi-statement query, so a batch would hide a later failure. The file must match the SHA-256 recorded in
  the class (after CRLF to LF; the file holds no CR) or the migration aborts before any statement.
- The statements set `SET LOCAL` session values, create `vector` in `public`, the `synth`, `synth_meta` and
  `plugins` schemas, `pg_trgm` in `synth` (where the legacy chain puts it; a provisioner that already created
  it in `public` keeps that), then every table, view, function, sequence position, index, constraint and
  comment, and the stock rows. Seed timestamps are the install time (`CURRENT_TIMESTAMP`), as with the chain.
- The bootstrap then runs as before: it records nothing else, rechecks the gate and loads the settings (the
  narrator loader still seeds its missing keys there, on both paths).

### Provenance and regeneration

`Version20261115000000.fresh.sql` was exported from the legacy fresh install of unstable `0f4fadc`
(`data/database_default.sql`, then the frozen chain via `applyLegacyChainWithoutLedger()` in a fresh child
process with `conf/conf.sample.php`) on a disposable PostgreSQL 17.11 with pgvector 0.8.7, in a private
namespace. The export was `pg_dump --schema=synth --schema=synth_meta --schema=plugins --no-owner
--no-privileges --no-tablespaces --no-security-labels --no-publications --no-subscriptions --inserts
--rows-per-insert=100` (no version table existed). Curation, each
step counted: the `\restrict`/`\unrestrict` lines and pg_dump's 12 session settings dropped and replaced by
`SET LOCAL` equivalents, the two extensions added, `CREATE SCHEMA synth_meta` made `IF NOT EXISTS` (the owner
creates it first for the version table), `synth.gin_trgm_ops` unqualified in 4 indexes, and the 478 seed
timestamps written by the export run replaced by `CURRENT_TIMESTAMP`. The statements were split with a SQL
lexer (strings, quoted identifiers, dollar quotes, comments), 783 in all. No credentials or runtime rows
are in it: the API badge rows carry empty keys and every runtime table is empty.

The file is frozen with the baseline. The chain is frozen too, so it is never regenerated for new schema;
new objects are new migrations. A replacement (for example a review fix) must come from the same procedure on
a disposable database, update the class's SHA-256, and pass `SchemaMigrationOwnerTest`, which compares the
result with a legacy fresh install built in the same run.

### Checks

`SchemaMigrationOwnerTest` (disposable PostgreSQL only) builds the legacy fresh install, bootstraps it once as
the reference, then on a new empty database:

- two concurrent bootstraps install once, record only the baseline and match the reference in the
  structure fingerprint and in every row (timestamps aside), sequence position, view, function and trigger
  definition, comment and extension schema; the stock rows, schemas, relations and the version record share
  one writing transaction; a rerun changes nothing; `revertTo('first')` keeps the install and the next run
  verifies and records it again;
- extensions pre-created in `public` (as the provisioner does) are used, the only difference being where
  `pg_trgm` lives;
- a failing later migration in the same plan leaves the database empty (no schema, version table or
  extension), and a retry installs it;
- a bootstrap killed inside its migration transaction leaves the database empty once its backend ends, and a
  rerun installs it;
- `isEmptyDatabase()` accepts extensions and the version table alone and refuses a table, view, sequence,
  function or type in `public`, a `synth`, `plugins` or other schema, or another `synth_meta` table; the
  generated file splits into 783 terminated statements.

Run it inside a disposable environment, from `unittests/`:
`php vendor/phpunit/phpunit/phpunit -c phpunit.xml tests/SchemaMigrationOwnerTest.php`.

## Snapshot compatibility guard

`SchemaMigrations::captureSnapshot()` and `restoreSnapshot()` are the entries a future snapshot capture and restore
must run through. Nothing calls them yet: they write nothing, copy no schema, add no column, endpoint or UI, and
the legacy snapshot pause (see Limits) stays fully in force.

- Both take the owner's existing session lock (`synth-schema-migrations`) and run on the owner's own connection,
  in one DBAL transaction opened by the owner. The owner commits when the callbacks return, before it unlocks,
  and rolls back when one throws. No second lock is added and the legacy session is not used. Nested owner
  entries are refused.
- Both read the live ledger once, before any callback, and refuse a missing ledger (no version table, or no
  record) and a record this tree does not contain.
- `captureSnapshot($source, $capture)` refuses any source but `synth`. It passes the callback the metadata the
  snapshot must store and the owner's connection: `format` (`synth-schema-snapshot.v1`), `schema` (`synth`) and
  `migration_versions`, every recorded version sorted bytewise.
- `restoreSnapshot($readTarget, $restore)` calls `$readTarget` inside the lock and transaction. It returns the
  concrete target as an array whose `metadata` is what capture stored. Unless that metadata equals the live
  capture metadata, the restore is refused and `$restore` never runs. Otherwise `$restore` receives that same
  checked target. The metadata is compared as an object: the order of its top-level keys is ignored, because a
  JSONB round trip may reorder them. Everything else is exact: the same three fields and no others, string
  `format` and `schema` values, and `migration_versions` as the same ordered list of the same strings. A
  different version set is refused even when it shares the newest version. Stored values are never named in
  the error.
- Trusted callback contract: `$readTarget` only reads and locks (`SELECT ... FOR UPDATE`) the target's rows.
  No callback begins, commits or rolls back a transaction, closes or reconfigures the connection, releases
  the lock, changes the migration ledger or calls another owner entry.

Future integration needs separate review and approval: a reviewed place to persist the metadata with each
snapshot (a dedicated column or table is a schema migration, and it must not be stored in an unrelated column).
It also needs the actual capture and restore, run inside these callbacks. Then the manager UI
and Timeline Break wiring can be re-enabled.

## What the baseline verifies

- UTF8 encoding and the `vector` and `pg_trgm` extensions.
- A frozen contract in the migration class, copied from the 42c7e0e readiness gate and settings list and never
  read from the live runtime: 47 legacy version floors (the gate's 45 plus `social_tasks_schema` ≥ 20261102001
  and `private_narrator_schema` ≥ 20261113001), the five SYNTH prompt rows, one default NPC and one default
  narrator profile, the 54 then-managed general setting ids, the three playthrough functions, and the absence
  of `synth.currentmission`. The gate's 83 required relations are covered by the manifest. Versions and seeds
  are read only once the structure matches.
- The structure manifest, generated from a disposable PostgreSQL 15 inventory of baseline source 42c7e0e
  (2062 entries). It covers 121 tables and views by name and type. It covers 1123 columns by data type,
  udt, nullability, default and character length, plus 6 `vector` dimensions taken from the schema source.
  It covers 574 primary key, foreign key, check and unique constraints by kind, validation and definition,
  and 238 indexes by definition. A missing or changed entry is refused, and the first five are named.
  Objects not in the manifest are allowed.

It does **not** compare view or function bodies, triggers, numeric precision, sequence state, ownership or
privileges, or any row beyond the frozen seeds. The playthrough functions are checked only for existence.
The manifest comes from a fresh-install fixture, not from installed databases. An older install that
reaches the baseline by a path producing different constraint or index names is refused until reviewed.

## Readiness

`health.php` is read-only: it loads configuration, connects through `synthRuntimeConnectDatabase` (an
exception, never the legacy plaintext exit; connect timeout capped at 5 seconds unless the environment sets
`SYNTH_DB_CONNECT_TIMEOUT`) and inspects. It never migrates, takes the owner's lock, creates the version table,
loads settings or calls providers, and `Logger::suspendFileWrites()` keeps it from writing or rotating any
SYNTH log file; warnings go to the web server error log. `SchemaMigrations::status()` compares the migrations
this tree contains with the recorded ledger and reports `schema_migrations` as:

| State | Meaning | Action |
|---|---|---|
| `current` | every known migration is recorded | none |
| `missing` | no version table or no record (a pre-ledger install or an empty database) | run `tools/bootstrap-database.php` |
| `pending` | a known migration is not recorded | run `tools/bootstrap-database.php` |
| `unknown` | a record this tree does not contain (code older than the database) | deploy the server version that last migrated it |
| `unavailable` | not inspected: no connection, unsupported encoding, missing `vendor/` or a failed read | follow `error` |

The answer is HTTP 200 only when the database answers, uses UTF8, passes the runtime gate
(`synthRuntimeNeedsDbUpdates`) and the ledger is `current`; otherwise 503 with one fixed, actionable `error`.
A `current` ledger with the gate unmet is reported as an incomplete schema, not as a missing migration.
Driver and migration messages are never returned. `background_processor` is advisory and does not change the
status code.

Quickstart GET runs one runtime bootstrap (`conf.php` read once) with `throw_db_connection_errors`. It answers a
short HTTP 503 page that links to `health.php` unless the database is reachable, uses UTF8, passes the runtime
gate, has a `current` ledger and has its vendor dependencies installed; only then does it load the render
settings, and it reads the active STT connector without writing it. A GET never migrates. A save checks the
encoding, runs the owner through `debug/db_updates.php`, rechecks the gate and answers any failure with a fixed
JSON 503 before any settings save. `tools/bootstrap-database.php` uses the same single bootstrap, checks UTF8
before migrating and exits nonzero when PostgreSQL is unreachable.

## Rules for new migrations

- Use `addSql()` only. Never read or change the `Schema` argument: building it makes DBAL introspect the
  whole database, and DBAL has no mapping for `vector` or `tsvector` columns.
- Keep every migration transactional (all-or-nothing rejects any that is not). Write a `down()` that
  reverses it, or throw `IrreversibleMigration` and say why.
- New schema objects and seeds are migrations. Never edit the baseline's frozen contract or manifest. A
  migration may extend the runtime gate or the managed settings: the owner checks only the frozen baseline
  before it runs, and the bootstrap's gate recheck after it. A failed migration rolls back with its version
  record.

## Schema conventions

Decisions recorded for the schema work that aligns SYNTH with the CHIM 4 server prototype's schema process
(a per-migration manifest, a read-only drift check, and domain-scoped DBAL stores). They bind **new work only**.
The baseline, its manifest and every existing table stay as they are.

| Id | Decision |
|---|---|
| D1 | Conventions apply to new work only. Existing `core_*` and other legacy names stay; nothing is renamed. |
| D2 | New tables are named `synth_meta.<domain>_<noun>` (for example `synth_meta.snapshot_versions`). |
| D3 | The manifest has one entry per migration (`migrations/schema-manifest.json`), never a combined entry. |
| D4 | The ORM pilot (`GeneralSetting`, see [DOCTRINE-SETTINGS-PILOT.md](DOCTRINE-SETTINGS-PILOT.md)) is frozen. No new ORM entities; new persistence code uses DBAL repositories. |
| D5 | SYNTH is a consumer of the CHIM 4 server's shared `api_keys` table (database `dwemer`), see [Shared API keys](#shared-api-keys). `synth.core_api_badge` stays as the local binding table: its ids, labels and connector references never change and no credential row is removed. **Held:** the migration and the import need the owner's explicit go-ahead. |
| D6 | The first future schema migration adds snapshot version metadata (see the paused snapshots under [Limits](#limits)). |

Plan order: conventions docs, read-only tooling (the [drift check](#drift-check)), DBAL repositories, a review gate,
then the first migration. The first migration is on hold. No schema-changing migration, snapshot re-enablement or
change to an installed database is part of the steps before it.

### Open questions for review

1. What "CHIM 4.0 planning format" means exactly, and which parts of it SYNTH should follow. This note only
   assumes the observable ChimServer pieces: a format-numbered manifest with per-migration hashes, a read-only
   `schema-check` and per-domain DBAL stores.
2. Which PostgreSQL major the manifest should target. The baseline structure was generated on PostgreSQL 15; SYNTH
   is validated on 17 by local disposable-cluster checks only (no hosted CI pass exists). The drift check reports the server major and does not refuse
   another one. ChimServer keeps one manifest per reviewed major; SYNTH has one.
3. Acceptance of D2 (`synth_meta.<domain>_<noun>`). Until it is accepted, no new table should be created.

## Shared API keys

**Status: held.** Nothing here is part of an installed database until the owner lifts the migration hold. The branch
was proven on disposable PostgreSQL 17 only.

The CHIM 4 server keeps provider API keys in one `api_keys` table of a separate shared PostgreSQL database
(`dwemer`), so every product can use one key list. SYNTH adopts that store without changing what connectors
reference. Reference implementation: ChimServer `Config/DBManager/Runtime/ApiKeyStore.php`, `Core/ApiKeyManager`,
`Config/DBManager/Schema/shared-pg17.json` (read at `804e501`).

### Design

- **Connection.** The same variables ChimServer reads, no defaults: `CHIM_SHARED_DB_HOST`, `_PORT`, `_NAME`, `_USER`,
  `_PASSWORD` (the shared runtime role). If any of host, port, name or user is unset, or the port is invalid, SYNTH has no
  shared store (`SharedApiKeyStore::fromEnvironment()` is null) and works exactly as before. Migrations use
  `CHIM_SHARED_DB_MIGRATION_USER` / `_PASSWORD`, as in ChimServer. SYNTH's own database stays `SYNTH_DB_*`. The store
  has its own DBAL connection (never the legacy session), a 5 s statement timeout, a 1 s lock timeout and fixed error
  messages: a database error's detail can repeat the failing row, so none is kept.
- **Shared schema.** ChimServer owns it. `core/dbmanager/shared-migrations/` holds **byte-identical copies** of its four
  shared migrations (hashes pinned in `core/dbmanager/shared-schema-manifest.json`, equal to `migration_hashes` of
  `shared-pg17.json`). They keep the namespace `ChimServer\SharedMigrations` and the version table
  `dwemer_shared_migration_versions` on purpose: the ledger stores fully qualified class names, so a SYNTH-named copy would
  record a second, parallel history. Whichever product migrates first creates `api_keys`; the other finds every version
  recorded and does nothing (proven for a rerun and against a store ChimServer-shaped data was added to). `core/dbmanager/shared-migrations.php`
  and `shared-migrations-db.php` mirror ChimServer's configuration. A new shared migration is made in ChimServer, copied
  here unchanged and pinned. No parallel table exists.
- **Provider, label and origin.** A SYNTH label is free text; ChimServer pins each provider to one origin. The provider is
  taken from the label, case-insensitively: `openrouter`, `openai`, `inworld`, `deepgram`, `google` (also `gemini`),
  `elevenlabs`, `cartesia`, `nano-gpt`/`nano_gpt`, `groq`, `atlascloud`. The origin is that provider's fixed origin. The shared
  `label` is the SYNTH label when it satisfies ChimServer's label rule, otherwise the provider name. Providers without a
  fixed origin (`azure`, `replicate`, `deepl`, `ploofy`, `custom_openai`) and any other label are **not migrated**: SYNTH does
  not know the endpoint. They stay local and unbound (open question 2). A value the shared table cannot hold (empty, or
  anything but 1-4096 printable ASCII characters without spaces) also stays local.
- **Connector references.** `core_api_badge` remains the stable binding table. The only schema change is
  `Version20261116100000`: one nullable `uuid` column, `core_api_badge.shared_key_id`, plus a partial index. No foreign key
  (another database), no default, no data change. Connector rows keep `api_badge_id` and are never rewritten. A bound
  badge reads its key from `api_keys`; `api_key` stays as a mirror (below). Several badges may point at one shared key.
  Rejected alternative: pointing connectors at shared key ids. It would rewrite four connector tables and their foreign
  keys, lose ids, and make rollback destructive.
- **Reads and writes (`ApiBadgeRepository`, callers unchanged).** A bound row's key comes from the shared row, in one
  batched query per read. A key the store has revoked reads as empty. A key the store cannot answer for (unreachable, never
  held, legacy sealed row) reads as the local mirror. Writes to a bound row go to the shared store first and fail closed when
  it cannot be written (the mirror never gets ahead of the shared key); then the same local statement as before runs and
  mirrors the value. A new key saved on an unbound, mappable row is promoted: it adopts an identical active key of the same
  provider or creates one, then binds. Editing a key that another SYNTH badge shares gives the edited badge its own shared
  key, so case variants stay independent. **SYNTH never deletes or tombstones a shared key**: Delete removes the local row,
  Clear empties the key and unbinds. Another product may use the key, and ChimServer's delete-in-use scan cannot see SYNTH
  references (open question 4). Binding follows ChimServer's lock order (shared row `FOR SHARE`, then the SYNTH statement,
  committed before the lock is released). Creation takes ChimServer's advisory catalog lock (`4851020019`) and respects its
  cap of 64 active keys.
- **Migration of existing rows** (`php tools/shared-api-keys.php import [--apply]`, never from a request, bootstrap or
  migration). It crosses two databases, so there is no cross-database transaction and no external call inside a
  transaction. Per row: one short shared transaction under the catalog lock adopts an active plain-text key with the same
  provider and value, or creates one; then one SYNTH statement binds the row (`WHERE shared_key_id IS NULL AND api_key = <value
  read>`, so a concurrent edit is never overwritten) while the shared row is held `FOR SHARE`. Idempotent: bound rows are
  skipped, and a run that stopped between the two steps adopts the key it created, so a rerun adds no duplicate (proven,
  also with three concurrent runs). **Conflict rule:** when ChimServer already holds a key for the same provider with the
  same value, SYNTH adopts it. A different value coexists: SYNTH adds its own key and never changes a ChimServer row. A
  sealed legacy row cannot be compared (SYNTH has no installation key), so SYNTH adds its own. A full store (64) binds
  nothing and exits 1. Local `api_key`, `id`, `label` and every connector reference are never changed. The default run is
  a read-only plan.
- **Shared store absent.** Not configured: no store object, no extra query, no log line, no warning, no connection.
  Configured but unreachable, without `api_keys`, or before this migration: the same, falling back to local keys silently.
  Bound rows then read their mirror; only a write to a bound row is refused. `tools/shared-api-keys.php status` reports
  each state.
- **#53/#54 semantics.** Keys stay write-only for pages. A blank field preserves the key and Clear is explicit (`update`
  with an empty `api_key` is the only way to empty one). Saved-key Test resolves server-side through the repository. The
  lowest-id case-insensitive canonical badge and independent variants are unchanged (editing a shared key is described
  above), and Delete still takes a strict id. The credential harnesses were rerun without and with the store.
- **Rollback.** `down()` drops only the index and the column. Every bound key also exists in `api_key`, so a rolled-back
  install loses no key; shared keys are never touched. Applying the migration again and rerunning the import adopts every
  key by provider and value (proven: created 0, adopted 9). Edits made while bound are mirrored, so the local copy is
  current. Removing the `CHIM_SHARED_DB_*` variables returns SYNTH to local keys at any time.
- **Origins.** SYNTH does not yet check the endpoint against the key's stored origin the way ChimServer's `resolveSecret`
  does: its callers do not pass one (open question 3).
- **Not in this change.** No UI, no change to the credential pages, no deletion of local `api_key` copies, no cleanup of
  shared keys, no change to ChimServer, no deployment script.

### Operator order (after the hold is lifted)

1. `vendor/bin/doctrine-migrations migrate --configuration=core/dbmanager/shared-migrations.php --db-configuration=core/dbmanager/shared-migrations-db.php --no-interaction`
   (a no-op when ChimServer already did it), then grant the runtime role as in ChimServer's `docs/s19-api-keys.md`.
2. `php tools/bootstrap-database.php` (applies `Version20261116100000`).
3. `php tools/shared-api-keys.php status`, then `import`, then `import --apply`.

### Open questions

1. **Hold.** The migration and the import wait for the owner's explicit approval; nothing merges before that.
2. **Providers without a fixed origin** (Azure, Replicate, custom labels, Player2/SYNTH): should an operator command bind
   them with an entered origin, or stay local?
3. **Origin enforcement.** Should repository callers pass the endpoint origin so SYNTH refuses a key for another host, as
   ChimServer does? Needs a signature change across connectors.
4. **Delete and cross-product usage.** SYNTH never tombstones a shared key. Should ChimServer's delete scan learn about
   SYNTH references (Distro consumer registry), and should SYNTH offer a delete of keys it created?
5. **Dropping the local mirror.** When, if ever, should `api_key` of a bound row be blanked? Today it is the rollback copy
   and the offline fallback.
6. **Environment delivery.** Where do `CHIM_SHARED_DB_*` reach SYNTH's Apache/PHP and workers on a Distro install?
7. **Sealed legacy rows** in ChimServer's store cannot be matched by value, so a SYNTH key identical to a sealed one is
   added again.
8. **Label sync.** SYNTH does not rename a shared key when a badge is renamed.

## Drift check

`php tools/schema-check.php` compares a live database with the frozen manifest and reports drift. It is the
read-only counterpart of the baseline's own verification and is meant for review, support and CI, not for the
install path (which keeps `SchemaMigrations`).

- **Read-only by construction.** It never calls `SchemaMigrations`, never takes the migration advisory lock, never
  creates the version table and never runs a migration. The inspection is one `READ ONLY` transaction
  (`BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY`, `SET LOCAL`, `SELECT`, `SHOW`) that always ends in
  `ROLLBACK`. The manifest is validated before any connection is made.
- **Manifest.** `core/dbmanager/migrations/schema-manifest.json` (format 1) has one entry per migration: the
  SHA-256 of the class and of each generated companion file beside it (`.structure.txt`, `.fresh.sql`), computed
  over LF bytes so a CRLF checkout is not a change. An entry with a `structure` file also states its entry count.
  The existing baseline files are unchanged; the manifest only freezes their hashes. A migration without an entry,
  an entry without a migration, a changed file or a changed count makes the manifest stale, and the check stops.
  A later migration adds its own entry (and, if it adds objects to verify, its own `<Version>.structure.txt`).
  `php tools/schema-check.php --hashes` prints the entries the tree implies, for review; refresh an entry only from
  a reviewed migration, never to silence drift.
- **What it compares.** The structure lines of every entry (tables and views, columns, `vector` column types,
  primary key, foreign key, check and unique constraints, indexes in `synth` and `synth_meta`) as exact strings,
  rendered under `search_path` `synth, synth_meta, public`, and the version ledger by name. A manifest line the
  live schema lacks is `missing`; a line whose object exists with another definition is `changed`. Objects the
  manifest does not list are allowed and only counted (`unmanifested_tables`). The ledger is `current`, `pending`
  (a known migration is not recorded), `unknown` (a record this tree does not contain) or `missing`; anything
  but `current` is drift.
- **Output.** JSON on stdout: the status, the server major, object kinds and names with `missing` or `changed`
  (at most 200), the ledger state and the stated limits. It never prints SQL, definitions, rows, credentials or a
  driver message; failures use fixed messages on stderr.
- **Exit codes.** `0` match, `1` drift, `2` the inspection could not run (configuration, connection, permissions,
  missing `vendor/`), `3` the manifest is stale or the usage is wrong. `--offline` validates only the manifest
  against the tree (no database, no `vendor/`) and exits `0` or `3`.
- **Connection.** `SYNTH_DB_*` from the environment, then `conf/conf.php`, as the runtime reads them. Point it at a
  disposable database for tests. A match proves only what [Drift check limits](#drift-check-limits) lists.

### Drift check limits

It does not compare view or function bodies, triggers, sequence state, ownership, privileges, extensions or any row.
Equivalent SQL that PostgreSQL deparses differently reports drift. It repairs and generates nothing, and it does
not replace the baseline's own verification.

## Limits

- A fresh install seeds the stock values of `conf/conf.sample.php`. The legacy chain also copied an existing
  `conf/conf.php`'s legacy values into new managed settings and narrator rows; the fresh path does not, so such
  values are set in the UI after installing. Existing databases are unaffected.
- A non-empty database without a ledger still takes the legacy chain, including one that is neither empty nor
  a SYNTH install; such a database is usually refused by verification and is never reset.
- `ui/cmd/install-db.php` (an explicit reset command, command line only; web requests are refused) and the shared `DatabaseTestCase` fixture still import
  `data/database_default.sql` and run the chain, which stay as legacy-upgrade sources. The fixture therefore
  does not exercise the fresh path; `SchemaMigrationOwnerTest` does.
- The fresh path was proven on disposable PostgreSQL 17.11 only, not on an installed database, through the WSL
  provisioner, or in hosted CI.
- Every entry needs the root `vendor/`. `tools/create-synth-db-wsl.ps1` fails before touching the database
  and asks for `composer install --no-dev` when the checkout has none.
- UI callers that wrapped the old `require_once` in `try`/`catch` still swallow failures, which now include
  the owner's refusals. Quickstart saves no longer do (see Readiness); the other pages are unchanged.
- The [install and upgrade checklist](ALPHA-ACCEPTANCE.md#install-and-upgrade) records readiness verification and remaining setup checks.
- The shared in-process test fixture still lacks `synth.synthnpcs` for every class after the first (the
  pre-existing per-process flag above; base behavior, unchanged here).
- Legacy playthrough snapshots are paused. The old UI cloned `public` instead of the `synth` application schema,
  and its switch dropped `public` (with every `vector` column) and truncated `synth.database_versioning`.
  The Timeline Break autosnapshot copied `synth` without a migration version. Now:
  - `ui/playthrough_manager.php` only lists and inspects profiles. It refuses every request other than GET or
    HEAD (405) before loading configuration or the database, and GET no longer captures a `default` profile.
  - In `lib/playthrough_schema.php`, `pts_clone_schema`, `pts_drop_schema` and `pts_recreate_public_schema`
    refuse without using the connection (same array/bool returns). `pts_metadata_schema_ready` and
    `pts_ensure_functions` only read; neither installs the clone functions (`debug/db_updates.php` still
    does, unchanged).
  - `timeline_break_create_snapshot` returns 0, its existing "no snapshot" result, before any connection,
    lock or clone. Native save identity, checkpoints and rollback pruning are unchanged.
    Legacy rollback pruning therefore continues without an automatic snapshot; native scoped save and
    checkpoint loading remains separate.
  - Existing snapshot schemas and profile rows are kept as they are and are not marked compatible.
- Re-enabling snapshots needs a reviewed design first: snapshots record their migration versions, and restore
  refuses a set that does not match the ledger. The [compatibility guard](#snapshot-compatibility-guard) is the
  owner-side check for this. Metadata persistence, the capture and restore themselves, and the UI and runtime
  wiring are still missing. This is separate from ordinary native co-save loading, which selects a playthrough
  without swapping schemas.
- An install already switched by the old UI is treated by the owner as an invalid baseline, and every
  bootstrap and dashboard run is refused; it is not repaired silently. Deleting the ledger row or
  `revertTo('first')` is not a recovery: it cannot restore lost data. Recovery needs a verified backup or a
  reviewed structural repair, approved separately, and must preserve the existing install.
- Once the baseline is recorded, a missing managed setting is refused rather than silently reseeded by the
  legacy chain.
- Checks: [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md#schema-migration-owner).
