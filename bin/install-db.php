<?php

// Destructive database rebuild. Operator command line only: a web request is refused before configuration,
// credentials or the database are loaded.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Database reinstall is not available from the web dashboard.\n";
    exit;
}

$enginePath = dirname(__DIR__).DIRECTORY_SEPARATOR;

require_once($enginePath."lib".DIRECTORY_SEPARATOR."autoload.php");

require_once($enginePath."conf".DIRECTORY_SEPARATOR."conf.php");
require_once($enginePath."lib".DIRECTORY_SEPARATOR."db_connection_settings.php");

$dbSettings = synthDbConnectionSettings('synth');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

$conn = pg_connect(synthPgConnectionString($dbSettings));

if (!$conn) {
    echo "Failed to connect to database.\n";
    die();
}

$encodingResult = pg_query($conn, 'SHOW server_encoding');
$encodingRow = $encodingResult ? pg_fetch_assoc($encodingResult) : [];
$databaseEncoding = strtoupper(trim(strval($encodingRow['server_encoding'] ?? '')));
if ($databaseEncoding !== 'UTF8') {
    echo "Database rebuild stopped: SYNTH requires UTF8, but '{$dbname}' uses {$databaseEncoding}.\n";
    echo "Run sudo bash /var/www/html/Synthserver/tools/migrate-synth-db-utf8-wsl.sh first.\n";
    pg_close($conn);
    exit(1);
}

// A reinstall is destructive: remove the active, metadata, plugin, snapshot and public schemas.
$snapshotSchemas = pg_query($conn, "
    SELECT schema_name
      FROM information_schema.schemata
     WHERE schema_name LIKE 'synth\_profile\_%' ESCAPE '\\'
");
if ($snapshotSchemas) {
    while ($snapshotSchema = pg_fetch_assoc($snapshotSchemas)) {
        $snapshotSchemaName = strval($snapshotSchema['schema_name'] ?? '');
        if ($snapshotSchemaName !== '') {
            $Q[] = "DROP SCHEMA IF EXISTS " . pg_escape_identifier($conn, $snapshotSchemaName) . " CASCADE";
        }
    }
}

// The baseline migration installs the whole schema into an empty database, so the rebuild leaves nothing behind: every
// application schema, and everything in public (the core_* tables, functions and extensions), is dropped, then the two
// extensions the baseline needs are created again in public.
$Q[]="DROP SCHEMA IF EXISTS $schema CASCADE";
$Q[]="DROP SCHEMA IF EXISTS plugins CASCADE";
$Q[]="DROP SCHEMA IF EXISTS synth_meta CASCADE";
$Q[]="DROP SCHEMA IF EXISTS public CASCADE";
$Q[]="CREATE SCHEMA public";
$Q[]="CREATE EXTENSION IF NOT EXISTS vector";
$Q[]="CREATE EXTENSION IF NOT EXISTS pg_trgm";

foreach ($Q as $QS) {
  $r = pg_query($conn, $QS);
  if (!$r) {
    echo pg_last_error($conn);
    die();
  } else {
    echo "$QS ok<br/>";
  }
  
}

pg_close($conn);

putenv("SYNTH_DB_HOST={$host}");
putenv("SYNTH_DB_PORT={$port}");
putenv("SYNTH_DB_NAME={$dbname}");
putenv("SYNTH_DB_USER={$username}");
putenv("SYNTH_DB_PASSWORD={$password}");

$bootstrapScript = $enginePath . 'tools' . DIRECTORY_SEPARATOR . 'bootstrap-database.php';
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$bootstrapCommand = escapeshellarg($phpBinary) . ' ' . escapeshellarg($bootstrapScript) . ' 2>&1';
$bootstrapOutput = [];
$bootstrapReturnVar = 0;
exec($bootstrapCommand, $bootstrapOutput, $bootstrapReturnVar);

if ($bootstrapReturnVar !== 0) {
    echo "Failed to apply or verify SYNTH database migrations.\n";
    echo implode("\n", $bootstrapOutput) . "\n";
    exit;
}

echo "SYNTH database rebuilt and verified successfully.\n";
echo implode("\n", $bootstrapOutput) . "\n";



?>
