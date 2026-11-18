<?php

// DBAL connection for the shared dwemer database migrations, using the shared schema-owner role (never a runtime role).
// Same variables as ChimServer's Config/DBManager/shared-migrations-db.php: CHIM_SHARED_DB_*. No defaults: the shared
// database is always named explicitly, so a migration can never land in the SYNTH database by accident.

declare(strict_types=1);

$env = static function (string $name): string {
    $value = getenv($name);
    return is_string($value) ? $value : '';
};

foreach (['CHIM_SHARED_DB_HOST', 'CHIM_SHARED_DB_PORT', 'CHIM_SHARED_DB_NAME', 'CHIM_SHARED_DB_MIGRATION_USER'] as $required) {
    if ($env($required) === '') {
        throw new RuntimeException($required . ' must be set for the shared database migrations');
    }
}

$port = $env('CHIM_SHARED_DB_PORT');
if (preg_match('/^[1-9][0-9]{0,4}$/D', $port) !== 1 || (int) $port > 65535) {
    throw new RuntimeException('CHIM_SHARED_DB_PORT is invalid for the shared database migrations');
}

return [
    'driver' => 'pgsql',
    'host' => $env('CHIM_SHARED_DB_HOST'),
    'port' => (int) $port,
    'dbname' => $env('CHIM_SHARED_DB_NAME'),
    'user' => $env('CHIM_SHARED_DB_MIGRATION_USER'),
    'password' => $env('CHIM_SHARED_DB_MIGRATION_PASSWORD'),
    'application_name' => 'dwemer-shared-migrations',
];
