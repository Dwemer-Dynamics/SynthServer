<?php

// Explicit shared API key operations (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"). Nothing here runs on a web request,
// from bootstrap or from a migration.
//
//   php tools/shared-api-keys.php status           read-only: store, binding column, shared-migration copies, counts
//   php tools/shared-api-keys.php import           read-only plan: what an import would adopt, create or leave local
//   php tools/shared-api-keys.php import --apply   bind local badges to shared keys (never deletes or rewrites a local row)
//
// SYNTH uses SYNTH_DB_* (environment, then conf.php, as the runtime does) and the shared store uses CHIM_SHARED_DB_* (the
// shared runtime role; never the migration owner). The import crosses two databases, so it uses no cross-database
// transaction: each key is adopted or created in one short shared transaction under the catalog lock, then bound in one
// short SYNTH statement while the shared row is held FOR SHARE (ChimServer's bind protocol). A rerun resumes: bound rows
// are skipped and an unbound row adopts the key an interrupted run created, so no duplicate is added. The local api_key
// stays as the mirror. Output holds counts and ids only, never a key.
// Exit codes: 0 done, 1 some rows could not be bound, 2 configuration or connection, 3 usage.
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Synth\Core\SharedApiKeyException;
use Synth\Core\SharedApiKeyStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$emit = static function (array $report, int $status, $stream = STDOUT): never {
    fwrite($stream, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($status);
};

try {
    $mode = $argv[1] ?? '';
    $apply = ($argv[2] ?? '') === '--apply';
    if (!in_array($mode, ['status', 'import'], true) || $argc > 3 || ($argc === 3 && !$apply)
        || ($mode === 'status' && $argc > 2)) {
        $emit(['status' => 'error', 'message' => 'Usage: php tools/shared-api-keys.php status | import [--apply]'], 3, STDERR);
    }
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $emit(['status' => 'error', 'message' => 'Doctrine dependencies are missing; run composer install --no-dev in the server root.'], 2, STDERR);
    }
    require_once $autoload;
    require_once $root . '/core/dbmanager/SharedApiKeyStore.php';
    require_once $root . '/lib/runtime_bootstrap.php';
    require_once $root . '/lib/db_connection_settings.php';
    foreach (synthRuntimeReadConfiguration($root) as $name => $value) {
        if (str_starts_with($name, 'SYNTH_DB_') && is_scalar($value)) {
            $GLOBALS[$name] = (string) $value;
        }
    }
    if (getenv('PGCONNECT_TIMEOUT') === false) {
        putenv('PGCONNECT_TIMEOUT=10');
    }

    // The vendored shared migrations must be the pinned ChimServer bytes.
    $pins = json_decode((string) file_get_contents($root . '/core/dbmanager/shared-schema-manifest.json'), true, 8, JSON_THROW_ON_ERROR);
    $copies = 'match';
    foreach ($pins['shared_migrations'] as $file => $hash) {
        $bytes = @file_get_contents($root . '/core/dbmanager/shared-migrations/' . $file);
        if ($bytes === false || hash('sha256', str_replace("\r\n", "\n", $bytes)) !== $hash) $copies = 'stale';
    }

    $store = SharedApiKeyStore::fromEnvironment();
    $settings = synthDbConnectionSettings('synth');
    try {
        $synth = DriverManager::getConnection([
            'driver' => 'pgsql', 'host' => $settings['host'], 'port' => (int) $settings['port'], 'dbname' => $settings['dbname'],
            'user' => $settings['username'], 'password' => $settings['password'], 'application_name' => 'synth-shared-api-keys',
        ]);
        $synth->executeQuery('SELECT 1');
    } catch (Throwable) {
        $emit(['status' => 'error', 'message' => 'The SYNTH database connection could not be opened.'], 2, STDERR);
    }
    $column = (int) $synth->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'synth'
        AND table_name = 'core_api_badge' AND column_name = 'shared_key_id'") === 1;
    $ready = $store !== null && $store->ready();
    $total = (int) $synth->fetchOne('SELECT count(*) FROM synth.core_api_badge');
    $bound = $column ? (int) $synth->fetchOne('SELECT count(*) FROM synth.core_api_badge WHERE shared_key_id IS NOT NULL') : 0;
    $report = [
        'store_configured' => $store !== null, 'store_ready' => $ready, 'binding_column' => $column,
        'shared_migration_copies' => $copies, 'shared_migrations_recorded' => $ready ? $store->recordedMigrations() : null,
        'badges' => $total, 'bound' => $bound,
    ];
    if ($mode === 'status') {
        $emit(['status' => 'ok'] + $report, 0);
    }
    if (!$ready || !$column || $copies !== 'match') {
        $emit(['status' => 'refused', 'message' => 'The import needs the configured shared store with its api_keys table, the shared_key_id column and unmodified shared migration copies.']
            + $report, 2, STDERR);
    }

    $outcome = ['bound_already' => 0, 'skip_empty' => 0, 'skip_unmapped_label' => 0, 'skip_invalid_value' => 0, 'skip_limit' => 0,
        'adopted' => 0, 'created' => 0, 'bound' => 0, 'would_adopt' => 0, 'would_create' => 0, 'changed_meanwhile' => 0, 'revoked' => 0];
    $outcome['bound_already'] = $bound;
    $active = $apply ? 0 : $store->activeCount();
    $planned = [];
    $exit = 0;
    foreach ($synth->fetchAllAssociative('SELECT id, label, api_key FROM synth.core_api_badge WHERE shared_key_id IS NULL ORDER BY id') as $row) {
        $key = (string) $row['api_key'];
        $provider = SharedApiKeyStore::providerForLabel((string) $row['label']);
        if ($key === '') { $outcome['skip_empty']++; continue; }
        if ($provider === null) { $outcome['skip_unmapped_label']++; continue; }
        if (!SharedApiKeyStore::secretValid($key)) { $outcome['skip_invalid_value']++; continue; }
        if (!$apply) {
            $existing = $store->findActive($provider, $key) ?? ($planned[$provider . "\0" . $key] ?? null);
            if ($existing !== null) { $outcome['would_adopt']++; continue; }
            if ($active >= SharedApiKeyStore::ACTIVE_MAX) { $outcome['skip_limit']++; continue; }
            $planned[$provider . "\0" . $key] = 'planned';
            $active++;
            $outcome['would_create']++;
            continue;
        }
        $result = $store->adoptOrCreate($provider, (string) $row['label'], $key);
        if ($result['key_id'] === null) { $outcome['skip_limit']++; $exit = 1; continue; }
        $outcome[$result['outcome']]++;
        $bind = $store->withShareLock($result['key_id'], static fn() => $synth->executeStatement(
            'UPDATE synth.core_api_badge SET shared_key_id = ?::uuid WHERE id = ? AND shared_key_id IS NULL AND api_key = ?',
            [$result['key_id'], (int) $row['id'], $key]));
        if ($bind['outcome'] === 'revoked') { $outcome['revoked']++; $exit = 1; }
        elseif ($bind['outcome'] === 'locked' && $bind['result'] === 1) $outcome['bound']++;
        else { $outcome['changed_meanwhile']++; $exit = 1; }
    }
    $emit(['status' => $apply ? ($exit === 0 ? 'applied' : 'applied_with_skips') : 'plan', 'outcome' => $outcome] + $report, $exit);
} catch (SharedApiKeyException $error) {
    $emit(['status' => 'error', 'message' => $error->getMessage()], 2, STDERR);
} catch (Throwable) {
    // Anything else may carry connection details, SQL or a key; never print it.
    $emit(['status' => 'error', 'message' => 'Shared API key tool failed unexpectedly; no secret was printed.'], 2, STDERR);
}
