<?php

/**
 * Provides the local PostgreSQL connection and the few values needed before the database can be read.
 *
 * Copy this file to config/config.inc.php and replace the sample values locally. Do not commit real credentials.
 * Every other setting lives in the database (core_config_setting) and is edited in the dashboard or through
 * /api/v1/edit/config. An installation that still has conf/conf.php keeps working: see ConfigFile.
 */
declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'port' => 5432,
    'database' => 'synth',
    'username' => 'dwemer',
    'password' => 'dwemer',
    // Seconds to wait for the database before giving up.
    'connect_timeout' => 90,
    // The game this server answers for.
    'game_id' => 'fo4',
    // Extra host names the dashboard may be opened on, comma separated. Empty means local access only.
    'allowed_hosts' => '',
];
