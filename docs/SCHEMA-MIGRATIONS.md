# Schema migration owner

> A from-scratch redesign of the whole schema (one new baseline on the CHIM Evolution `core_*` entity, DAO model) is proposed in
> [SCHEMA-REDESIGN.md](SCHEMA-REDESIGN.md). The design is approved and implemented step by step. Step 0 (scaffolding) has
> landed: the baseline is renamed `Version20261117000000` with its DDL and companion file contents unchanged (the generated
> comment line names the new id), so a database that recorded `Version20261115000000` is refused as an unknown record and is
> recreated, not migrated (no upgrade path, by decision); `core/dbmanager/Database.php`, `Types/PreciseDateTimeType.php`,
> `core/entities/{base,system}` and `core/dbmanager/dao` exist with no entity yet; the drift check also inspects `public`
> relations named `core_*`; and the shared test fixture installs the baseline through `tools/bootstrap-database.php` instead of
> importing the default SQL and running the legacy chain. Text below that names `Version20261115000000` means this baseline.

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
- `core/dbmanager/migrations/Version20261117000000.php`: the frozen baseline, its fresh install and its
  verification. `Version20261117000000.structure.txt` is generated data: the structure manifest of the
  baseline. `Version20261117000000.fresh.sql` is generated data too: the statements that install the baseline
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

- Empty means (`Version20261117000000::isEmptyDatabase()`): no schema besides `public` and `synth_meta`, and in
  those two no relation, function or standalone type that is not an extension member, apart from the Doctrine
  version table. Extensions may exist. Any other database, including one holding only an unrelated table in
  `public`, keeps the legacy path above. Nothing is reset or dropped automatically.
- `up()` re-checks emptiness inside the transaction and adds the statements of
  `Version20261117000000.fresh.sql` one by one. DBAL's `pgsql` driver reads only the first result of a
  multi-statement query, so a batch would hide a later failure. The file must match the SHA-256 recorded in
  the class (after CRLF to LF; the file holds no CR) or the migration aborts before any statement.
- The statements set `SET LOCAL` session values, create `vector` in `public`, the `synth`, `synth_meta` and
  `plugins` schemas, `pg_trgm` in `synth` (where the legacy chain puts it; a provisioner that already created
  it in `public` keeps that), then every table, view, function, sequence position, index, constraint and
  comment, and the stock rows. Seed timestamps are the install time (`CURRENT_TIMESTAMP`), as with the chain.
- The bootstrap then runs as before: it records nothing else, rechecks the gate and loads the settings (the
  narrator loader still seeds its missing keys there, on both paths).

### Provenance and regeneration

