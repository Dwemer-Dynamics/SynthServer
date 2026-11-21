<?php

// Read-only schema drift check: compares a live database with the frozen schema manifest (docs/SCHEMA-MIGRATIONS.md,
// "Drift check"). It never migrates, never takes the migration lock and never writes. Output is JSON on stdout.
//
//   php tools/schema-check.php             validate the manifest, then compare the live database
//   php tools/schema-check.php --offline   validate the manifest against the tree only (no database, no vendor/)
//   php tools/schema-check.php --hashes    print the manifest entries the tree currently implies (for review)
//
// Exit codes: 0 match, 1 drift, 2 inspection error (configuration, connection, permissions), 3 stale manifest or
// usage error. The database connection uses SYNTH_DB_* from the environment, then conf.php, as the runtime does.
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Synth\Core\Doctrine\SchemaCheck;
use Synth\Core\Doctrine\SchemaCheckException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/core/dbmanager/SchemaCheck.php';

$emit = static function (array $report, int $status, $stream = STDOUT): never {
    fwrite($stream, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($status);
};

try {
    $mode = $argv[1] ?? 'check';
    if ($argc > 2 || !in_array($mode, ['check', '--offline', '--hashes'], true)) {
        throw new SchemaCheckException('Usage: php tools/schema-check.php [--offline|--hashes]', 3);
    }
    $check = SchemaCheck::forTree();
    if ($mode === '--hashes') {
        $emit(['migrations' => $check->currentEntries()], 0);
    }
    // The manifest must match the tree before any connection is made.
    $manifest = $check->loadManifest();
    $expected = $check->expectedStructure($manifest);
    if ($mode === '--offline') {
        $emit(['status' => 'manifest_current', 'migrations' => array_keys($manifest['migrations']),
            'structure_entries' => count($expected)], 0);
    }

    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new SchemaCheckException('Doctrine dependencies are missing; run composer install --no-dev in the server root.', 2);
    }
    require_once $autoload;
    require_once $root . '/lib/runtime_bootstrap.php';
    require_once $root . '/lib/db_connection_settings.php';
    // Environment wins over conf.php, as in the runtime. conf.php is read in an isolated scope and never written.
    foreach (synthRuntimeReadConfiguration($root) as $name => $value) {
        if (str_starts_with($name, 'SYNTH_DB_') && is_scalar($value)) {
            \Synth\Bootstrap::set($name, (string) $value);
        }
    }
    $settings = synthDbConnectionSettings('synth');
    if (getenv('PGCONNECT_TIMEOUT') === false) {
        putenv('PGCONNECT_TIMEOUT=10');
    }
    try {
        $connection = DriverManager::getConnection([
            'driver' => 'pgsql',
            'host' => $settings['host'],
            'port' => (int) $settings['port'],
            'dbname' => $settings['dbname'],
            'user' => $settings['username'],
            'password' => $settings['password'],
            'application_name' => 'synth-schema-check',
        ]);
        $connection->executeQuery('SELECT 1');
    } catch (Throwable) {
        throw new SchemaCheckException('The database connection could not be opened.', 2);
    }
    $live = $check->inspect($connection);
    $connection->close();

    $structure = SchemaCheck::compare($expected, $live['structure']);
    $ledger = SchemaCheck::compareLedger($manifest, $live['versions']);
    $drift = $structure['total'] > 0 || $ledger['status'] !== 'current';
    $emit([
        'status' => $drift ? 'drift' : 'match',
        'server_major' => $live['server_major'],
        'manifest' => ['migrations' => array_keys($manifest['migrations']), 'structure_entries' => count($expected)],
        'structure' => ['differences' => $structure['total'], 'unmanifested_tables' => $structure['unmanifested_tables'],
            'changes' => $structure['changes']],
        'ledger' => $ledger,
        'limits' => SchemaCheck::LIMITS,
    ], $drift ? 1 : 0);
} catch (SchemaCheckException $error) {
    $emit(['status' => 'error', 'message' => $error->getMessage()], $error->getCode() === 2 ? 2 : 3, STDERR);
} catch (Throwable) {
    // Anything else may carry connection details or SQL; never print it.
    $emit(['status' => 'error', 'message' => 'Schema check failed unexpectedly; no database change was made.'], 2, STDERR);
}
