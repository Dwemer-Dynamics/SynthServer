<?php
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "management_request_guard.php");

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
// conf/conf.php is an optional operator override of the tracked sample (and the environment for the database).
require_once($enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.sample.php");
if (is_file($enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php")) {
    require_once($enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
}
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");

header('Content-Type: application/json');
http_response_code(200);

$dbSettings = synthDbConnectionSettings('synth');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

require_once $enginePath . 'bootstrap.php';
try {
    // Explicit configuration: a connection failure throws instead of exiting with plaintext.
    $db = \Synth\Bootstrap::boot(rtrim($enginePath, DIRECTORY_SEPARATOR))->db;
} catch (Throwable $e) {
    echo json_encode([ 'ok'=>false ]);
    exit;
}
$catalog = \Synth\Core\DbManager\Dao\SchemaCatalogDao::current($db);

$eventlog = 0; $worldknowledge = 0; $last = 0; $fallout = '';
// Use fast estimates for counts where possible
// Each read fails on its own, as the separate queries did: the value stays 0.
try { $eventlog = (int)($catalog->estimatedRows('public', 'core_context_event') ?? 0); } catch (Throwable $e) { }
$hasO = false;
try { $hasO = $catalog->tableExists('worldknowledge', [$schema]); } catch (Throwable $e) { }
if ($hasO) {
    try { $worldknowledge = (int)($catalog->estimatedRows($schema, 'worldknowledge') ?? 0); } catch (Throwable $e) { }
}
try { $last = (int)(\Synth\Core\DbManager\Dao\ContextEventDao::current($db)->maxGamets() ?? 0); } catch (Throwable $e) { }
if ($last > 0) { $fallout = convert_gamets2fallout_long_date($last); }

echo json_encode([
    'ok' => true,
    'eventlog' => $eventlog,
    'worldknowledge' => $worldknowledge,
    'last_gamets' => $last,
    'last_fallout_date' => $fallout
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

?>


