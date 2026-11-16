<?php

namespace Synth\Core\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Synth\Migrations\Version20261115000000 as LegacyBaseline;

// The one owner of versioned schema changes. tools/bootstrap-database.php and every caller of the debug/db_updates.php
// compatibility entry come here; the frozen legacy chain in that file runs only from inside this class. It uses its
// own transaction-capable PostgreSQL connection, never the shared-session read adapter (which refuses transactions
// and must not end a legacy-owned one). Every invocation holds one session advisory lock for its whole run, so
// concurrent runs go one at a time; a killed run releases the lock with its session and its open migration
// transaction rolls back.
final class SchemaMigrations
{
    public const METADATA_TABLE = 'synth_meta.doctrine_migration_versions';
    private const LOCK_NAME = 'synth-schema-migrations';
    private const LEGACY_LOCK_NAME = 'synth-schema-migrations-legacy';
    // Snapshot compatibility metadata. The only supported source is the synth application schema; a new metadata
    // layout needs a new format value, never a reinterpretation of this one.
    public const SNAPSHOT_FORMAT = 'synth-schema-snapshot.v1';
    public const SNAPSHOT_SCHEMA = 'synth';

    // Set only while this class itself includes debug/db_updates.php; any other include of that file delegates here.
    private static bool $runningLegacyBody = false;

    private bool $locked = false;

    /** @param list<class-string> $additionalMigrations */
    private function __construct(private readonly Connection $connection, private readonly int $lockWaitSeconds,
        private readonly array $additionalMigrations) {}

    public static function isRunningLegacyBody(): bool
    {
        return self::$runningLegacyBody;
    }

