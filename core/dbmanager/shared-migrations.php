<?php

// Doctrine Migrations configuration for the shared dwemer database, which the CHIM 4 server owns. These four migrations are
// byte-identical copies of ChimServer's Config/DBManager/SharedMigrations (804e501) and keep its namespace and version
// table on purpose: the version names recorded in dwemer_shared_migration_versions are fully qualified class names, so a
// SYNTH-named copy would record a second, parallel history. Whichever product runs first creates the table; the other
// finds every version recorded and does nothing. Never edit them here; a new shared migration is made in ChimServer and
// copied with its hash added to docs/SCHEMA-MIGRATIONS.md.
// vendor/bin/doctrine-migrations migrate --configuration=core/dbmanager/shared-migrations.php \
//     --db-configuration=core/dbmanager/shared-migrations-db.php --no-interaction

declare(strict_types=1);

return [
    'table_storage' => [
        'table_name' => 'dwemer_shared_migration_versions',
    ],
    'migrations_paths' => [
        'ChimServer\SharedMigrations' => __DIR__ . '/shared-migrations',
    ],
    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => true,
    'organize_migrations' => 'none',
];
