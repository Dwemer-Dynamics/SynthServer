# Doctrine settings read pilot

`Synth\Core\SettingsRepository::value()` and `values()` read `synth.general_settings` through
Doctrine ORM. This pilot covers reads only. Writes, locks, schema and migrations keep their
current owners.

## Source

- `composer.json`, `composer.lock`: production dependencies only (Doctrine ORM 3, DBAL pinned to
  4.5). Resolution is pinned to `config.platform.php` 8.2.35, the PHP 8.2 floor in the README, so
  the lock is valid for DwemerDistro's Debian 12 PHP 8.2 and every newer supported branch.
- `core/dbmanager/repositories/SettingsRepository.php`: same constructor and method signatures.
- `core/dbmanager/entities/GeneralSetting.php`: read-only mapping of `id` and `value`.
- `core/dbmanager/SharedSessionDriver.php`: DBAL driver over the legacy `sql` link.
- `core/dbmanager/EntityManagerFactory.php`: the EntityManager. It has no credentials, cache,
  schema tool, migrations or writable path of its own.

## Connection lifetime

Reads share the legacy `sql` wrapper's PostgreSQL session. Each statement runs through that
wrapper's own `execQuery()`, with Doctrine's placeholders converted to `$1`-style. So it gets the
same `re_connect()`, `search_path`, transaction, `DB_EXECUTION_TIME` accounting, slow-query
warning and failure log as any legacy query. The adapter logs nothing itself. A separate
Doctrine connection was rejected for three reasons. It would not
see a write still uncommitted in the caller's transaction. It would add a backend and credentials per worker. And it could disagree with
`search_path`. DBAL's own `pgsql` driver was also rejected. Its connection calls `pg_close()` on
destruction, and its statements create and `DEALLOCATE` named prepared statements on the session.

`execQuery()` runs each statement as an unnamed `pg_query_params`, so no prepared statement is
left on the session. On failure the adapter reports `GetLastError()` to DBAL without
reconnecting. It refuses `BEGIN`, `COMMIT`, `ROLLBACK` and `lastInsertId`. `quote()`, the server
version and the native handle use `getCatalogueSqlConnection()`. Reads use array or scalar
hydration, never the identity map, so a direct legacy write is visible to the next read.

## Behavior kept

- `value()` returns the stored string, `''` for SQL `NULL`, and `null` for a missing key.
  When the read fails, `execQuery()` logs it once and `value()` returns `null`, as before.
- `values()` is one native query with scalar result mapping and one bound `text[]` parameter
  (`id = ANY(CAST(? AS text[]))`), as before. DQL `IN (:ids)` was rejected: it binds one parameter
  per key and fails past PostgreSQL's 65535-parameter limit. Any key count is one statement, and an
  empty list still runs.
- `values()` returns `id => value` for the keys that exist. When the read fails, it throws
  `RuntimeException('Unable to read general settings')`, as before. Numeric-string keys become
  PHP integer keys, as before.
- Keys are compared exactly, by the text primary key. Reads take no advisory or row locks.
- New failure: if `vendor/autoload.php` is missing, the first read throws `RuntimeException`.
  It never falls back to another read path.

## Install and upgrade

`tools/deploy-synthserver-wsl.ps1` copies the two Composer files into a private `/tmp` staging
directory. It then uses the distro's own Composer (Debian 12: 2.5.5), running as `nobody` with
plugins and scripts disabled. It runs `composer validate` (exit 2 on a stale lock),
`install --no-dev` and `check-platform-reqs --no-dev`. It syncs `vendor/` into the target
before the source. The source `rsync --delete` excludes `/vendor/`. CI and the loopback runner
install and bind the same lock.

## Checks and limits

See [ALPHA-ACCEPTANCE.md](ALPHA-ACCEPTANCE.md#doctrine-settings-read-pilot).

- `@internal` dependency: `Doctrine\DBAL\SQL\Parser` is marked `@internal`, which is why DBAL
  is pinned to one minor release. Review the adapter before you widen that pin.
- Nesting count: DBAL increments its transaction nesting level before the adapter refuses
  `BEGIN`. A second `beginTransaction()` on the same EntityManager would issue `SAVEPOINT`. The
  repository never calls it, and its EntityManager is private.
- SQLSTATE: `execQuery()` returns only `false` on failure, so DBAL gets the error text without a
  SQLSTATE and raises its generic driver exception. Both methods catch that, as before.
- Network: each install runs with a fresh temporary Composer cache and downloads the locked
  packages from their pinned commits, so the installer requires network access. No offline
  install path exists.