    /**
     * $additionalMigrations is for tests only: already-declared migration classes registered beside this tree's, so a
     * later migration can be proven against the owner without shipping one.
     *
     * @param array{host:string,port:string,dbname:string,username:string,password:string} $settings
     * @param list<class-string> $additionalMigrations
     */
    public static function connect(#[\SensitiveParameter] array $settings, int $lockWaitSeconds = 1800,
        array $additionalMigrations = []): self
    {
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException('Synthserver production dependencies are missing; run composer install --no-dev in the server root');
        }
        require_once $autoload;
        require_once __DIR__.'/migrations/Version20261115000000.php';

        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pgsql',
                'host' => $settings['host'],
                'port' => (int) $settings['port'],
                'dbname' => $settings['dbname'],
                'user' => $settings['username'],
                'password' => $settings['password'],
                'application_name' => 'synth-schema-migrations',
            ]);
            $connection->executeStatement('SET search_path TO synth, synth_meta, public');
        } catch (\Throwable) {
            // Driver messages can carry connection details; the caller gets a fixed message.
            throw new \RuntimeException('SYNTH schema migrations could not open their database connection');
        }
        return new self($connection, max(0, $lockWaitSeconds), array_values($additionalMigrations));
    }

    /**
     * $settings with host, port and database taken from the legacy session's own connection. The legacy class ignores
     * SYNTH_DB_PORT (and the test adapter every setting), so this is the database the legacy chain and gate act on.
     */
    public static function settingsForLegacySession(object $legacy, #[\SensitiveParameter] array $settings): array
    {
        $link = $legacy->getCatalogueSqlConnection();
        $host = strval(pg_host($link));
        return array_merge($settings, [
            'host' => $host !== '' ? $host : $settings['host'],
            'port' => strval(pg_port($link)) !== '' ? strval(pg_port($link)) : $settings['port'],
            'dbname' => strval(pg_dbname($link)),
        ]);
    }

    /**
     * Brings the database to the latest migration and returns the recorded versions. Records this tree does not
     * contain are refused before anything changes. An empty database (LegacyBaseline::isEmptyDatabase) gets no legacy
     * chain: the baseline migration installs its frozen schema and seeds in the same transaction as every later
     * migration and the version records. Before the baseline is recorded, any other install whose legacy versions
     * are older than the frozen set gets the legacy chain first; one that already claims them is verified as it
     * stands. The baseline migration verifies and is recorded only if the result is complete. With the baseline as
     * the latest record the schema is re-verified against the baseline's frozen contract (never the current runtime's
     * readiness gate, which may already expect a pending migration's work) and refused if damaged, never repaired by
     * the legacy chain; once later migrations are recorded they own the schema and the legacy checks no longer apply.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        return $this->underLock(function (): array {
            $legacy = $this->legacySession();
            $factory = $this->factory();
            $this->refuseUnknownRecords($factory);
            return $this->withLegacyLock($legacy, function () use ($legacy, $factory): array {
                $recorded = $this->recordedVersions();
                if ($recorded === []) {
                    // An empty database is installed by the baseline migration itself, inside the transaction below.
                    if (LegacyBaseline::isEmptyDatabase($this->connection)) return $this->migrateTo($factory, 'latest', true);
                    if (!LegacyBaseline::legacyVersionsClaimCurrent($this->connection)) $this->runLegacyBody($legacy);
                } elseif ($recorded === [LegacyBaseline::class]) {
                    $problems = LegacyBaseline::problems($this->connection);
                    if ($problems !== []) {
                        throw new \RuntimeException('The database no longer matches its recorded legacy baseline and is not repaired automatically: '
                            .implode('; ', $problems));
                    }
                }
                return $this->migrateTo($factory, 'latest');
            });
        });
    }

    /**
     * Runs the frozen legacy chain on a database with no migration records, under the same locks, and records nothing.
     * Test fixtures use it to build a true pre-ledger legacy install; everything else uses migrate().
     */
    public function applyLegacyChainWithoutLedger(): void
    {
        $this->underLock(function (): void {
            $legacy = $this->legacySession();
            if ($this->recordedVersions() !== []) {
                throw new \RuntimeException('This database records schema migrations; the legacy chain runs only through migrate()');
            }
            $this->withLegacyLock($legacy, fn () => $this->runLegacyBody($legacy));
        });
    }

    /**
     * Reverts recorded migrations down to $version ('first' removes every record). Only Doctrine migrations run;
     * reverting the baseline removes its record and leaves the legacy schema and rows untouched.
     *
     * @return list<string>
     */
    public function revertTo(string $version): array
    {
        return $this->underLock(function () use ($version): array {
            $factory = $this->factory();
            $this->refuseUnknownRecords($factory);
            return $this->migrateTo($factory, $version);
        });
    }

    /**
     * Snapshot compatibility guard for a future capture of $source, which must be the synth schema. Under the owner's
     * lock and in one owner transaction it reads the live ledger once (refused when missing, empty or holding a
     * version this tree does not contain) and calls $capture with the metadata the snapshot must store beside it and
     * this owner's connection. Nothing is written here: storing the metadata and copying the schema belong to the
     * future capture, which has no dedicated column yet.
     *
     * Trusted callback contract (here and in restoreSnapshot): callbacks run on this owner's connection inside the
     * owner's DBAL transaction, while its session advisory lock is held. The owner commits when the last callback
     * returns and before it unlocks, and rolls back when one throws. Callbacks must not begin, commit or roll back
     * transactions, close or reconfigure the connection, release the lock, change the migration ledger, or call
     * another owner entry (nesting is refused).
     *
     * @template T
     * @param callable(array{format:string,schema:string,migration_versions:list<string>}, Connection):T $capture
     * @return T
     */
    public function captureSnapshot(string $source, callable $capture): mixed
    {
        if ($source !== self::SNAPSHOT_SCHEMA) {
            throw new \RuntimeException('Snapshots are captured only from the synth schema');
        }
        return $this->underSnapshotTransaction(fn (): mixed => $capture($this->snapshotMetadata(), $this->connection));
    }

    /**
     * Snapshot compatibility guard for a future restore. Under the owner's lock and in one owner transaction it reads
     * the live ledger once (refused like captureSnapshot), then calls $readTarget, which must only read the target
     * snapshot's rows, lock them (SELECT ... FOR UPDATE) and return them as an array whose 'metadata' is what capture
     * stored. Unless that metadata equals capture's metadata for the live ledger, the restore is refused and $restore
     * never runs; otherwise $restore receives that same checked target. The metadata is compared as an object: the
     * order of its top-level keys is ignored (a JSONB round trip may reorder them), but the field set, the scalar
     * types and values and the ordered version list must match exactly. Both follow captureSnapshot's callback
     * contract.
     *
     * @template T
     * @param callable(Connection):array{metadata:mixed} $readTarget
     * @param callable(array{metadata:mixed}, Connection):T $restore
     * @return T
     */
    public function restoreSnapshot(callable $readTarget, callable $restore): mixed
    {
        return $this->underSnapshotTransaction(function () use ($readTarget, $restore): mixed {
            $expected = $this->snapshotMetadata();
            $target = $readTarget($this->connection);
            if (!is_array($target) || self::canonicalObject($target['metadata'] ?? null) !== self::canonicalObject($expected)) {
                throw new \RuntimeException('The snapshot does not match the schema migrations of this database and is not restored');
            }
            return $restore($target, $this->connection);
        });
    }

    /** @return list<string> */
    public function recordedVersions(): array
    {
        if (!$this->metadataTableExists()) return [];
        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT version FROM '.self::METADATA_TABLE.' ORDER BY version'));
    }

    public function close(): void
    {
        $this->releaseLock();
        $this->connection->close();
    }

    private function factory(): DependencyFactory
    {
        $configuration = require __DIR__.'/migrations.php';
        if ($this->additionalMigrations !== []) $configuration['migrations'] = $this->additionalMigrations;
        return DependencyFactory::fromConnection(
            new ConfigurationArray($configuration),
            new ExistingConnection($this->connection)
        );
    }

    // A record this source tree does not know means the code is older than the database. Refuse before the legacy
    // chain or any migration can touch it. Returns the recorded versions it checked.
    /** @return list<string> */
    private function refuseUnknownRecords(DependencyFactory $factory): array
    {
        $known = array_map(static fn ($m): string => (string) $m->getVersion(),
            $factory->getMigrationRepository()->getMigrations()->getItems());
        $recorded = $this->recordedVersions();
        $unknown = array_values(array_diff($recorded, $known));
        if ($unknown !== []) {
            throw new \RuntimeException('The database records schema migrations this Synthserver version does not contain: '
                .implode(', ', $unknown));
        }
        return $recorded;
    }

    // One owner transaction around the version table and the plan: with $createMetadataSchema (an empty database) the
    // synth_meta schema the version table lives in, then the table, every migration and its record commit together or
    // not at all. Doctrine's all-or-nothing and per-migration transactions nest inside it as savepoints, and a failure
    // anywhere rolls the database back to where it started, so a rerun finds the same state.
    /** @return list<string> */
    private function migrateTo(DependencyFactory $factory, string $target, bool $createMetadataSchema = false): array
    {
        $this->connection->transactional(function () use ($factory, $target, $createMetadataSchema): void {
            if ($createMetadataSchema) $this->connection->executeStatement('CREATE SCHEMA IF NOT EXISTS synth_meta');
            $factory->getMetadataStorage()->ensureInitialized();
            $version = in_array($target, ['latest', 'first'], true)
                ? $factory->getVersionAliasResolver()->resolveVersionAlias($target)
                : new Version($target);
            $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
            // All-or-nothing: the plan and its version records commit together or not at all.
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));
        });
        return $this->recordedVersions();
    }

    // The legacy chain runs on the runtime sql session. It must reach this connection's database, and it must
    // be idle: the chain's statements would otherwise join, and could end, a transaction its caller owns.
    private function legacySession(): object
    {
        $legacy = $GLOBALS['db'] ?? null;
        if (!is_object($legacy) || !method_exists($legacy, 'isTransactionIdle')) {
            throw new \RuntimeException('SYNTH schema migrations need the legacy runtime session');
        }
        if (!$legacy->isTransactionIdle()) {
            throw new \RuntimeException('SYNTH schema migrations refuse to run while the legacy session has an open transaction');
        }
        $identity = "SELECT current_database() || '|' || coalesce(inet_server_port(), 0) || '|' || extract(epoch FROM pg_postmaster_start_time()) AS identity";
        $legacyIdentity = strval($legacy->fetchOne($identity)['identity'] ?? '');
        if ($legacyIdentity === '' || $legacyIdentity !== strval($this->connection->fetchOne($identity))) {
            throw new \RuntimeException('The schema migration connection and the legacy session reach different databases; check SYNTH_DB_HOST, SYNTH_DB_PORT and SYNTH_DB_NAME');
        }
        return $legacy;
    }

    // Also locked on the legacy session, under its own key, for the chain and the verification. After a
    // killed run, an orphaned chain statement still executing there keeps this lock until it ends, though the owner's
    // idle session has already released its own; a rerun waits for it instead of overlapping.
    /** @template T @param callable():T $work @return T */
    private function withLegacyLock(object $legacy, callable $work): mixed
    {
        if ($legacy->execQuery('SELECT pg_advisory_lock(hashtextextended($1, 0))', [self::LEGACY_LOCK_NAME]) === false) {
            throw new \RuntimeException('SYNTH schema migrations could not lock the legacy session');
        }
        try {
            return $work();
        } finally {
            $legacy->execQuery('SELECT pg_advisory_unlock(hashtextextended($1, 0))', [self::LEGACY_LOCK_NAME]);
        }
    }

    private function runLegacyBody(object $legacy): void
    {
        self::$runningLegacyBody = true;
        try {
            // The chain's closures read $db from the including scope and from globals.
            (static function (): void {
                $db = $GLOBALS['db'];
                require dirname(__DIR__, 2).'/debug/db_updates.php';
            })();
        } finally {
            self::$runningLegacyBody = false;
        }
        if (!$legacy->isTransactionIdle()) {
            throw new \RuntimeException('The legacy schema chain left a transaction open on the legacy session');
        }
    }

    private function metadataTableExists(): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'synth_meta' AND c.relname = 'doctrine_migration_versions' AND c.relkind IN ('r', 'p')"
        ) === 1;
    }

    // The canonical metadata for the live ledger: present, nonempty and fully known to this tree, its versions in byte
    // order (the database's ORDER BY follows its collation, which can differ between installs).
    /** @return array{format:string,schema:string,migration_versions:list<string>} */
    private function snapshotMetadata(): array
    {
        $versions = $this->refuseUnknownRecords($this->factory());
        if ($versions === []) {
            throw new \RuntimeException('This database records no schema migrations; snapshots need the migration ledger');
        }
        sort($versions, SORT_STRING);
        return ['format' => self::SNAPSHOT_FORMAT, 'schema' => self::SNAPSHOT_SCHEMA, 'migration_versions' => $versions];
    }

    // Snapshot metadata as an object: only its top-level keys are put in byte order. Values, including the ordered
    // version list, are left untouched, so the strict comparison still checks every type, value and list position.
    private static function canonicalObject(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    // The owner lock around one DBAL transaction: committed before the lock is released, rolled back on a throw.
    /** @template T @param \Closure():T $work @return T */
    private function underSnapshotTransaction(\Closure $work): mixed
    {
        return $this->underLock(fn (): mixed => $this->connection->transactional($work));
    }

    /** @template T @param callable():T $work @return T */
    private function underLock(callable $work): mixed
    {
        // Nesting would re-enter the session lock and release it with the inner entry, before the outer one ends.
        if ($this->locked) {
            throw new \RuntimeException('SYNTH schema migration entries cannot be nested');
        }
        $this->acquireLock();
        try {
            return $work();
        } finally {
            $this->releaseLock();
        }
    }

    // Session-level, not transaction-level: the lock must span the legacy chain, which runs outside any Doctrine
    // transaction on the legacy session. Polling keeps the wait bounded without a server-side lock_timeout.
    private function acquireLock(): void
    {
        $deadline = microtime(true) + $this->lockWaitSeconds;
        while (true) {
            if ((int) $this->connection->fetchOne(
                'SELECT CASE WHEN pg_try_advisory_lock(hashtextextended(?, 0)) THEN 1 ELSE 0 END', [self::LOCK_NAME]) === 1) {
                $this->locked = true;
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Another SYNTH schema migration is still running; retry after it finishes');
            }
            usleep(250000);
        }
    }

    private function releaseLock(): void
    {
        if (!$this->locked) return;
        $this->locked = false;
        try {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [self::LOCK_NAME]);
        } catch (\Throwable) {
            // Closing the session releases a session lock; close() always follows.
        }
    }
}
