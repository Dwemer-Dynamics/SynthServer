<?php

$root = dirname(__DIR__);

require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php";
require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "synth_runtime.php";

try {
    // One bootstrap reads conf.php once and connects through the exception path: the legacy constructor's plaintext
    // exit would end this command with status 0 when PostgreSQL is unreachable. UTF8 is checked before migrations.
    synthRuntimeBootstrap($root, [
        'throw_db_connection_errors' => true,
        'load_general_settings' => false,
        'load_stt_connector' => false,
    ]);
    if (!synthRuntimeDatabaseEncodingIsSupported()) {
        throw new \RuntimeException(synthRuntimeDatabaseEncodingError());
    }
    synthRuntimeApplyBootstrapOptions($root, [
        'run_db_updates' => true,
        'load_general_settings' => true,
        'load_stt_connector' => true,
        'load_tts_connector' => false,
        'load_player_name' => true,
        'load_narrator' => true,
    ]);
} catch (\Throwable $error) {
    // Message only: a default CLI trace can print call arguments.
    fwrite(STDERR, "SYNTH database bootstrap failed: " . $error->getMessage() . "\n");
    exit(1);
}

$db = $GLOBALS["db"] ?? null;
if (!$db) {
    fwrite(STDERR, "SYNTH database bootstrap failed: no database connection was created.\n");
    exit(1);
}

if (!synthRuntimeDatabaseEncodingIsSupported()) {
    fwrite(STDERR, synthRuntimeDatabaseEncodingError() . "\n");
    exit(1);
}

if (synthRuntimeNeedsDbUpdates()) {
    fwrite(STDERR, "SYNTH database bootstrap failed: required schema objects or migration versions are still missing.\n");
    exit(1);
}

$versionRows = $db->fetchAll("SELECT COUNT(*) AS count FROM public.core_catalog_version");
$seedRows = $db->fetchAll("
    SELECT 'core_action' AS seed_name, COUNT(*) AS row_count, COUNT(DISTINCT code_name) AS unique_count
      FROM synth.core_action
    UNION ALL
    SELECT 'prompts', COUNT(*), COUNT(DISTINCT prompt_key)
      FROM public.core_prompt
    UNION ALL
    SELECT 'bio_templates', COUNT(*), COUNT(DISTINCT lower(npc_name))
      FROM synth.bio_templates
    UNION ALL
    SELECT 'descriptions', COUNT(*), COUNT(DISTINCT lower(plugin || ':' || baseid))
      FROM synth.descriptions
    UNION ALL
    SELECT 'general_settings', COUNT(*), COUNT(DISTINCT id)
      FROM synth.general_settings
");

$seedMinimums = [
    'core_action' => 26,
    'prompts' => 47,
    // FO4 actor and item records arrive from the runtime; zero is a valid clean baseline.
    'bio_templates' => 0,
    'descriptions' => 0,
    'general_settings' => count(array_unique(synthGetManagedGeneralSettingIds())),
];
$seedCounts = [];
foreach ($seedRows as $seedRow) {
    $seedName = strval($seedRow['seed_name'] ?? '');
    $rowCount = intval($seedRow['row_count'] ?? 0);
    $uniqueCount = intval($seedRow['unique_count'] ?? 0);
    if ($seedName === '' || $rowCount !== $uniqueCount || $rowCount < intval($seedMinimums[$seedName] ?? 1)) {
        fwrite(STDERR, "SYNTH database bootstrap failed: seed verification failed for {$seedName}.\n");
        exit(1);
    }
    $seedCounts[$seedName] = $rowCount;
}

foreach ($seedMinimums as $seedName => $minimum) {
    if (!isset($seedCounts[$seedName])) {
        fwrite(STDERR, "SYNTH database bootstrap failed: seed verification did not return {$seedName}.\n");
        exit(1);
    }
}

$versionCount = intval($versionRows[0]["count"] ?? 0);

// The owner records the frozen legacy baseline before any later migration; a bootstrap without it is incomplete.
$migrationRows = $db->fetchAll("SELECT version FROM synth_meta.doctrine_migration_versions ORDER BY version");
$migrationVersions = array_map(static fn(array $row): string => strval($row['version'] ?? ''), is_array($migrationRows) ? $migrationRows : []);
if (!in_array('Synth\\Migrations\\Version20261117000000', $migrationVersions, true)) {
    fwrite(STDERR, "SYNTH database bootstrap failed: the legacy schema baseline is not recorded.\n");
    exit(1);
}

echo "SYNTH database bootstrap complete.\n";
echo "core_catalog_version rows: {$versionCount}\n";
echo "schema verification: complete\n";
echo "schema migrations recorded: " . count($migrationVersions) . " (latest " . end($migrationVersions) . ")\n";
foreach ($seedCounts as $seedName => $seedCount) {
    echo "{$seedName} rows: {$seedCount}\n";
}