`Version20261117000000.fresh.sql` was exported from the legacy fresh install of unstable `0f4fadc`
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
| D1 | Conventions apply to new work only. Existing `core_*` and other legacy names stay; nothing is renamed. **Amended for API keys by D5**: the connectors' `api_badge_id` is renamed `local_api_key_id` and `api_key_id` is added. |
| D2 | New tables are named `synth_meta.<domain>_<noun>` (for example `synth_meta.snapshot_versions`). |
| D3 | The manifest has one entry per migration (`migrations/schema-manifest.json`), never a combined entry. |
| D4 | The ORM pilot (`GeneralSetting`, see [DOCTRINE-SETTINGS-PILOT.md](DOCTRINE-SETTINGS-PILOT.md)) is frozen. No new ORM entities; new persistence code uses DBAL repositories. |
| D5 | API keys are global by default (the shared `dwemer` database's `api_keys` table, the CHIM 4 server's store) with SYNTH-only keys in `synth.core_api_badge`; a connector names one key in one store through `api_key_id` (uuid) or `local_api_key_id` (integer), or none for the provider's global key. See [Shared API keys](#shared-api-keys). **Supersedes the earlier "no shared `api_keys` yet".** |
| D6 | The first schema migration is the shared API key cutover (D5). Snapshot version metadata (see the paused snapshots under [Limits](#limits)) follows. |

Plan order: conventions docs, read-only tooling (the [drift check](#drift-check)), DBAL repositories, a review gate,
then the first migration, which is the shared API key cutover (D5, draft pull request). Snapshot re-enablement is not part of it.

### Open questions for review

1. What "CHIM 4.0 planning format" means exactly, and which parts of it SYNTH should follow. This note only
   assumes the observable ChimServer pieces: a format-numbered manifest with per-migration hashes, a read-only
   `schema-check` and per-domain DBAL stores.
2. Which PostgreSQL major the manifest should target. The baseline structure was generated on PostgreSQL 15; SYNTH
   is validated on 17 by local disposable-cluster checks only (no hosted CI pass exists). The drift check reports the server major and does not refuse
   another one. ChimServer keeps one manifest per reviewed major; SYNTH has one.
3. Acceptance of D2 (`synth_meta.<domain>_<noun>`). Until it is accepted, no new table should be created.

## Shared API keys

**Status: draft, not merged.** This is the first schema-changing migration. It was proven on disposable PostgreSQL 17 only.

**DwemerDistro registration of SYNTH is NOT active.** The canonical bridge only knows the legacy products and SOULKILLER, so CHIM cannot see which global keys SYNTH uses and can still delete one SYNTH's connectors name. SYNTH refuses to delete such a key itself, and the API Keys page warns about this until Distro's registry lists SYNTH (`ApiBadgeDao::distroRegistered()`; `php tools/shared-api-keys.php status`). The paired change is below.

API keys are **global by default** and SYNTH can also keep **SYNTH-only** keys. Global keys live in the `api_keys` table of the
shared PostgreSQL database `dwemer`, the store the CHIM 4 server uses and other Dwemer mods share. SYNTH-only keys live in
`synth.core_api_badge` (`id`, `label`, `api_key`, `UNIQUE (label)`, the shape of chevo's table) and are meant for providers the
shared table cannot hold (Player2 and other local services, region-specific Azure, any custom endpoint) or for a key a user wants
to keep out of the shared store, for any provider. Reference implementation for the global side: ChimServer
`Config/DBManager/Runtime/ApiKeyStore.php`, `Core/ApiKeyManager`, `Config/DBManager/Schema/shared-pg17.json` (read at `804e501`).
No existing install is migrated to the shared store: there is no import and no mirror.

### Rules

- **Global by default.** SYNTH lists and uses the shared keys; a key added in CHIM appears in SYNTH with no extra step.
  Quickstart and the provider cards read and write the provider's global key (the oldest shared key labelled like the provider, any
  letter case). A connector that names no key uses the global key of its provider **when it is used**; nothing is stored for it.
- **SYNTH-only on request.** A connector uses a SYNTH-only key only when the user picks it in the connector's key list.
- **One store per reference, no silent fallback.** A connector names a key in exactly one store, or none. A named key that is
  missing, deleted or unreadable is "no key"; it never turns into the other store's key or another key. With none named and no
  global key for the provider, there is no key (never a SYNTH-only key).
- **Edits are global.** Editing or deleting a global key from SYNTH changes it for every mod. The API Keys page says so. A key a
  connector uses cannot be deleted from SYNTH, in either store. CHIM refuses to delete a shared key SYNTH uses only once the paired
  Distro change ships (below).
- **Origin pinning at use.** A global key is bound to one origin. `ApiBadge::keyForConnector` is the one resolver every connector,
  worker and Test path uses; it releases a global key only when the endpoint it is about to call has that origin (host and port),
  else it returns no key and logs one fixed line, as ChimServer's `resolveSecret` does. A SYNTH-only key has no pinned origin.
  Azure, Replicate and DeepL global keys (whose endpoint varies) record a placeholder origin and are not pinned.
- **Provider-matched defaults.** The default key of a provider is the oldest shared key whose provider is the one the label names;
  a custom key that only carries the label is never the default.
- **Optimistic concurrency.** Global edits and deletes take the revision the form was rendered with; a key changed elsewhere is
  refused (`conflict`) and the page says it changed elsewhere.
- **Bind lock.** Saving a connector that names a shared key runs under `FOR SHARE` on that key and refuses a missing or revoked key.
- **#53/#54 apply to both groups:** keys are write-only, a blank field preserves the key, Clear is explicit (confirmed, with the
  session token), the saved-key Test resolves server-side by exact reference, ids are strict, Delete takes one exact id with the
  management token. A global key is not cleared from SYNTH (that would remove it for every mod): its card offers Delete, with a
  warning that it deletes for every mod, and Clear is refused. Clear on a SYNTH-only key empties it (the entry and label stay).
- **Shared store absent** (no `CHIM_SHARED_DB_*`, unreachable, or no `api_keys` table): only SYNTH-only keys work. Global keys read
  as none and every global write is refused, with no warning and no log line. `ApiBadgeRepository::unavailableReason()` /
  `unavailableMessage()` give one fixed sentence (it says that only SYNTH-only keys work and what to set or run); the API Keys page
  and Quickstart show it and `health.php` reports `api_key_store` (`ok`, `not_configured`, `unreachable`, `not_migrated`) as
  advisory data that does not change the status code.

### Schema (`Version20261117100000`)

Each of `core_llm_connector`, `core_tts_connector`, `core_stt_connector` and `core_itt_connector` has two typed columns and a CHECK:

| Column | Type | Meaning |
|---|---|---|
| `api_key_id` | `uuid NULL` | a shared key's `key_id`; no foreign key (another database) |
| `local_api_key_id` | `integer NULL` | a SYNTH-only key's `core_api_badge.id`; the old `api_badge_id` renamed, foreign key kept |
| `<table>_one_key_store` | CHECK | `api_key_id IS NULL OR local_api_key_id IS NULL` |

Both null means "no key chosen" (use the provider's global key). The legacy chain's schema files build the same shape, so the
migration is idempotent. On an install that already holds keys the migration keeps every non-empty `core_api_badge` row (id, label,
key) and every connector reference to one; it clears references to the empty seed rows and deletes those empty rows, so the
SYNTH-only list starts with only what a user entered. The frozen baseline's structure manifest no longer lists the four
`api_badge_id` columns and their foreign keys; its other lines are unchanged. The migration also creates the public view
`public.synth_key_references (id, kind, label, shared_key_id)`, one row per connector that names a shared key (kinds `model`,
`voice`, `stt`, `itt`), which is what DwemerDistro's bridge reads. `down()` does nothing (a revert removes only the ledger record).

### Facade contract (stable)

A key is addressed by an **`ApiKeyRef`**, the one handle the application passes around: the string `shared:<uuid>` (a canonical
lower-case uuid) or `local:<integer>` (a positive integer without leading zeros). Parsing is exact: padding, upper case, signs,
suffixes, arrays and bare ids are refused. A connector row stores it as the two columns above. The connector classes
(`LLMConnector`, `TTSConnector`, `STTConnector`, `ITTConnector`) show it to the application as `api_key_id` (a reference string
or `null`; `local_api_key_id` is not exposed) and accept it the same way on `create`/`update`.

| Class | Path | Notes |
|---|---|---|
| `Synth\Core\Contracts\ApiKeyRef` | `core/contracts/ApiKeyRef.php` | `parse($v): ?self`, `shared(string $uuid): ?self`, `local(int $id): ?self`, `fromRow(array $row): ?self` (typed columns), `fromAny(array $row): ?self`, `normalize($v): ?string`, `toColumns(?self): array` (`['api_key_id' => ?string, 'local_api_key_id' => ?int]`), `decorateRow(array): array`, `columnsFromData(array): array`, `toString()`, `store()`, `id()`, `isShared()` |
| `Synth\Core\Entities\System\ApiBadge` | `core/entities/system/ApiBadge.php` | read view, never the secret: `ref()`, `label()`, `store()`, `provider()`, `origin()`, `revision()`, `hasKey()`, `toArray()` |
| `Synth\Core\DbManager\Dao\ApiBadgeDao` | `core/dbmanager/dao/ApiBadgeDao.php` | `listAll(): list<ApiBadge>` (global first), `find(ApiKeyRef): ?ApiBadge`, `findByLabel(string, ?string $store = null): ?ApiBadge`, `defaultForProvider(string $label): ?ApiBadge` (oldest global key whose provider is the label's provider), `secret(ApiKeyRef): string`, `secretForEndpoint(ApiKeyRef, string $endpoint, string $providerLabel = ''): string` (the origin-pinned resolver), `bindUnderLock(ApiKeyRef, callable $write): array` (`locked`, `missing`, `revoked`, `unavailable`), `create(string $store, string $label, string $secret, array $options = []): ?ApiKeyRef`, `update(ApiKeyRef, ?string $label, ?string $secret, ?int $expectedRevision = null): bool` (a changed global key: false and `lastError()` is `conflict`), `delete(ApiKeyRef, ?int $expectedRevision = null): string` (`deleted`, `in_use`, `conflict`, `missing`, `revoked`, `unavailable`), `storeStatus(): array`, `listWithSecrets(): array`, `lastError(): string`; static `unavailableReason()`, `unavailableMessage()`, `distroRegistered()`, `useSharedStore()` |
| `Synth\Core\DbManager\Dao\ConnectorReferences` | `core/dbmanager/dao/ConnectorReferences.php` | `usedBy(ApiKeyRef): array`, `forSharedKey(string $uuid): array`, `isUsed(ApiKeyRef): bool` |
| `Synth\Core\SharedApiKeyStore` | `core/dbmanager/SharedApiKeyStore.php` | the shared-store gateway (ChimServer's `ApiKeyStore` rules) |
| `Synth\Core\ApiBadgeRepository`, `ApiBadge` | `core/dbmanager/repositories/ApiBadgeRepository.php`, `lib/core/api_badge.class.php` | thin array-returning call-throughs for the existing callers; `ApiBadge::keyForConnector(array $row, string $providerLabel = '', string $endpoint = ''): string` is the one resolver (named key, else the provider's global key, released only for the key's origin); `ApiBadge::lockedWrite($sharedUuid, callable, $refused)` runs a connector write under the bind lock; `update`/`deleteOutcome` take the expected revision |

Create takes `$store` `'shared'` or `'local'`. A global key's provider defaults to the one its label names (`openrouter`, `openai`,
`inworld`, `deepgram`, `google`/`gemini`, `elevenlabs`, `cartesia`, `nano-gpt`, `groq`, `atlascloud`, `replicate`, `azure`, `deepl`;
Azure, Replicate and DeepL record a default origin SYNTH never sends a key by); any other label is a custom key (`custom_openai`)
that needs an `origin` option (https, or http to loopback). A shared key is 1-4096 printable ASCII characters without spaces and
cannot be empty; a SYNTH-only key can be anything on one line, can be empty (its Clear), and its label is unique in exact case.

### Delete safety across products

- **SYNTH side:** `ApiBadgeDao::delete` refuses (`in_use`) while any SYNTH connector names the key, for both stores. For a shared key
  the check runs on SYNTH's database while the shared row is locked `FOR UPDATE` (ChimServer's lock order: shared row first).
- **CHIM side:** ChimServer's `deleteKey` also refuses while `ApiKeyManager::externalUsage()` is not empty. That reads DwemerDistro's
  root-owned registry `/etc/dwemer/shared-key-consumers.json` and the bridge `/usr/local/lib/dwemerdistro/shared_keys.php`
  (`DistroSharedKeys::consumerUsage`), which query each registered product database. SYNTH publishes its references through
  `public.synth_key_references`; `ConnectorReferences::forSharedKey` is the same query in PHP. DwemerDistro does not know SYNTH yet,
  so a **paired Distro change** is required (below). Until it ships, CHIM can delete a shared key SYNTH uses; SYNTH then reads that
  connector as having no key.

### Paired DwemerDistro change (required; not applied, DwemerDistro is not edited here)

1. `bin/ddistro_shared_keys.php` (the bridge) gains a `synth` product with one table. The diff below was applied to a copy of
   canonical `bin/ddistro_shared_keys.php` and exercised in the probe (usage rows came back as expected):

```diff
--- a/bin/ddistro_shared_keys.php
+++ b/bin/ddistro_shared_keys.php
@@ -20,6 +20,8 @@
     public const STT_PROVIDERS = ['openai', 'deepgram', 'inworld', 'custom_openai'];
     private const LEGACY = ['herika' => 'CHIM legacy', 'stobe' => 'STOBE', 'dialectic' => 'Dialectic'];
     private const SAVED_CONNECTORS = 'ex_saved_connectors';
+    /** SYNTH publishes the shared keys its connectors use through this view of its own database (migration Version20261117100000). */
+    private const SYNTH_REFERENCES = 'synth_key_references';
 
     /** Preparation checks actual metadata-role access before adding a database to the deletion registry. */
     public static function checkSavedConnectorDatabase(string $database): void {
@@ -58,6 +60,17 @@
                     }
                     continue;
                 }
+                if ($product['id'] === 'synth') {
+                    // One row per connector that references a shared key: model (LLM), voice (TTS), stt and itt.
+                    $query = 'SELECT id, kind, label, shared_key_id::text FROM public.' . self::SYNTH_REFERENCES . ' WHERE shared_key_id IS NOT NULL'
+                        . ($keyId === null ? '' : ' AND shared_key_id = $1::uuid') . ' ORDER BY kind, id LIMIT 129';
+                    foreach (self::boundedRows($connection, $query, $keyId) as $row) {
+                        $usage[] = ['key_id' => $row['shared_key_id'], 'connection_id' => (int)$row['id'],
+                            'label' => 'SYNTH: ' . $row['label'] . ' (' . $row['kind'] . ')', 'status' => 'active', 'product' => 'synth',
+                            'table' => self::SYNTH_REFERENCES];
+                    }
+                    continue;
+                }
                 foreach ($product['tables'] as $table) {
                     $displayColumn = $product['id'] === 'stobe' && $table !== 'core_api_badge' ? 'name' : 'label';
                     $query = 'SELECT id, ' . $displayColumn . ' AS label, shared_key_id::text FROM public.' . $table
@@ -189,16 +202,16 @@
         }
         $products = [];
         foreach ($registry['products'] as $product) {
-            if (!is_array($product) || !in_array($product['id'] ?? null, [...array_keys(self::LEGACY), 'soulkiller'], true)
+            if (!is_array($product) || !in_array($product['id'] ?? null, [...array_keys(self::LEGACY), 'soulkiller', 'synth'], true)
                 || !is_string($product['database'] ?? null)
                 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/D', $product['database']) !== 1
                 || isset($products[$product['id']])) {
                 throw new RuntimeException('Shared key usage is unavailable');
             }
             $tables = $product['tables'] ?? ['core_api_badge'];
-            $allowed = $product['id'] === 'soulkiller' ? [self::SAVED_CONNECTORS] : ['core_api_badge', 'core_llm_connector', 'core_tts_connector'];
+            $allowed = $product['id'] === 'soulkiller' ? [self::SAVED_CONNECTORS] : ($product['id'] === 'synth' ? [self::SYNTH_REFERENCES] : ['core_api_badge', 'core_llm_connector', 'core_tts_connector']);
             if (!is_array($tables) || count($tables) > 3 || count(array_unique($tables)) !== count($tables)
-                || array_diff($tables, $allowed) !== [] || ($product['id'] === 'soulkiller' && $tables !== $allowed)) {
+                || array_diff($tables, $allowed) !== [] || (in_array($product['id'], ['soulkiller', 'synth'], true) && $tables !== $allowed)) {
                 throw new RuntimeException('Shared key usage is unavailable');
             }
             $products[$product['id']] = ['id' => $product['id'], 'database' => $product['database'], 'tables' => $tables];
```

2. The installer (`bin/ddistro_keys`) must treat SYNTH as a **native** consumer, like SOULKILLER, not as a "legacy" product: its
   `prepare_legacy` path runs `ddistro_legacy_keys.sql`, which requires real tables (`relkind = 'r'`), adds a `shared_key_id` column
   and installs a binding function, none of which applies to SYNTH. A `prepare_synth(role, entries, warnings)` is needed that, in
   the SYNTH database only, runs `GRANT CONNECT ON DATABASE <db> TO <role>; GRANT USAGE ON SCHEMA public TO <role>; GRANT SELECT ON public.synth_key_references TO <role>;`
   (the migration creates the view as the SYNTH database owner), checks that `public.synth_key_references` has columns `id`,
   `kind`, `label`, `shared_key_id`, and then registers `{"id": "synth", "database": "<SYNTH database>", "tables": ["synth_key_references"]}`
   in `/etc/dwemer/shared-key-consumers.json` with the other products (same atomic write). The installer part is **not tested**.
3. `CHIM_SHARED_DB_*` must reach SYNTH's web server and background workers. Proposed: the Distro service definitions that start SYNTH's Apache/PHP and its workers read the root-owned `/etc/dwemer/shared-keys.env` (the file the bridge already reads for the metadata role) and export only the five `CHIM_SHARED_DB_HOST`/`_PORT`/`_NAME`/`_USER`/`_PASSWORD` values, for the shared runtime role (never the migration owner). This is described, not written or tested.

### Operator order

1. `vendor/bin/doctrine-migrations migrate --configuration=core/dbmanager/shared-migrations.php --db-configuration=core/dbmanager/shared-migrations-db.php --no-interaction`
   (a no-op when ChimServer already did it), then grant the runtime role as in ChimServer's `docs/s19-api-keys.md`.
2. Set `CHIM_SHARED_DB_*` for SYNTH, then `php tools/bootstrap-database.php` (applies `Version20261117100000`).
3. Check `health.php` (`api_key_store`); enter global keys on the API Keys page or in Quickstart, SYNTH-only keys in the second group.

### Shared schema

ChimServer owns it. `core/dbmanager/shared-migrations/` holds byte-identical copies of its four shared migrations (hashes pinned in
`core/dbmanager/shared-schema-manifest.json`, equal to `migration_hashes` of `shared-pg17.json`). They keep the namespace
`ChimServer\SharedMigrations` and the table `dwemer_shared_migration_versions` on purpose: the ledger stores fully qualified class
names, so a SYNTH-named copy would record a second, parallel history. Whichever product migrates first creates `api_keys`.

### Other behaviour

- **Player2** needs no key (its connector sends its fixed game key when it has none). A Quickstart local-model key is a SYNTH-only key.
- **Profile import** does not create keys: a connector whose key is not saved here has no key until one is chosen.
- **Local-model setup** runs inside its own transaction, so a connector it saves is not under the bind lock; it only ever binds SYNTH-only keys.

### Open questions

1. **Environment delivery.** Where do `CHIM_SHARED_DB_*` reach SYNTH's Apache/PHP and workers on a Distro install?
2. **Distro registration.** Not active until the paired Distro change (and the delivery of `CHIM_SHARED_DB_*` to SYNTH's web server and workers) ships.
3. **Sealed legacy keys** in the shared store read as no key until replaced (SYNTH has no installation key).
4. **Azure, Replicate and DeepL** global keys use placeholder origins and are not pinned; should they take an entered endpoint like custom keys?
5. **Installer.** The Distro installer change in item 2 above is described, not written or tested.

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
- `bin/install-db.php` (an explicit reset command, command line only; web requests are refused) still imports
  `data/database_default.sql` and runs the chain, which stay as legacy-upgrade sources until rollout step 8 of the redesign.
  The shared `DatabaseTestCase` fixture no longer does: it installs the baseline through `tools/bootstrap-database.php`
  (the fresh path); `SchemaMigrationOwnerTest` still builds the legacy install as its comparison reference.
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
