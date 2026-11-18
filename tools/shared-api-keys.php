<?php

// Read-only status of SYNTH's global API key setup (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"). It changes nothing and prints
// no key or connection value.
//
//   php tools/shared-api-keys.php status
//
// Reports whether the shared store is configured and usable, whether the vendored shared-migration copies still match the pinned
// ChimServer hashes, which shared migrations the store records, and whether DwemerDistro's registry lists SYNTH (so CHIM checks
// SYNTH connectors before deleting a shared key). Exit codes: 0 reported, 3 usage.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
if (($argv[1] ?? '') !== 'status' || $argc > 2) {
    fwrite(STDERR, "Usage: php tools/shared-api-keys.php status\n");
    exit(3);
}
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Doctrine dependencies are missing; run composer install --no-dev in the server root.\n");
    exit(3);
}
require_once $autoload;
require_once $root . '/core/dbmanager/SharedApiKeyStore.php';
require_once $root . '/core/dbmanager/dao/ApiBadgeDao.php';

$pins = json_decode((string) file_get_contents($root . '/core/dbmanager/shared-schema-manifest.json'), true, 8, JSON_THROW_ON_ERROR);
$copies = 'match';
foreach ($pins['shared_migrations'] as $file => $hash) {
    $bytes = @file_get_contents($root . '/core/dbmanager/shared-migrations/' . $file);
    if ($bytes === false || hash('sha256', str_replace("\r\n", "\n", $bytes)) !== $hash) {
        $copies = 'stale';
    }
}
$store = Synth\Core\SharedApiKeyStore::fromEnvironment();
$reason = $store === null ? 'not_configured' : $store->unavailableReason();
echo json_encode([
    'store' => $reason ?? 'ok',
    'shared_migration_copies' => $copies,
    'chimserver_commit' => $pins['chimserver_commit'] ?? '',
    'shared_migrations_recorded' => $reason === null ? $store->recordedMigrations() : null,
    'distro_registers_synth' => Synth\Core\DbManager\Dao\ApiBadgeDao::distroRegistered(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
