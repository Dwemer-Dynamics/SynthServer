<?php

// Doctrine Migrations configuration for the one versioned schema owner (SchemaMigrations.php). The version table
// lives in synth_meta, the control-plane schema: playthrough snapshots clone application schemas, and a cloned or
// restored copy of the version table could disagree with the schema it describes.
declare(strict_types=1);

return [
    'migrations_paths' => [
        'Synth\Migrations' => __DIR__ . '/migrations',
    ],
    'table_storage' => [
        'table_name' => 'synth_meta.doctrine_migration_versions',
    ],
    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => true,
];
